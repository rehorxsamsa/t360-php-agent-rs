<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Container\Container;
use App\Domain\Article\AdminArticleSummary;
use App\Domain\Article\ArticleStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Category\Category;
use App\Domain\User\Role;
use App\Domain\User\User;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\ArticleInputs;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryCategoryRepository;
use App\Tests\Unit\Support\InMemoryTagRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 005, AC 17–29: administrace článků přes skutečný Kernel z config/container.php
 * (session, hodiny a repozitáře v paměti; admin id 7 „Administrátor“, čas 2026-10-03 12:00).
 */
final class AdminArticlesTest extends TestCase
{
    private const int ADMIN_ID = 7;

    private ArraySession $session;
    private InMemoryUserRepository $users;
    private InMemoryAuditLogRepository $audit;
    private InMemoryArticleAdminRepository $articles;
    private InMemoryCategoryRepository $categories;
    private Container $container;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->users = new InMemoryUserRepository();
        $this->users->users[self::ADMIN_ID] = new User(self::ADMIN_ID, 'admin@example.cz', 'Administrátor', 'hash', Role::Admin);
        $this->audit = new InMemoryAuditLogRepository();
        $this->articles = new InMemoryArticleAdminRepository();
        $this->categories = new InMemoryCategoryRepository();
        $this->container = TestContainer::create(
            $this->session,
            $this->users,
            $this->audit,
            clock: FixedClock::at('2026-10-03 12:00:00'),
            adminArticles: $this->articles,
            categories: $this->categories,
            tags: new InMemoryTagRepository(),
        );
        $this->kernel = $this->container->get(Kernel::class);
    }

    // ---------------------------------------------------------------- pomocníci

    private function signIn(): void
    {
        $this->session->set('user_id', self::ADMIN_ID);
    }

    private function csrf(): string
    {
        return $this->container->get(CsrfToken::class)->token();
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->kernel->handle(new Request('GET', $path, clientIp: '172.18.0.1', query: $query));
    }

    /**
     * @param array<string, string> $body
     * @param array<string, list<string>> $lists
     */
    private function post(string $path, array $body = [], array $lists = [], ?string $token = null, bool $withToken = true): Response
    {
        if ($withToken) {
            $body['_csrf'] = $token ?? $this->csrf();
        }

        return $this->kernel->handle(new Request('POST', $path, body: $body, clientIp: '172.18.0.1', bodyLists: $lists));
    }

    /**
     * @param array<string, string> $overrides
     * @param list<string>|null $tags
     */
    private function postForm(string $path, array $overrides = [], ?array $tags = ['3', '2']): Response
    {
        $form = ArticleInputs::post($overrides, $tags);

        return $this->post($path, $form['body'], $form['lists']);
    }

    private function addArticle5(string $title = 'Starý článek', string $body = ''): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, title: $title, slug: 'stary-clanek', body: $body));
    }

    private static function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Prague'));
    }

    private static function summary(
        int $id,
        string $title,
        ArticleStatus $status = ArticleStatus::Draft,
        string $categoryName = 'Technologie',
        ?string $publishedAt = null,
        string $updatedAt = '2026-10-01 09:00:00',
        ?string $updatedByName = 'Administrátor',
    ): AdminArticleSummary {
        return new AdminArticleSummary(
            id: $id,
            title: $title,
            slug: sprintf('clanek-%d', $id),
            status: $status,
            categoryName: $categoryName,
            publishedAt: $publishedAt === null ? null : self::date($publishedAt),
            updatedAt: self::date($updatedAt),
            updatedByName: $updatedByName,
        );
    }

    /**
     * Najde první značku `<$name …>`, která má všechny zadané atributy (pořadí atributů nehraje roli).
     * Atribut je `name="value"` nebo holý název (`required`, `checked`, `selected`).
     *
     * @param list<string> $attributes
     */
    private static function findTag(string $html, string $name, array $attributes): ?string
    {
        preg_match_all('~<' . $name . '\b[^>]*>~u', $html, $matches);
        foreach ($matches[0] as $tag) {
            $all = true;
            foreach ($attributes as $attribute) {
                if (preg_match('~\s' . preg_quote($attribute, '~') . '(?=[\s/>=])~u', $tag) !== 1) {
                    $all = false;

                    break;
                }
            }
            if ($all) {
                return $tag;
            }
        }

        return null;
    }

    /** @param list<string> $attributes */
    private static function assertHasTag(string $html, string $name, array $attributes): string
    {
        $tag = self::findTag($html, $name, $attributes);
        self::assertNotNull($tag, sprintf('Chybí <%s %s>.', $name, implode(' ', $attributes)));

        return $tag;
    }

    private static function attributeValue(string $tag, string $attribute): ?string
    {
        return preg_match('~\s' . preg_quote($attribute, '~') . '="([^"]*)"~u', $tag, $m) === 1 ? $m[1] : null;
    }

    private static function hasBareAttribute(string $tag, string $attribute): bool
    {
        return preg_match('~\s' . preg_quote($attribute, '~') . '(?=[\s/>=])~u', $tag) === 1;
    }

    /** Pole formuláře má `id` a k němu `<label for="…">`. */
    private static function assertFieldHasLabel(string $html, string $tag): void
    {
        $id = self::attributeValue($tag, 'id');
        self::assertNotNull($id, 'Pole nemá id: ' . $tag);
        self::assertMatchesRegularExpression('~<label\b[^>]*\sfor="' . preg_quote($id, '~') . '"~u', $html, 'Chybí <label for> pro ' . $tag);
    }

    /** @return list<string> hodnoty voleb v `<select name="$name">` v pořadí */
    private static function optionValues(string $html, string $name): array
    {
        if (preg_match('~<select\b[^>]*\sname="' . preg_quote($name, '~') . '"[^>]*>(.*?)</select>~su', $html, $select) !== 1) {
            self::fail(sprintf('Chybí <select name="%s">.', $name));
        }
        preg_match_all('~<option\b[^>]*\svalue="([^"]*)"~u', $select[1], $values);

        return $values[1];
    }

    private static function selectedOption(string $html, string $name): ?string
    {
        preg_match('~<select\b[^>]*\sname="' . preg_quote($name, '~') . '"[^>]*>(.*?)</select>~su', $html, $select);
        $tag = self::findTag($select[1] ?? '', 'option', ['selected']);

        return $tag === null ? null : self::attributeValue($tag, 'value');
    }

    private static function rowCount(string $html): int
    {
        return (int) preg_match_all('~href="/admin/clanky/\d+/smazat"~', $html);
    }

    private function assertNothingWritten(): void
    {
        self::assertSame(0, $this->articles->writeCount(), 'Repozitář dostal zápis.');
        self::assertSame([], $this->audit->entries, 'Vznikl záznam auditu.');
    }

    // ---------------------------------------------------------------- AC 17: přístup a CSRF

    /** @return iterable<string, array{string}> */
    public static function adminGetPaths(): iterable
    {
        yield 'list' => ['/admin/clanky'];
        yield 'new form' => ['/admin/clanky/novy'];
        yield 'edit form' => ['/admin/clanky/5/upravit'];
        yield 'delete confirmation' => ['/admin/clanky/5/smazat'];
    }

    /** @return iterable<string, array{string}> */
    public static function adminPostPaths(): iterable
    {
        yield 'store' => ['/admin/clanky/novy'];
        yield 'update' => ['/admin/clanky/5/upravit'];
        yield 'delete' => ['/admin/clanky/5/smazat'];
    }

    #[DataProvider('adminGetPaths')]
    public function test_anonymous_get_redirects_to_login(string $path): void
    {
        $this->addArticle5();

        $response = $this->get($path);

        self::assertSame(303, $response->status);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null);
    }

    #[DataProvider('adminPostPaths')]
    public function test_anonymous_post_with_valid_token_redirects_to_login_without_writes(string $path): void
    {
        $this->addArticle5();

        $response = $this->postForm($path);

        self::assertSame(303, $response->status);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null);
        self::assertNothingWritten();
    }

    #[DataProvider('adminPostPaths')]
    public function test_signed_in_post_without_token_is_403_without_writes(string $path): void
    {
        $this->addArticle5();
        $this->signIn();
        $form = ArticleInputs::post();

        $response = $this->post($path, $form['body'], $form['lists'], withToken: false);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('Neplatný formulář', $response->body);
        self::assertNothingWritten();
    }

    #[DataProvider('adminPostPaths')]
    public function test_signed_in_post_with_wrong_token_is_403_without_writes(string $path): void
    {
        $this->addArticle5();
        $this->signIn();
        $this->csrf();
        $form = ArticleInputs::post();

        $response = $this->post($path, $form['body'], $form['lists'], token: 'spatny-token');

        self::assertSame(403, $response->status);
        self::assertNothingWritten();
    }

    // ---------------------------------------------------------------- AC 18: seznam

    public function test_list_shows_all_articles_in_repository_order_with_status_and_editor(): void
    {
        $this->signIn();
        $this->articles->summaries = [
            self::summary(3, 'Naplánovaný článek', ArticleStatus::Published, 'Věda a výzkum', '2099-01-01 08:00:00'),
            self::summary(1, 'Koncept článku', ArticleStatus::Draft, 'Technologie', null, '2026-10-03 10:05:00', 'Administrátor'),
            self::summary(2, 'Publikovaný článek', ArticleStatus::Published, 'Zprávy', '2026-09-12 08:00:00', '2026-10-01 09:00:00', null),
        ];

        $response = $this->get('/admin/clanky');
        $body = $response->body;

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>Články</h1>', $body);
        self::assertStringContainsString('<a href="/admin/clanky/novy">Nový článek</a>', $body);
        self::assertStringContainsString('<table', $body);
        self::assertSame(3, self::rowCount($body));
        foreach ([1 => 'Koncept článku', 2 => 'Publikovaný článek', 3 => 'Naplánovaný článek'] as $id => $title) {
            self::assertStringContainsString(sprintf('<a href="/admin/clanky/%d/upravit">%s</a>', $id, $title), $body);
            self::assertStringContainsString(sprintf('href="/admin/clanky/%d/smazat"', $id), $body);
        }
        self::assertStringContainsString('Koncept', $body);
        self::assertStringContainsString('Publikováno', $body);
        self::assertStringContainsString('Naplánováno', $body);
        self::assertStringContainsString('Věda a výzkum', $body);
        self::assertStringContainsString('Zprávy', $body);
        self::assertStringContainsString('12. září 2026', $body);
        self::assertStringContainsString('1. ledna 2099', $body);
        self::assertStringContainsString('—', $body);
        self::assertStringContainsString('3. října 2026 10:05', $body);
        self::assertStringContainsString('Administrátor', $body);
        self::assertStringContainsString('neuvedeno', $body);
        self::assertStringContainsString('Upravit', $body);
        self::assertStringContainsString('Smazat', $body);

        $positions = array_map(
            static fn(string $title): int|false => strpos($body, '>' . $title . '</a>'),
            ['Naplánovaný článek', 'Koncept článku', 'Publikovaný článek'],
        );
        self::assertNotContains(false, $positions);
        self::assertSame($positions, array_values(array_unique($positions)));
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'Pořadí řádků musí odpovídat pořadí z repozitáře.');
    }

    public function test_scheduled_label_is_used_only_for_future_published_article(): void
    {
        $this->signIn();
        $this->articles->summaries = [self::summary(1, 'Publikovaný', ArticleStatus::Published, publishedAt: '2026-09-12 08:00:00')];

        $body = $this->get('/admin/clanky')->body;

        self::assertStringContainsString('Publikováno', $body);
        self::assertStringNotContainsString('Naplánováno', $body);
    }

    public function test_empty_list_shows_message(): void
    {
        $this->signIn();

        $response = $this->get('/admin/clanky');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Zatím tu nejsou žádné články.', $response->body);
        self::assertSame(0, self::rowCount($response->body));
    }

    // ---------------------------------------------------------------- AC 19: stránkování

    private function withSummaries(int $count): void
    {
        $summaries = [];
        for ($id = $count; $id >= 1; --$id) {
            $summaries[] = self::summary($id, sprintf('Článek %d', $id));
        }
        $this->articles->summaries = $summaries;
    }

    public function test_first_page_has_twenty_rows_and_link_to_next(): void
    {
        $this->signIn();
        $this->withSummaries(21);

        $body = $this->get('/admin/clanky')->body;

        self::assertSame(20, self::rowCount($body));
        self::assertStringContainsString('<a href="/admin/clanky?strana=2" rel="next">Další strana</a>', $body);
        self::assertStringNotContainsString('Předchozí strana', $body);
    }

    public function test_second_page_has_remaining_row_and_link_to_first(): void
    {
        $this->signIn();
        $this->withSummaries(21);

        $response = $this->get('/admin/clanky', ['strana' => '2']);

        self::assertSame(200, $response->status);
        self::assertSame(1, self::rowCount($response->body));
        self::assertStringContainsString('<a href="/admin/clanky" rel="prev">Předchozí strana</a>', $response->body);
        self::assertStringNotContainsString('Další strana', $response->body);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPages(): iterable
    {
        yield 'out of range' => ['3'];
        yield 'zero' => ['0'];
        yield 'letters' => ['abc'];
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_page_is_404(string $page): void
    {
        $this->signIn();
        $this->withSummaries(21);

        $response = $this->get('/admin/clanky', ['strana' => $page]);

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Stránka nenalezena', $response->body);
    }

    // ---------------------------------------------------------------- AC 20: formulář nového článku

    public function test_new_article_form_has_all_fields_with_labels(): void
    {
        $this->signIn();

        $response = $this->get('/admin/clanky/novy');
        $body = $response->body;

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>Nový článek</h1>', $body);
        self::assertStringContainsString('<form method="post" action="/admin/clanky/novy">', $body);
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $body);

        $title = self::assertHasTag($body, 'input', ['name="title"', 'maxlength="200"', 'required']);
        $slug = self::assertHasTag($body, 'input', ['name="slug"', 'maxlength="220"']);
        self::assertStringContainsString('Nechte prázdné – vytvoří se z titulku.', $body);
        $excerpt = self::assertHasTag($body, 'textarea', ['name="excerpt"', 'maxlength="500"']);
        $text = self::assertHasTag($body, 'textarea', ['name="body"']);
        $category = self::assertHasTag($body, 'select', ['name="category_id"']);
        $status = self::assertHasTag($body, 'select', ['name="status"']);
        $published = self::assertHasTag($body, 'input', ['type="datetime-local"', 'name="published_at"']);
        foreach ([$title, $slug, $excerpt, $text, $category, $status, $published] as $field) {
            self::assertFieldHasLabel($body, $field);
        }

        self::assertSame(['', '1', '2', '3'], self::optionValues($body, 'category_id'));
        self::assertStringContainsString('— vyberte rubriku —', $body);
        self::assertNull(self::selectedOption($body, 'category_id'));

        self::assertMatchesRegularExpression('~<fieldset\b[^>]*>\s*<legend>Štítky</legend>~u', $body);
        foreach (['1', '2', '3'] as $tagId) {
            $checkbox = self::assertHasTag($body, 'input', ['type="checkbox"', 'name="tags[]"', 'value="' . $tagId . '"']);
            self::assertFalse(self::hasBareAttribute($checkbox, 'checked'));
            self::assertFieldHasLabel($body, $checkbox);
        }
        self::assertStringContainsString('Bezpečnost', $body);
        self::assertStringContainsString('Docker', $body);
        self::assertStringContainsString('PHP', $body);

        self::assertSame(['draft', 'published', 'archived'], self::optionValues($body, 'status'));
        self::assertSame('draft', self::selectedOption($body, 'status'));
        self::assertStringContainsString('Koncept', $body);
        self::assertStringContainsString('Publikováno', $body);
        self::assertStringContainsString('Archiv', $body);
        self::assertStringContainsString('Uložit článek', $body);
    }

    // ---------------------------------------------------------------- AC 21: vytvoření

    public function test_valid_form_creates_article_and_redirects_to_edit_with_one_time_flash(): void
    {
        $this->signIn();

        $response = $this->postForm('/admin/clanky/novy');

        self::assertSame(303, $response->status);
        self::assertCount(1, $this->articles->articles);
        $id = (int) array_key_first($this->articles->articles);
        self::assertSame(sprintf('/admin/clanky/%d/upravit', $id), $response->headers['Location'] ?? null);

        $article = $this->articles->articles[$id];
        self::assertSame('novy-clanek', $article->slug);
        self::assertSame([2, 3], $article->tagIds);
        self::assertSame(self::ADMIN_ID, $this->articles->createdBy[$id]);
        self::assertSame(self::ADMIN_ID, $this->articles->updatedBy[$id]);
        self::assertCount(1, $this->audit->byAction(AuditAction::ArticleCreated));

        $first = $this->get(sprintf('/admin/clanky/%d/upravit', $id));
        self::assertSame(200, $first->status);
        self::assertMatchesRegularExpression('~role="status"[^>]*>(?:(?!</).)*?Článek byl vytvořen\.~su', $first->body);
        self::assertSame(1, substr_count($first->body, 'Článek byl vytvořen.'));
        self::assertStringNotContainsString('Článek byl vytvořen.', $this->get(sprintf('/admin/clanky/%d/upravit', $id))->body);
    }

    // ---------------------------------------------------------------- AC 22: chyby formuláře

    public function test_invalid_new_form_returns_422_with_errors_and_kept_values(): void
    {
        $this->signIn();

        $response = $this->postForm('/admin/clanky/novy', ['title' => '', 'category_id' => '', 'excerpt' => '<b>perex</b>'], ['3']);
        $body = $response->body;

        self::assertSame(422, $response->status);
        self::assertMatchesRegularExpression('~role="alert"[^>]*>(?:(?!</).)*?Článek se nepodařilo uložit, opravte prosím chyby ve formuláři\.~su', $body);
        self::assertStringContainsString('Vyplňte titulek.', $body);
        self::assertStringContainsString('Vyberte rubriku.', $body);
        self::assertMatchesRegularExpression('~id="title-error"[^>]*>[^<]*Vyplňte titulek\.~u', $body);
        self::assertHasTag($body, 'input', ['name="title"', 'aria-invalid="true"', 'aria-describedby="title-error"']);
        self::assertMatchesRegularExpression('~id="category_id-error"[^>]*>[^<]*Vyberte rubriku\.~u', $body);
        self::assertHasTag($body, 'select', ['name="category_id"', 'aria-invalid="true"', 'aria-describedby="category_id-error"']);

        self::assertMatchesRegularExpression('~<textarea\b[^>]*\sname="excerpt"[^>]*>&lt;b&gt;perex&lt;/b&gt;</textarea>~u', $body);
        self::assertStringNotContainsString('<b>perex</b>', $body);
        $checked = self::assertHasTag($body, 'input', ['name="tags[]"', 'value="3"']);
        self::assertTrue(self::hasBareAttribute($checked, 'checked'));
        $unchecked = self::assertHasTag($body, 'input', ['name="tags[]"', 'value="2"']);
        self::assertFalse(self::hasBareAttribute($unchecked, 'checked'));
        self::assertStringContainsString('<form method="post" action="/admin/clanky/novy">', $body);
        self::assertStringContainsString('name="_csrf"', $body);

        self::assertNothingWritten();
    }

    public function test_valid_field_has_no_aria_invalid(): void
    {
        $this->signIn();

        $body = $this->postForm('/admin/clanky/novy', ['category_id' => ''])->body;

        $title = self::assertHasTag($body, 'input', ['name="title"']);
        self::assertNull(self::attributeValue($title, 'aria-invalid'));
        self::assertStringContainsString('value="Nový článek"', $title);
    }

    // ---------------------------------------------------------------- AC 23: úprava – formulář

    private function addEditableArticle(ArticleStatus $status = ArticleStatus::Draft, string $publishedAt = '2026-11-01 09:30:00'): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(
            5,
            title: 'Starý článek',
            slug: 'stary-clanek',
            excerpt: 'Perex starého článku.',
            body: 'Text starého článku.',
            categoryId: 2,
            tagIds: [1, 3],
            status: $status,
            publishedAt: $publishedAt,
            updatedAt: '2026-10-02 14:05:00',
            updatedByName: 'Jana <b>',
        ));
    }

    public function test_edit_form_is_prefilled(): void
    {
        $this->signIn();
        $this->addEditableArticle();

        $response = $this->get('/admin/clanky/5/upravit');
        $body = $response->body;

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>Úprava článku</h1>', $body);
        self::assertStringContainsString('<form method="post" action="/admin/clanky/5/upravit">', $body);
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $body);
        self::assertHasTag($body, 'input', ['name="title"', 'value="Starý článek"']);
        self::assertHasTag($body, 'input', ['name="slug"', 'value="stary-clanek"']);
        self::assertMatchesRegularExpression('~<textarea\b[^>]*\sname="excerpt"[^>]*>Perex starého článku\.</textarea>~u', $body);
        self::assertMatchesRegularExpression('~<textarea\b[^>]*\sname="body"[^>]*>Text starého článku\.</textarea>~u', $body);
        self::assertSame('2', self::selectedOption($body, 'category_id'));
        self::assertSame('draft', self::selectedOption($body, 'status'));
        self::assertTrue(self::hasBareAttribute(self::assertHasTag($body, 'input', ['name="tags[]"', 'value="1"']), 'checked'));
        self::assertFalse(self::hasBareAttribute(self::assertHasTag($body, 'input', ['name="tags[]"', 'value="2"']), 'checked'));
        self::assertTrue(self::hasBareAttribute(self::assertHasTag($body, 'input', ['name="tags[]"', 'value="3"']), 'checked'));
        self::assertHasTag($body, 'input', ['name="published_at"', 'value="2026-11-01T09:30"']);
        self::assertStringContainsString('Naposledy upraveno 2. října 2026 14:05 (Jana &lt;b&gt;)', $body);
        self::assertStringNotContainsString('Jana <b>', $body);
        self::assertMatchesRegularExpression('~<a\b[^>]*href="/admin/clanky/5/smazat"[^>]*>Smazat článek</a>~u', $body);
        self::assertStringNotContainsString('Zobrazit na webu', $body);
    }

    public function test_edit_form_links_to_public_page_only_for_visible_article(): void
    {
        $this->signIn();
        $this->addEditableArticle(ArticleStatus::Published, '2026-09-01 08:00:00');

        $body = $this->get('/admin/clanky/5/upravit')->body;

        self::assertMatchesRegularExpression('~<a\b[^>]*href="/clanek/stary-clanek"[^>]*>Zobrazit na webu</a>~u', $body);
    }

    public function test_edit_form_has_no_public_link_for_scheduled_article(): void
    {
        $this->signIn();
        $this->addEditableArticle(ArticleStatus::Published, '2026-11-01 09:30:00');

        $body = $this->get('/admin/clanky/5/upravit')->body;

        self::assertStringNotContainsString('Zobrazit na webu', $body);
        self::assertStringNotContainsString('href="/clanek/stary-clanek"', $body);
    }

    // ---------------------------------------------------------------- AC 24: náhled

    public function test_edit_page_shows_sanitized_preview_of_saved_text(): void
    {
        $this->signIn();
        $this->addArticle5(body: "## Nadpis\n\n<script>alert(1)</script>");

        $body = $this->get('/admin/clanky/5/upravit')->body;

        self::assertStringContainsString('Náhled uloženého textu', $body);
        self::assertStringContainsString('<h2>Nadpis</h2>', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script', $body);
    }

    public function test_edit_page_without_text_has_no_preview(): void
    {
        $this->signIn();
        $this->addArticle5(body: '');

        $body = $this->get('/admin/clanky/5/upravit')->body;

        self::assertStringNotContainsString('Náhled uloženého textu', $body);
    }

    // ---------------------------------------------------------------- AC 25: úprava – uložení

    public function test_valid_update_redirects_back_with_one_time_flash(): void
    {
        $this->signIn();
        $this->addArticle5();

        $response = $this->postForm('/admin/clanky/5/upravit', ['title' => 'Nový titulek', 'slug' => 'stary-clanek']);

        self::assertSame(303, $response->status);
        self::assertSame('/admin/clanky/5/upravit', $response->headers['Location'] ?? null);
        self::assertCount(1, $this->articles->updateCalls);
        $call = $this->articles->updateCalls[0];
        self::assertSame(5, $call['id']);
        self::assertSame(self::ADMIN_ID, $call['editorId']);
        self::assertSame('stary-clanek', $call['data']->slug);
        self::assertSame('2026-10-03 12:00:00', $call['now']->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->audit->byAction(AuditAction::ArticleUpdated));

        $first = $this->get('/admin/clanky/5/upravit');
        self::assertMatchesRegularExpression('~role="status"[^>]*>(?:(?!</).)*?Změny byly uloženy\.~su', $first->body);
        self::assertStringNotContainsString('Změny byly uloženy.', $this->get('/admin/clanky/5/upravit')->body);
    }

    public function test_invalid_update_returns_422_without_writes(): void
    {
        $this->signIn();
        $this->addArticle5();

        $response = $this->postForm('/admin/clanky/5/upravit', ['title' => '', 'category_id' => '']);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('<form method="post" action="/admin/clanky/5/upravit">', $response->body);
        self::assertStringContainsString('Článek se nepodařilo uložit, opravte prosím chyby ve formuláři.', $response->body);
        self::assertStringContainsString('Vyplňte titulek.', $response->body);
        self::assertStringContainsString('Vyberte rubriku.', $response->body);
        self::assertNothingWritten();
    }

    // ---------------------------------------------------------------- AC 26: smazání

    public function test_delete_confirmation_page_does_not_delete(): void
    {
        $this->signIn();
        $this->addArticle5();

        $response = $this->get('/admin/clanky/5/smazat');
        $body = $response->body;

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>Smazat článek</h1>', $body);
        self::assertStringContainsString('Opravdu smazat článek „Starý článek“? Akci nelze vrátit.', $body);
        self::assertStringContainsString('<form method="post" action="/admin/clanky/5/smazat">', $body);
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $body);
        self::assertMatchesRegularExpression('~<button\b[^>]*>Smazat článek</button>~u', $body);
        self::assertMatchesRegularExpression('~<a\b[^>]*href="/admin/clanky/5/upravit"[^>]*>Zrušit</a>~u', $body);
        self::assertArrayHasKey(5, $this->articles->articles);
        self::assertNothingWritten();
    }

    public function test_delete_post_removes_article_and_redirects_to_list_with_flash(): void
    {
        $this->signIn();
        $this->addArticle5();

        $response = $this->post('/admin/clanky/5/smazat');

        self::assertSame(303, $response->status);
        self::assertSame('/admin/clanky', $response->headers['Location'] ?? null);
        self::assertSame([5], $this->articles->deleteCalls);
        self::assertCount(1, $this->audit->byAction(AuditAction::ArticleDeleted));

        $list = $this->get('/admin/clanky');
        self::assertMatchesRegularExpression('~role="status"[^>]*>(?:(?!</).)*?Článek „Starý článek“ byl smazán\.~su', $list->body);
        self::assertStringNotContainsString('byl smazán.', $this->get('/admin/clanky')->body);
    }

    // ---------------------------------------------------------------- AC 27: 404 a 405

    /** @return iterable<string, array{string, string}> */
    public static function notFoundRequests(): iterable
    {
        yield 'GET edit missing' => ['GET', '/admin/clanky/404/upravit'];
        yield 'POST edit missing' => ['POST', '/admin/clanky/404/upravit'];
        yield 'GET delete missing' => ['GET', '/admin/clanky/404/smazat'];
        yield 'POST delete missing' => ['POST', '/admin/clanky/404/smazat'];
        yield 'GET edit zero' => ['GET', '/admin/clanky/0/upravit'];
        yield 'POST edit zero' => ['POST', '/admin/clanky/0/upravit'];
        yield 'GET edit letters' => ['GET', '/admin/clanky/abc/upravit'];
        yield 'POST delete letters' => ['POST', '/admin/clanky/abc/smazat'];
        yield 'GET edit leading zero' => ['GET', '/admin/clanky/05/upravit'];
        yield 'POST edit leading zero' => ['POST', '/admin/clanky/05/upravit'];
        yield 'GET edit 19 digits' => ['GET', '/admin/clanky/1234567890123456789/upravit'];
        yield 'POST delete 19 digits' => ['POST', '/admin/clanky/1234567890123456789/smazat'];
    }

    #[DataProvider('notFoundRequests')]
    public function test_missing_or_invalid_id_is_404_without_writes(string $method, string $path): void
    {
        $this->signIn();
        $this->addArticle5();

        $response = $method === 'GET' ? $this->get($path) : $this->postForm($path);

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Stránka nenalezena', $response->body);
        self::assertNothingWritten();
    }

    public function test_put_is_405_with_allow_header(): void
    {
        $this->signIn();
        $this->addArticle5();

        $response = $this->kernel->handle(new Request('PUT', '/admin/clanky/5/upravit', clientIp: '172.18.0.1'));

        self::assertSame(405, $response->status);
        self::assertSame('GET, POST', $response->headers['Allow'] ?? null);
        self::assertNothingWritten();
    }

    // ---------------------------------------------------------------- AC 28: escapování

    public function test_list_form_and_confirmation_escape_title_and_category(): void
    {
        $this->signIn();
        $this->categories->categories = [new Category(1, 'R & D', 'r-d'), new Category(2, 'Zprávy', 'zpravy')];
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, title: '<script>alert(1)</script>', slug: 'skript', categoryId: 1));
        $this->articles->summaries = [self::summary(5, '<script>alert(1)</script>', categoryName: 'R & D')];

        $list = $this->get('/admin/clanky')->body;
        $form = $this->get('/admin/clanky/5/upravit')->body;
        $confirmation = $this->get('/admin/clanky/5/smazat')->body;

        foreach (['list' => $list, 'form' => $form, 'confirmation' => $confirmation] as $page => $body) {
            self::assertStringContainsString('&lt;script&gt;', $body, $page);
            self::assertStringNotContainsString('<script>alert', $body, $page);
        }
        self::assertStringContainsString('R &amp; D', $list);
        self::assertStringContainsString('R &amp; D', $form);
        self::assertStringNotContainsString('R & D', $list);
        self::assertStringNotContainsString('R & D', $form);
    }

    // ---------------------------------------------------------------- AC 29: rozcestník

    public function test_dashboard_links_to_articles(): void
    {
        $this->signIn();

        $body = $this->get('/admin')->body;

        self::assertStringContainsString('<a href="/admin/clanky">Články</a>', $body);
        self::assertStringNotContainsString('Správa článků přibude v dalším milníku.', $body);
    }
}
