<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Domain\Article\ArticleDetail;
use App\Domain\Article\ArticleSummary;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\InMemoryArticleRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Veřejná část (plán 004, AC 11-18) přes skutečný Kernel a repozitář článků v paměti. */
final class PublicPagesTest extends TestCase
{
    /** @var array<mixed> */
    private array $serverBackup = [];
    /** @var array<mixed> */
    private array $getBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
    }

    private static function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Prague'));
    }

    private function get(InMemoryArticleRepository $repository, string $uri, string $method = 'GET'): Response
    {
        $container = TestContainer::create(
            new ArraySession(),
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
            $repository,
        );

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        $_GET = [];
        parse_str($query, $_GET);

        return $container->get(Kernel::class)->handle(Request::fromGlobals());
    }

    /** 12 publikovaných článků; nejnovější je `ukazka-markdownu` (2026-09-12), druhý `sablony-a-escapovani`. */
    private function repositoryWithTwelve(): InMemoryArticleRepository
    {
        $repository = new InMemoryArticleRepository();
        for ($day = 12; $day >= 1; --$day) {
            $title = match ($day) {
                12 => 'Ukázka Markdownu',
                11 => 'Šablony & escapování: <script> se nespustí',
                default => sprintf('Článek %d', $day),
            };
            $slug = match ($day) {
                12 => 'ukazka-markdownu',
                11 => 'sablony-a-escapovani',
                default => sprintf('clanek-%d', $day),
            };
            $repository->summaries[] = new ArticleSummary(
                title: $title,
                slug: $slug,
                excerpt: $day === 11 ? '<b>perex</b>' : sprintf('Perex článku %d.', $day),
                publishedAt: self::date(sprintf('2026-09-%02d 08:00:00', $day)),
                categoryName: $day === 12 ? 'Technologie' : 'Zprávy',
            );
        }

        return $repository;
    }

    private function repositoryWithDetail(string $body = "## Podnadpis\n\nText s **tučným** a `kódem`.\n\n```\n<b>x</b>\n```"): InMemoryArticleRepository
    {
        $repository = $this->repositoryWithTwelve();
        $repository->details['ukazka-markdownu'] = new ArticleDetail(
            title: 'Ukázka Markdownu',
            slug: 'ukazka-markdownu',
            excerpt: 'Perex ukázky.',
            publishedAt: self::date('2026-09-12 08:00:00'),
            categoryName: 'Technologie',
            body: $body,
            tagNames: ['Bezpečnost', 'PHP'],
        );

        return $repository;
    }

    /** @return array<string, string> */
    private function headers(Response $response): array
    {
        $result = [];
        foreach ($response->headers as $name => $value) {
            $result[strtolower((string) $name)] = (string) $value;
        }

        return $result;
    }

    public function test_homepage_lists_ten_newest_articles_with_pagination_to_next(): void
    {
        $response = $this->get($this->repositoryWithTwelve(), '/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>Nejnovější články</h1>', $response->body);
        self::assertSame(10, preg_match_all('~<article[\s>]~', $response->body));
        self::assertStringContainsString('<h2><a href="/clanek/ukazka-markdownu">Ukázka Markdownu</a></h2>', $response->body);
        self::assertStringContainsString('<time datetime="2026-09-12T08:00:00+02:00">12. září 2026</time>', $response->body);
        self::assertStringContainsString('Technologie', $response->body);
        self::assertStringContainsString('Perex článku 10.', $response->body);
        self::assertStringNotContainsString('Perex článku 2.', $response->body);
        self::assertStringContainsString('<nav aria-label="Stránkování"', $response->body);
        self::assertStringContainsString('<a href="/?strana=2" rel="next">Starší články</a>', $response->body);
        self::assertStringContainsString('Strana 1 z 2', $response->body);
        self::assertStringNotContainsString('Novější články', $response->body);
        self::assertArrayNotHasKey('set-cookie', $this->headers($response));
    }

    public function test_second_page_lists_remaining_articles_with_canonical_link_to_first(): void
    {
        $response = $this->get($this->repositoryWithTwelve(), '/?strana=2');

        self::assertSame(200, $response->status);
        self::assertSame(2, preg_match_all('~<article[\s>]~', $response->body));
        self::assertStringContainsString('<a href="/" rel="prev">Novější články</a>', $response->body);
        self::assertStringContainsString('Strana 2 z 2', $response->body);
        self::assertStringNotContainsString('Starší články', $response->body);
        self::assertMatchesRegularExpression('~<title>[^<]*Strana 2~u', $response->body);
    }

    public function test_page_one_query_renders_same_as_homepage(): void
    {
        $repository = $this->repositoryWithTwelve();

        $plain = $this->get($repository, '/');
        $explicit = $this->get($repository, '/?strana=1');

        self::assertSame(200, $explicit->status);
        self::assertSame($plain->body, $explicit->body);
        self::assertStringNotContainsString('strana=1', $explicit->body);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPageQueries(): iterable
    {
        foreach (['3', '0', '-1', 'abc', '1.5', '01', '9999999', ' 1', '1 ', '2abc'] as $value) {
            yield 'strana=' . $value => [rawurlencode($value)];
        }
    }

    #[DataProvider('invalidPageQueries')]
    public function test_invalid_or_out_of_range_page_returns_404_not_500(string $encodedValue): void
    {
        $response = $this->get($this->repositoryWithTwelve(), '/?strana=' . $encodedValue);

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Stránka nenalezena', $response->body);
    }

    public function test_array_page_parameter_is_ignored_and_first_page_is_shown(): void
    {
        $repository = $this->repositoryWithTwelve();

        $response = $this->get($repository, '/?strana[]=2');

        self::assertSame(200, $response->status);
        self::assertSame($this->get($repository, '/')->body, $response->body);
    }

    public function test_empty_repository_shows_empty_state_without_pagination(): void
    {
        $response = $this->get(new InMemoryArticleRepository(), '/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Zatím tu nejsou žádné publikované články.', $response->body);
        self::assertStringNotContainsString('<nav aria-label="Stránkování"', $response->body);
        self::assertSame(0, preg_match_all('~<article[\s>]~', $response->body));
    }

    public function test_empty_repository_second_page_is_404(): void
    {
        self::assertSame(404, $this->get(new InMemoryArticleRepository(), '/?strana=2')->status);
    }

    public function test_homepage_escapes_title_and_excerpt(): void
    {
        $body = $this->get($this->repositoryWithTwelve(), '/')->body;

        self::assertStringContainsString('Šablony &amp; escapování: &lt;script&gt; se nespustí', $body);
        self::assertStringContainsString('&lt;b&gt;perex&lt;/b&gt;', $body);
        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('<b>perex', $body);
    }

    public function test_article_detail_renders_meta_markdown_tags_and_back_link(): void
    {
        $response = $this->get($this->repositoryWithDetail(), '/clanek/ukazka-markdownu');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<article', $response->body);
        self::assertStringContainsString('<h1>Ukázka Markdownu</h1>', $response->body);
        self::assertStringContainsString('<time datetime="2026-09-12T08:00:00+02:00">12. září 2026</time>', $response->body);
        self::assertStringContainsString('Rubrika: Technologie', $response->body);
        self::assertStringContainsString('Perex ukázky.', $response->body);
        self::assertStringContainsString('<div class="article-body">', $response->body);
        self::assertStringContainsString('<h2>Podnadpis</h2>', $response->body);
        self::assertStringContainsString('<strong>tučným</strong>', $response->body);
        self::assertStringContainsString('<pre><code>&lt;b&gt;x&lt;/b&gt;</code></pre>', $response->body);
        self::assertStringContainsString('<ul class="tag-list">', $response->body);
        self::assertMatchesRegularExpression('~<li>\s*Bezpečnost\s*</li>\s*<li>\s*PHP\s*</li>~u', $response->body);
        self::assertStringContainsString('Zpět na titulní stránku', $response->body);
        self::assertMatchesRegularExpression('~<title>Ukázka Markdownu~u', $response->body);
        self::assertArrayNotHasKey('set-cookie', $this->headers($response));
    }

    public function test_article_without_tags_has_no_tag_list(): void
    {
        $repository = $this->repositoryWithDetail();
        $detail = $repository->details['ukazka-markdownu'];
        $repository->details['ukazka-markdownu'] = new ArticleDetail(
            $detail->title,
            $detail->slug,
            $detail->excerpt,
            $detail->publishedAt,
            $detail->categoryName,
            $detail->body,
            [],
        );

        $body = $this->get($repository, '/clanek/ukazka-markdownu')->body;

        self::assertStringNotContainsString('tag-list', $body);
    }

    /** @return iterable<string, array{string}> */
    public static function notFoundArticleUris(): iterable
    {
        yield 'unknown' => ['/clanek/neexistuje'];
        yield 'draft' => ['/clanek/rozepsany-koncept'];
        yield 'archived' => ['/clanek/archivni-clanek'];
        yield 'scheduled' => ['/clanek/planovany-clanek'];
        yield 'uppercase' => ['/clanek/Ukazka-Markdownu'];
        yield 'trailing space' => ['/clanek/ukazka-markdownu%20'];
        yield 'diacritics' => ['/clanek/%C4%8Dl%C3%A1nek'];
        yield 'empty slug' => ['/clanek/'];
        yield 'nested' => ['/clanek/a/b'];
    }

    #[DataProvider('notFoundArticleUris')]
    public function test_unpublished_or_invalid_article_returns_same_404_page(string $uri): void
    {
        $repository = $this->repositoryWithDetail();
        $unknown = $this->get($repository, '/neexistuje');

        $response = $this->get($repository, $uri);

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Stránka nenalezena', $response->body);
        self::assertSame($unknown->body, $response->body);
    }

    public function test_post_to_article_returns_405_with_allow_get(): void
    {
        $response = $this->get($this->repositoryWithDetail(), '/clanek/ukazka-markdownu', 'POST');

        self::assertSame(405, $response->status);
        self::assertSame('GET', $this->headers($response)['allow'] ?? null);
    }

    public function test_article_body_is_sanitized_markdown_output(): void
    {
        $repository = $this->repositoryWithDetail('<script>alert(1)</script>');

        $body = $this->get($repository, '/clanek/ukazka-markdownu')->body;

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script>alert', $body);
    }

    public function test_article_with_javascript_link_renders_no_anchor_for_it(): void
    {
        $repository = $this->repositoryWithDetail('[zlo](javascript:alert(1))');

        $body = $this->get($repository, '/clanek/ukazka-markdownu')->body;

        self::assertStringNotContainsString('href="javascript:', $body);
    }

    public function test_article_metadata_is_escaped(): void
    {
        $repository = $this->repositoryWithDetail();
        $repository->details['ukazka-markdownu'] = new ArticleDetail(
            title: '<i>T</i> & "U"',
            slug: 'ukazka-markdownu',
            excerpt: '<u>p</u>',
            publishedAt: self::date('2026-09-12 08:00:00'),
            categoryName: '<s>R</s>',
            body: 'x',
            tagNames: ['<em>t</em>'],
        );

        $body = $this->get($repository, '/clanek/ukazka-markdownu')->body;

        self::assertStringNotContainsString('<i>T', $body);
        self::assertStringNotContainsString('<u>p', $body);
        self::assertStringNotContainsString('<s>R', $body);
        self::assertStringNotContainsString('<em>t', $body);
        self::assertStringContainsString('&lt;i&gt;T&lt;/i&gt; &amp; &quot;U&quot;', $body);
    }

    public function test_layout_has_skip_link_and_main_landmark(): void
    {
        $body = $this->get($this->repositoryWithTwelve(), '/')->body;

        self::assertStringContainsString('<a class="skip-link" href="#obsah">', $body);
        self::assertStringContainsString('<main id="obsah"', $body);
    }
}
