<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Ai\Examples\Example06WritingAssistant;
use App\Domain\Article\ArticleStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\User\Role;
use App\Domain\User\User;
use App\Http\Controller\Admin\AiController;
use App\Http\Controller\Admin\ArticleController;
use App\Http\Kernel;
use App\Http\Middleware\AdminAccessMiddleware;
use App\Http\Middleware\AiRateLimitMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Middleware\RoutingMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteMatch;
use App\Infrastructure\Config\AiRateLimitConfig;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\ArticleInputs;
use App\Tests\Unit\Support\InMemoryAiRateLimitHitRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\MutableClock;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plán 013, AC 10–18 a 20: rate limit AI tras přes skutečný Kernel (AiRateLimitMiddleware za CSRF a AdminAccess).
 * Admin id 7 přihlášený v ArraySession, platné CSRF, falešný klient (limit platí i pro něj), záznamy limitu
 * v InMemoryAiRateLimitHitRepository, čas 2026-10-09 12:00 (Europe/Prague) z MutableClock – okno se posouvá bez sleep.
 * Limity si každý test sníží přes AiRateLimitConfig::fromEnvironment().
 */
final class AdminAiRateLimitTest extends AdminAiM7TestCase
{
    private const string IP = '172.18.0.1';
    private const string LIMIT_TITLE = 'Příliš mnoho požadavků na AI';
    private const string DEMO_TOPIC = 'Jak Docker usnadňuje práci malé redakce';

    private InMemoryAiRateLimitHitRepository $hits;
    private InMemoryAuditLogRepository $audit;
    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hits = new InMemoryAiRateLimitHitRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-09 12:00:00', new \DateTimeZone('Europe/Prague')));
        $this->bootLimited();
    }

    /** Stejné zapojení jako AdminAiM7TestCase::boot(), navíc sdílené záznamy limitu, audit, posuvné hodiny a limity. */
    private function bootLimited(string $standard = '', string $heavy = ''): void
    {
        $this->container = TestContainer::create(
            $this->session,
            $this->users,
            $this->audit,
            articles: $this->articles,
            clock: $this->clock,
            adminArticles: $this->adminArticles,
            aiCalls: $this->aiCalls,
            rateLimitHits: $this->hits,
            rateLimitConfig: AiRateLimitConfig::fromEnvironment(array_filter(
                ['AI_LIMIT_POZADAVKU' => $standard, 'AI_LIMIT_NAROCNYCH' => $heavy],
                static fn(string $value): bool => $value !== '',
            )),
        );
        $this->kernel = $this->container->get(Kernel::class);
    }

    /**
     * Běžné AI trasy (kbelík `ai`) s tělem, které controller přijme.
     *
     * @return array<string, array{string, array<string, string>}>
     */
    private static function standardRoutes(): array
    {
        return [
            '01' => ['/admin/ai/01', ['article' => 'demo']],
            '02' => ['/admin/ai/02', ['article' => 'demo']],
            '03' => ['/admin/ai/03', ['article' => 'demo']],
            '04' => ['/admin/ai/04', ['article' => 'demo']],
            '05' => ['/admin/ai/05', ['article' => 'demo']],
            '06 proud' => ['/admin/ai/06/proud', ['action' => 'zkrat', 'text' => Example06WritingAssistant::DEMO_TEXT]],
            '07' => ['/admin/ai/07', ['question' => 'Co redakce píše o Dockeru?']],
            '08' => ['/admin/ai/08', ['question' => 'Jak spánek ovlivňuje paměť?']],
            '08 indexace' => ['/admin/ai/08/indexace', []],
        ];
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function standardRouteCases(): iterable
    {
        yield from self::standardRoutes();
    }

    /**
     * @return array<string, string>
     */
    private static function saveForm(): array
    {
        return [
            'title' => 'Upravený titulek od člověka',
            'excerpt' => 'Perex upravený člověkem, který návrh před uložením přečetl.',
            'body' => "## Proč Docker\n\nUpravený text.\n\n## Jak začít\n\nDalší odstavec.",
            'category_id' => '1',
        ];
    }

    private function propose(): void
    {
        $response = $this->post('/admin/ai/09', ['topic' => self::DEMO_TOPIC]);
        self::assertSame(303, $response->status, 'Návrh 09 se nepodařil: ' . self::text($response->body));
    }

    private static function assertHtmlLimitPage(Response $response, int $limit, int $window, int $retryAfter): void
    {
        self::assertSame(429, $response->status);
        self::assertSame((string) $retryAfter, $response->headers['Retry-After'] ?? null, 'Hlavička Retry-After (delta-seconds).');
        self::assertStringStartsWith('text/html', $response->headers['Content-Type'] ?? '');
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertNull($response->producer);
        $text = self::text($response->body);
        self::assertStringContainsString(self::LIMIT_TITLE, $text);
        self::assertStringContainsString(sprintf('nejvýše %d za %d s', $limit, $window), $text);
        self::assertStringContainsString(sprintf('Zkuste to znovu za %d s', $retryAfter), $text);
        self::assertStringContainsString('href="/admin/ai"', $response->body, 'Odkaz zpět na přehled AI.');
    }

    // ---------------------------------------------------------------- AC 10: HTML 429 + Retry-After

    public function test_third_post_over_limit_of_two_is_429_html_with_retry_after(): void
    {
        $this->bootLimited(standard: '2/60');
        $this->signIn();

        $first = $this->post('/admin/ai/01', ['article' => 'demo']);
        $second = $this->post('/admin/ai/01', ['article' => 'demo']);
        $third = $this->post('/admin/ai/01', ['article' => 'demo']);

        self::assertSame(303, $first->status);
        self::assertSame(303, $second->status);
        self::assertHtmlLimitPage($third, 2, 60, 60);
        self::assertCount(2, $this->aiCalls->calls, 'Falešný klient byl zavolán jen 2× – limit platí i pro něj.');
        self::assertCount(2, $this->hits->hits);
    }

    public function test_rejection_is_audited_with_user_ip_and_summary(): void
    {
        $this->bootLimited(standard: '2/60');
        $this->signIn();
        $this->post('/admin/ai/01', ['article' => 'demo']);
        $this->post('/admin/ai/01', ['article' => 'demo']);

        $this->post('/admin/ai/01', ['article' => 'demo']);

        $entries = $this->audit->byAction(AuditAction::AiRateLimited);
        self::assertCount(1, $entries);
        self::assertCount(1, $this->audit->entries, 'Povolené požadavky audit nezapisují.');
        self::assertSame(self::ADMIN_ID, $entries[0]->userId);
        self::assertSame(self::IP, $entries[0]->ipAddress);
        self::assertSame('Limit běžných AI požadavků 2 za 60 s: POST /admin/ai/01', $entries[0]->summary);
    }

    public function test_retry_after_counts_down_and_request_passes_after_window(): void
    {
        $this->bootLimited(standard: '1/60');
        $this->signIn();
        self::assertSame(303, $this->post('/admin/ai/01', ['article' => 'demo'])->status);
        $this->clock->advanceSeconds(45);

        self::assertHtmlLimitPage($this->post('/admin/ai/01', ['article' => 'demo']), 1, 60, 15);

        $this->clock->advanceSeconds(15);
        self::assertSame(303, $this->post('/admin/ai/01', ['article' => 'demo'])->status);
    }

    public function test_limit_is_per_user_not_per_session_or_ip(): void
    {
        $this->users->users[8] = new User(8, 'druhy@example.cz', 'Druhý admin', 'hash', Role::Admin);
        $this->bootLimited(standard: '1/60');
        $this->signIn();
        self::assertSame(303, $this->post('/admin/ai/01', ['article' => 'demo'])->status);
        self::assertSame(429, $this->post('/admin/ai/01', ['article' => 'demo'])->status);

        $this->session->set('user_id', 8);

        self::assertSame(303, $this->post('/admin/ai/01', ['article' => 'demo'])->status, 'Druhý admin ze stejné IP má vlastní kbelík.');
        self::assertSame([7, 8], array_column($this->hits->hits, 'userId'));
    }

    // ---------------------------------------------------------------- AC 11: 06 proud = JSON

    public function test_stream_over_limit_is_429_json_before_stream_starts(): void
    {
        $this->bootLimited(standard: '1/60');
        $this->signIn();
        $first = $this->post('/admin/ai/06/proud', ['action' => 'zkrat', 'text' => Example06WritingAssistant::DEMO_TEXT]);
        self::assertSame(200, $first->status);

        $second = $this->post('/admin/ai/06/proud', ['action' => 'zkrat', 'text' => Example06WritingAssistant::DEMO_TEXT]);

        self::assertSame(429, $second->status);
        self::assertStringStartsWith('application/json', $second->headers['Content-Type'] ?? '');
        self::assertSame('60', $second->headers['Retry-After'] ?? null);
        self::assertNull($second->producer, 'Proud SSE nezačne.');
        self::assertSame(
            '{"error":"Příliš mnoho požadavků na AI: nejvýše 1 za 60 s. Zkuste to znovu za 60 s."}',
            $second->body,
        );
        self::assertCount(1, $this->hits->hits);
    }

    // ---------------------------------------------------------------- AC 12: běžný kbelík

    /** @param array<string, string> $body */
    #[DataProvider('standardRouteCases')]
    public function test_every_standard_route_shares_one_bucket(string $path, array $body): void
    {
        $this->bootLimited(standard: '1/60');
        $this->signIn();

        $first = $this->post($path, $body);

        self::assertNotSame(429, $first->status, 'První požadavek ' . $path . ' se do limitu vejde.');
        self::assertSame([['userId' => self::ADMIN_ID, 'bucket' => 'ai']], array_map(
            static fn(array $hit): array => ['userId' => $hit['userId'], 'bucket' => $hit['bucket']],
            $this->hits->hits,
        ));
        foreach (self::standardRoutes() as $name => [$otherPath, $otherBody]) {
            self::assertSame(429, $this->post($otherPath, $otherBody)->status, sprintf('Po %s má %s (%s) vrátit 429.', $path, $otherPath, $name));
        }
        self::assertCount(1, $this->hits->hits, 'Odmítnuté požadavky se nezapisují.');
        self::assertCount(\count(self::standardRoutes()), $this->audit->byAction(AuditAction::AiRateLimited));
    }

    // ---------------------------------------------------------------- AC 13: náročný kbelík (09)

    public function test_second_ai_editor_draft_over_heavy_limit_is_429_and_standard_bucket_still_works(): void
    {
        $this->bootLimited(heavy: '1/600');
        $this->signIn();
        $this->propose();

        $second = $this->post('/admin/ai/09', ['topic' => self::DEMO_TOPIC]);

        self::assertHtmlLimitPage($second, 1, 600, 600);
        self::assertSame(
            'Limit náročných AI požadavků (AI redaktor) 1 za 600 s: POST /admin/ai/09',
            $this->audit->byAction(AuditAction::AiRateLimited)[0]->summary ?? null,
        );
        self::assertSame(303, $this->post('/admin/ai/07', ['question' => 'Co redakce píše o Dockeru?'])->status, 'Jiný kbelík.');
        self::assertSame(['ai_heavy', 'ai'], array_column($this->hits->hits, 'bucket'));
    }

    // ---------------------------------------------------------------- AC 14: uložení a zahození bez limitu

    private function exhaustBothBucketsWithProposal(): void
    {
        $this->bootLimited(standard: '1/60', heavy: '1/600');
        $this->signIn();
        $this->propose();
        self::assertSame(303, $this->post('/admin/ai/01', ['article' => 'demo'])->status);
        self::assertSame(429, $this->post('/admin/ai/09', ['topic' => self::DEMO_TOPIC])->status);
        self::assertSame(429, $this->post('/admin/ai/01', ['article' => 'demo'])->status);
        self::assertCount(2, $this->hits->hits);
    }

    public function test_saving_ai_draft_is_not_limited(): void
    {
        $this->exhaustBothBucketsWithProposal();

        $response = $this->post('/admin/ai/09/ulozit', self::saveForm());

        self::assertSame(303, $response->status);
        self::assertCount(1, $this->adminArticles->articles, 'Zaplacený návrh se kvůli limitu neztratil.');
        $article = array_values($this->adminArticles->articles)[0];
        self::assertSame(sprintf('/admin/clanky/%d/upravit', $article->id), $response->headers['Location'] ?? null);
        self::assertSame(ArticleStatus::Draft, $article->status);
        self::assertCount(2, $this->hits->hits, 'Uložení nepřidá záznam limitu.');
    }

    public function test_discarding_ai_draft_is_not_limited(): void
    {
        $this->exhaustBothBucketsWithProposal();

        $response = $this->post('/admin/ai/09/zahodit');

        self::assertSame(303, $response->status);
        self::assertSame('/admin/ai/09', $response->headers['Location'] ?? null);
        self::assertArrayNotHasKey('ai_draft', $this->session->data);
        self::assertCount(2, $this->hits->hits, 'Zahození nepřidá záznam limitu.');
    }

    // ---------------------------------------------------------------- AC 15: GET se nepočítá

    public function test_get_requests_are_never_limited(): void
    {
        $this->bootLimited(standard: '1/60', heavy: '1/600');
        $this->signIn();

        for ($i = 0; $i < 20; ++$i) {
            foreach (['/admin/ai/01', '/admin/ai/06', '/admin/ai/07', '/admin/ai/08', '/admin/ai/09', '/admin/ai', '/admin'] as $path) {
                self::assertSame(200, $this->get($path)->status, $path . ' (' . ($i + 1) . '.)');
            }
        }

        self::assertSame([], $this->hits->hits);
        self::assertSame(0, $this->hits->windowCalls, 'GET se na okno ani neptá.');
        self::assertSame([], $this->audit->entries);
    }

    // ---------------------------------------------------------------- AC 16: pořadí middleware (CSRF, přihlášení)

    public function test_post_with_invalid_csrf_is_403_without_hit(): void
    {
        $this->signIn();

        $missing = $this->post('/admin/ai/01', ['article' => 'demo'], withToken: false);
        $wrong = $this->post('/admin/ai/01', ['article' => 'demo'], token: str_repeat('0', 64));

        self::assertSame(403, $missing->status);
        self::assertSame(403, $wrong->status);
        self::assertSame([], $this->hits->hits);
        self::assertSame(0, $this->hits->windowCalls);
    }

    public function test_anonymous_post_redirects_to_login_without_hit(): void
    {
        $response = $this->post('/admin/ai/01', ['article' => 'demo']);

        self::assertRedirectsToLogin($response);
        self::assertSame([], $this->hits->hits);
        self::assertSame(0, $this->hits->windowCalls);
        self::assertSame([], $this->audit->entries);
    }

    // ---------------------------------------------------------------- AC 17: správa obsahu se neomezuje

    public function test_content_management_is_not_limited_when_ai_bucket_is_exhausted(): void
    {
        $this->bootLimited(standard: '1/60');
        $this->signIn();
        self::assertSame(303, $this->post('/admin/ai/01', ['article' => 'demo'])->status);
        $form = ArticleInputs::post();

        $response = $this->kernel->handle(new Request(
            'POST',
            '/admin/clanky/novy',
            body: $form['body'] + ['_csrf' => $this->csrf()],
            clientIp: self::IP,
            bodyLists: $form['lists'],
        ));

        self::assertSame(303, $response->status);
        self::assertCount(1, $this->hits->hits, 'Správa obsahu nepřidá záznam limitu.');
    }

    // ---------------------------------------------------------------- AC 18 a R3: záznam vzniká před controllerem

    public function test_invalid_question_is_422_but_still_counts(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/07', ['question' => '']);

        self::assertSame(422, $response->status);
        self::assertCount(1, $this->hits->hits);
        self::assertSame('ai', $this->hits->hits[0]['bucket']);
    }

    public function test_unknown_example_is_404_but_still_counts(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/xyz', ['article' => 'demo']);

        self::assertSame(404, $response->status);
        self::assertCount(1, $this->hits->hits, 'POST /admin/ai/{example} je AiController::run, záznam vzniká před controllerem.');
    }

    public function test_default_limits_allow_ten_standard_requests(): void
    {
        $this->signIn();

        for ($i = 1; $i <= 10; ++$i) {
            self::assertSame(303, $this->post('/admin/ai/01', ['article' => 'demo'])->status, $i . '. požadavek');
        }

        self::assertHtmlLimitPage($this->post('/admin/ai/01', ['article' => 'demo']), 10, 60, 60);
    }

    // ---------------------------------------------------------------- nález 3 revize: neznámá AI trasa je fail-closed

    public function test_unmapped_post_handler_under_admin_ai_gets_standard_bucket(): void
    {
        $this->signIn();
        $middleware = $this->container->get(AiRateLimitMiddleware::class);
        $request = new Request('POST', '/admin/ai/11', clientIp: self::IP, route: new RouteMatch([AiController::class, 'show'], []));

        $response = $middleware->process($request, static fn(Request $r): Response => Response::html('ok'));

        self::assertSame(200, $response->status);
        self::assertCount(1, $this->hits->hits);
        self::assertSame('ai', $this->hits->hits[0]['bucket']);
    }

    public function test_unmapped_post_handler_outside_admin_ai_is_not_limited(): void
    {
        $this->signIn();
        $middleware = $this->container->get(AiRateLimitMiddleware::class);
        $request = new Request('POST', '/admin/clanky/novy', clientIp: self::IP, route: new RouteMatch([ArticleController::class, 'create'], []));

        $middleware->process($request, static fn(Request $r): Response => Response::html('ok'));

        self::assertCount(0, $this->hits->hits);
    }

    // ---------------------------------------------------------------- AC 21: ai-stream.js (textová kontrola; chování ověří E2E)

    public function test_stream_script_shows_json_error_of_429_like_422(): void
    {
        $source = (string) file_get_contents(AiFixtures::root() . '/public/assets/ai-stream.js');
        preg_match_all('~\bif\s*\((.+?)\)\s*\{~s', $source, $conditions);

        $jsonBranch = array_values(array_filter(
            $conditions[1],
            static fn(string $condition): bool => str_contains($condition, '422')
                && str_contains($condition, '429')
                && str_contains($condition, 'application/json'),
        ));

        self::assertCount(1, $jsonBranch, 'Odpověď 429 s JSON má jít stejnou větví jako 422 (zobrazit data.error).');
    }

    // ---------------------------------------------------------------- AC 20: pořadí v řetězu

    public function test_pipeline_ends_with_ai_rate_limit_after_admin_access(): void
    {
        $pipeline = $this->container->get(MiddlewarePipeline::class);
        $property = new \ReflectionProperty(MiddlewarePipeline::class, 'middleware');
        $middleware = $property->getValue($pipeline);
        self::assertIsArray($middleware);

        self::assertSame(
            [
                SecurityHeadersMiddleware::class,
                ErrorHandlerMiddleware::class,
                RoutingMiddleware::class,
                CsrfMiddleware::class,
                AdminAccessMiddleware::class,
                AiRateLimitMiddleware::class,
            ],
            array_map(static fn(mixed $item): string => \is_object($item) ? $item::class : get_debug_type($item), $middleware),
        );
    }
}
