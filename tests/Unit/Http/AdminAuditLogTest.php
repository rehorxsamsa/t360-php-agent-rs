<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Container\Container;
use App\Domain\Audit\AuditAction;
use App\Domain\User\Role;
use App\Domain\User\User;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 007, AC 1–10: stránka audit logu přes skutečný Kernel (session, hodiny a repozitáře v paměti;
 * admin id 7 „Administrátor“, čas 2026-10-04 12:00 Europe/Prague, výchozí záznamy #1–#5 z kontraktu).
 */
final class AdminAuditLogTest extends TestCase
{
    private const int ADMIN_ID = 7;

    private ArraySession $session;
    private InMemoryUserRepository $users;
    private InMemoryAuditLogRepository $audit;
    private Container $container;
    private Kernel $kernel;

    /** @var array<mixed> */
    private array $serverBackup = [];
    /** @var array<mixed> */
    private array $getBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->session = new ArraySession();
        $this->users = new InMemoryUserRepository();
        $this->users->users[self::ADMIN_ID] = new User(self::ADMIN_ID, 'admin@example.cz', 'Administrátor', 'hash', Role::Admin);
        $this->boot(InMemoryAuditLogRepository::withContractRecords());
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
    }

    private function boot(InMemoryAuditLogRepository $audit): void
    {
        $this->audit = $audit;
        $this->container = TestContainer::create(
            $this->session,
            $this->users,
            $this->audit,
            clock: FixedClock::at('2026-10-04 12:00:00'),
        );
        $this->kernel = $this->container->get(Kernel::class);
    }

    // ---------------------------------------------------------------- pomocníci

    private function signIn(): void
    {
        $this->session->set('user_id', self::ADMIN_ID);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->kernel->handle(new Request('GET', $path, clientIp: '172.18.0.1', query: $query));
    }

    private function repositoryUntouched(): void
    {
        self::assertSame(0, $this->audit->countCalls, 'count() se neměl volat.');
        self::assertSame(0, $this->audit->searchCalls, 'search() se neměl volat.');
    }

    /** Text bez značek, entity dekódované, bílé znaky sloučené. */
    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('~[\s\x{00A0}\x{202F}]+~u', ' ', $text));
    }

    private static function tbody(string $html): string
    {
        if (preg_match('~<tbody\b[^>]*>(.*?)</tbody>~su', $html, $m) !== 1) {
            self::fail('Chybí <tbody>.');
        }

        return $m[1];
    }

    /** @return list<list<string>> text buněk každého řádku tabulky */
    private static function rows(string $html): array
    {
        preg_match_all('~<tr\b[^>]*>(.*?)</tr>~su', self::tbody($html), $rows);
        $result = [];
        foreach ($rows[1] as $row) {
            preg_match_all('~<t[hd]\b[^>]*>(.*?)</t[hd]>~su', $row, $cells);
            $result[] = array_map(self::text(...), $cells[1]);
        }

        return $result;
    }

    /** @return list<string> hodnoty `datetime` v pořadí řádků */
    private static function rowTimes(string $html): array
    {
        preg_match_all('~<time\b[^>]*\sdatetime="([^"]*)"~u', self::tbody($html), $m);

        return $m[1];
    }

    /** @param list<string> $attributes */
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

    /** @return list<array{value: string, text: string, selected: bool}> */
    private static function options(string $html, string $name): array
    {
        if (preg_match('~<select\b[^>]*\sname="' . preg_quote($name, '~') . '"[^>]*>(.*?)</select>~su', $html, $select) !== 1) {
            self::fail(sprintf('Chybí <select name="%s">.', $name));
        }
        preg_match_all('~<option\b([^>]*)>(.*?)</option>~su', $select[1], $matches, PREG_SET_ORDER);
        $options = [];
        foreach ($matches as $match) {
            $options[] = [
                'value' => preg_match('~\svalue="([^"]*)"~u', $match[1], $value) === 1 ? $value[1] : '',
                'text' => self::text($match[2]),
                'selected' => preg_match('~\sselected(?=[\s/>=]|$)~u', $match[1]) === 1,
            ];
        }

        return $options;
    }

    private static function filterForm(string $html): string
    {
        if (preg_match('~<form\b(?=[^>]*\smethod="get")(?=[^>]*\saction="/admin/audit")[^>]*>(.*?)</form>~su', $html, $m) !== 1) {
            self::fail('Chybí <form method="get" action="/admin/audit">.');
        }

        return $m[1];
    }

    private static function assertAlert(string $html, string $message): void
    {
        self::assertMatchesRegularExpression(
            '~role="alert"[^>]*>(?:(?!</(?:div|section)>).)*?' . preg_quote($message, '~') . '~su',
            $html,
            'Chybí role="alert" s hláškou: ' . $message,
        );
    }

    private static function assertLink(string $html, string $href, string $text): void
    {
        self::assertMatchesRegularExpression(
            '~<a\b[^>]*\shref="' . preg_quote($href, '~') . '"[^>]*>\s*' . preg_quote($text, '~') . '\s*</a>~u',
            $html,
            sprintf('Chybí odkaz „%s“ na %s.', $text, $href),
        );
    }

    private static function assertNoLink(string $html, string $text): void
    {
        self::assertDoesNotMatchRegularExpression('~<a\b[^>]*>\s*' . preg_quote($text, '~') . '\s*</a>~u', $html);
    }

    /** 120 × auth.login (2026-10-01 08:00 + i min) a 3 jiné akce. */
    private function bootWithManyLogins(): void
    {
        $audit = new InMemoryAuditLogRepository();
        $start = new \DateTimeImmutable('2026-10-01 08:00:00', new \DateTimeZone('Europe/Prague'));
        for ($i = 0; $i < 120; ++$i) {
            $audit->records[] = InMemoryAuditLogRepository::record(
                $i + 1,
                $start->modify(sprintf('+%d minutes', $i))->format('Y-m-d H:i:s'),
                'auth.login',
                self::ADMIN_ID,
                'Administrátor',
            );
        }
        $audit->records[] = InMemoryAuditLogRepository::record(121, '2026-10-02 09:00:00', 'auth.logout', self::ADMIN_ID, 'Administrátor');
        $audit->records[] = InMemoryAuditLogRepository::record(122, '2026-10-02 09:30:00', 'auth.login_failed', summary: 'x@example.cz');
        $audit->records[] = InMemoryAuditLogRepository::record(123, '2026-10-02 10:00:00', 'article.updated', self::ADMIN_ID, 'Administrátor', 'article', 5);
        $this->boot($audit);
    }

    // ---------------------------------------------------------------- AC 1: přístup

    public function test_anonymous_get_redirects_to_login_without_reading_audit(): void
    {
        $response = $this->get('/admin/audit');

        self::assertSame(303, $response->status);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null);
        $this->repositoryUntouched();
    }

    public function test_post_is_405_with_allow_get_even_with_valid_token(): void
    {
        $this->signIn();
        $token = $this->container->get(CsrfToken::class)->token();

        $response = $this->kernel->handle(new Request('POST', '/admin/audit', body: ['_csrf' => $token], clientIp: '172.18.0.1'));

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
        $this->repositoryUntouched();
    }

    public function test_audit_detail_path_does_not_exist(): void
    {
        $this->signIn();

        self::assertSame(404, $this->get('/admin/audit/1')->status);
        $this->repositoryUntouched();
    }

    // ---------------------------------------------------------------- AC 2: výpis

    public function test_signed_in_admin_sees_all_records_newest_first(): void
    {
        $this->signIn();

        $response = $this->get('/admin/audit');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>Audit log</h1>', $response->body);
        self::assertStringContainsString('Počet záznamů: 5', self::text($response->body));
        self::assertSame(
            ['2026-10-04T10:15:30+02:00', '2026-10-04T00:00:00+02:00', '2026-10-03T23:59:59+02:00', '2026-10-03T00:00:00+02:00', '2026-10-02T23:59:59+02:00'],
            self::rowTimes($response->body),
        );
    }

    public function test_table_has_column_headers_in_order(): void
    {
        $this->signIn();

        $body = $this->get('/admin/audit')->body;

        if (preg_match('~<thead\b[^>]*>(.*?)</thead>~su', $body, $thead) !== 1) {
            self::fail('Chybí <thead>.');
        }
        preg_match_all('~<th\b[^>]*>(.*?)</th>~su', $thead[1], $headers);
        self::assertSame(['Čas', 'Akce', 'Uživatel', 'Objekt', 'Shrnutí', 'IP adresa'], array_map(self::text(...), $headers[1]));
        self::assertMatchesRegularExpression('~<table\b[^>]*>\s*<caption\b[^>]*>~u', $body, 'Tabulka má mít <caption>.');
    }

    public function test_rows_show_time_label_user_object_summary_and_ip(): void
    {
        $this->signIn();

        $body = $this->get('/admin/audit')->body;
        $rows = self::rows($body);

        self::assertCount(5, $rows);
        self::assertMatchesRegularExpression(
            '~<time datetime="2026-10-04T10:15:30\+02:00">\s*4\. října 2026 10:15:30\s*</time>~u',
            $body,
        );
        // #5 – odhlášení
        self::assertSame('4. října 2026 10:15:30', $rows[0][0]);
        self::assertSame('Odhlášení', $rows[0][1]);
        self::assertSame('Administrátor', $rows[0][2]);
        self::assertSame('—', $rows[0][3]);
        // #4 – smazání článku
        self::assertSame('Smazání článku', $rows[1][1]);
        self::assertSame('článek #5', $rows[1][3]);
        // #3 – vytvoření článku
        self::assertSame('Vytvoření článku', $rows[2][1]);
        self::assertSame('článek #5', $rows[2][3]);
        self::assertSame('Titulek [titulek]', $rows[2][4]);
        // #2 – přihlášení
        self::assertSame('Přihlášení', $rows[3][1]);
        // #1 – neúspěšné přihlášení bez uživatele
        self::assertSame('Neúspěšné přihlášení', $rows[4][1]);
        self::assertSame('—', $rows[4][2]);
        self::assertSame('x@example.cz', $rows[4][4]);
        self::assertSame('172.19.0.1', $rows[4][5]);
    }

    public function test_unknown_action_from_future_version_is_shown_verbatim(): void
    {
        $audit = new InMemoryAuditLogRepository();
        $audit->records = [InMemoryAuditLogRepository::record(1, '2026-10-04 09:00:00', 'legacy.x', entityType: 'category', entityId: 2)];
        $this->boot($audit);
        $this->signIn();

        $response = $this->get('/admin/audit');

        self::assertSame(200, $response->status);
        self::assertSame('legacy.x', self::rows($response->body)[0][1]);
        self::assertSame('category #2', self::rows($response->body)[0][3]);
    }

    // ---------------------------------------------------------------- AC 3: formulář filtru

    public function test_filter_form_is_get_without_csrf_and_has_labelled_fields(): void
    {
        $this->signIn();

        $body = $this->get('/admin/audit')->body;
        $form = self::filterForm($body);

        self::assertStringNotContainsString('_csrf', $form);
        self::assertMatchesRegularExpression('~<label\b[^>]*\sfor="akce"[^>]*>\s*Akce\s*</label>~u', $form);
        self::assertHasTag($form, 'select', ['name="akce"', 'id="akce"']);
        self::assertMatchesRegularExpression('~<label\b[^>]*\sfor="od"[^>]*>\s*Od\s*</label>~u', $form);
        self::assertHasTag($form, 'input', ['type="date"', 'name="od"', 'id="od"']);
        self::assertMatchesRegularExpression('~<label\b[^>]*\sfor="do"[^>]*>\s*Do\s*</label>~u', $form);
        self::assertHasTag($form, 'input', ['type="date"', 'name="do"', 'id="do"']);
        self::assertMatchesRegularExpression('~<button\b[^>]*>\s*Filtrovat\s*</button>~u', $form);
        self::assertLink($body, '/admin/audit', 'Zrušit filtr');
    }

    public function test_action_select_offers_all_actions_in_enum_order(): void
    {
        $this->signIn();

        $options = self::options($this->get('/admin/audit')->body, 'akce');

        $expected = [['value' => '', 'text' => 'Všechny akce']];
        foreach (AuditAction::cases() as $action) {
            $expected[] = ['value' => $action->value, 'text' => $action->label()];
        }
        self::assertSame($expected, array_map(static fn(array $o): array => ['value' => $o['value'], 'text' => $o['text']], $options));
        self::assertCount(8, $options);
    }

    public function test_form_shows_current_filter(): void
    {
        $this->signIn();

        $body = $this->get('/admin/audit', ['akce' => 'auth.login', 'od' => '2026-10-03', 'do' => '2026-10-04'])->body;

        $selected = array_values(array_filter(self::options($body, 'akce'), static fn(array $o): bool => $o['selected']));
        self::assertSame(['auth.login'], array_column($selected, 'value'));
        self::assertSame('2026-10-03', self::attributeValue(self::assertHasTag($body, 'input', ['name="od"']), 'value'));
        self::assertSame('2026-10-04', self::attributeValue(self::assertHasTag($body, 'input', ['name="do"']), 'value'));
    }

    // ---------------------------------------------------------------- AC 4–5: filtr akce a data

    public function test_action_filter_shows_only_matching_records(): void
    {
        $this->signIn();

        $response = $this->get('/admin/audit', ['akce' => 'article.deleted']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Počet záznamů: 1', self::text($response->body));
        self::assertSame(['2026-10-04T00:00:00+02:00'], self::rowTimes($response->body));
    }

    /** @return iterable<string, array{array<string, string>, list<string>}> */
    public static function dateFilters(): iterable
    {
        yield 'one day' => [['od' => '2026-10-03', 'do' => '2026-10-03'], ['2026-10-03T23:59:59+02:00', '2026-10-03T00:00:00+02:00']];
        yield 'only from' => [['od' => '2026-10-04'], ['2026-10-04T10:15:30+02:00', '2026-10-04T00:00:00+02:00']];
        yield 'only to' => [['do' => '2026-10-02'], ['2026-10-02T23:59:59+02:00']];
        yield 'action and from' => [['akce' => 'auth.login', 'od' => '2026-10-03'], ['2026-10-03T00:00:00+02:00']];
    }

    /**
     * @param array<string, string> $query
     * @param list<string> $expectedTimes
     */
    #[DataProvider('dateFilters')]
    public function test_date_filter_uses_prague_day_bounds(array $query, array $expectedTimes): void
    {
        $this->signIn();

        $response = $this->get('/admin/audit', $query);

        self::assertSame(200, $response->status);
        self::assertSame($expectedTimes, self::rowTimes($response->body));
        self::assertStringContainsString(sprintf('Počet záznamů: %d', \count($expectedTimes)), self::text($response->body));
    }

    // ---------------------------------------------------------------- AC 6: prázdné výsledky

    public function test_empty_log_without_filter_shows_message(): void
    {
        $this->boot(new InMemoryAuditLogRepository());
        $this->signIn();

        $response = $this->get('/admin/audit');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Audit log je zatím prázdný.', self::text($response->body));
        self::assertStringNotContainsString('<table', $response->body);
        self::filterForm($response->body);
    }

    public function test_filter_without_match_shows_message(): void
    {
        $this->signIn();

        $response = $this->get('/admin/audit', ['akce' => 'user.created']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Filtru neodpovídá žádný záznam.', self::text($response->body));
        self::assertStringNotContainsString('<table', $response->body);
        self::filterForm($response->body);
    }

    // ---------------------------------------------------------------- AC 7: neplatný filtr

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function invalidFilters(): iterable
    {
        yield 'unknown action' => [['akce' => 'xyz'], 'Vyberte akci ze seznamu.', 'akce'];
        foreach (['2026-13-01', '2026-02-30', '3.10.2026', '2026-1-1'] as $value) {
            yield 'from ' . $value => [['od' => $value], 'Zadejte datum od ve tvaru RRRR-MM-DD.', 'od'];
            yield 'to ' . $value => [['do' => $value], 'Zadejte datum do ve tvaru RRRR-MM-DD.', 'do'];
        }
    }

    /** @param array<string, string> $query */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filter_is_422_with_error_and_without_query(array $query, string $message, string $field): void
    {
        $this->signIn();

        $response = $this->get('/admin/audit', $query);

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, $message);
        self::assertStringNotContainsString('<table', $response->body);
        self::filterForm($response->body);
        $tagName = $field === 'akce' ? 'select' : 'input';
        self::assertHasTag($response->body, $tagName, ['name="' . $field . '"', 'aria-invalid="true"']);
        $this->repositoryUntouched();
    }

    public function test_from_after_to_is_422(): void
    {
        $this->signIn();

        $response = $this->get('/admin/audit', ['od' => '2026-10-04', 'do' => '2026-10-03']);

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, 'Datum od nesmí být pozdější než datum do.');
        $this->repositoryUntouched();
    }

    public function test_more_errors_are_shown_together(): void
    {
        $this->signIn();

        $response = $this->get('/admin/audit', ['akce' => 'xyz', 'od' => '2026-13-01']);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Vyberte akci ze seznamu.', $response->body);
        self::assertStringContainsString('Zadejte datum od ve tvaru RRRR-MM-DD.', $response->body);
        $this->repositoryUntouched();
    }

    public function test_array_parameter_from_query_is_ignored(): void
    {
        $this->signIn();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/audit?akce%5B%5D=x';
        $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
        $_GET = ['akce' => ['x']];

        $response = $this->kernel->handle(Request::fromGlobals());

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Počet záznamů: 5', self::text($response->body));
    }

    // ---------------------------------------------------------------- AC 8: stránkování

    public function test_first_page_of_filtered_results_links_to_next_with_filter(): void
    {
        $this->bootWithManyLogins();
        $this->signIn();

        $response = $this->get('/admin/audit', ['akce' => 'auth.login']);

        self::assertSame(200, $response->status);
        self::assertCount(50, self::rows($response->body));
        self::assertStringContainsString('Strana 1 z 3', self::text($response->body));
        self::assertStringContainsString('Počet záznamů: 120', self::text($response->body));
        self::assertLink($response->body, '/admin/audit?akce=auth.login&amp;strana=2', 'Další strana');
        self::assertNoLink($response->body, 'Předchozí strana');
    }

    public function test_last_page_links_to_previous_with_filter(): void
    {
        $this->bootWithManyLogins();
        $this->signIn();

        $response = $this->get('/admin/audit', ['akce' => 'auth.login', 'strana' => '3']);

        self::assertSame(200, $response->status);
        self::assertCount(20, self::rows($response->body));
        self::assertStringContainsString('Strana 3 z 3', self::text($response->body));
        self::assertLink($response->body, '/admin/audit?akce=auth.login&amp;strana=2', 'Předchozí strana');
        self::assertNoLink($response->body, 'Další strana');
    }

    public function test_previous_from_second_page_leads_to_first_without_page_parameter(): void
    {
        $this->bootWithManyLogins();
        $this->signIn();

        $body = $this->get('/admin/audit', ['akce' => 'auth.login', 'strana' => '2'])->body;

        self::assertLink($body, '/admin/audit?akce=auth.login', 'Předchozí strana');
        self::assertLink($body, '/admin/audit?akce=auth.login&amp;strana=3', 'Další strana');
    }

    public function test_page_links_keep_parameter_order_and_skip_empty_ones(): void
    {
        $this->bootWithManyLogins();
        $this->signIn();

        $body = $this->get('/admin/audit', ['strana' => '2', 'do' => '2026-10-04', 'od' => '2026-10-01', 'akce' => 'auth.login'])->body;

        self::assertLink($body, '/admin/audit?akce=auth.login&amp;od=2026-10-01&amp;do=2026-10-04', 'Předchozí strana');
        self::assertLink($body, '/admin/audit?akce=auth.login&amp;od=2026-10-01&amp;do=2026-10-04&amp;strana=3', 'Další strana');
    }

    public function test_unfiltered_pagination_has_no_empty_parameters(): void
    {
        $this->bootWithManyLogins();
        $this->signIn();

        $body = $this->get('/admin/audit', ['akce' => '', 'od' => '', 'do' => ''])->body;

        self::assertStringContainsString('Strana 1 z 3', self::text($body));
        self::assertLink($body, '/admin/audit?strana=2', 'Další strana');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPages(): iterable
    {
        yield 'out of range' => ['4'];
        yield 'zero' => ['0'];
        yield 'text' => ['abc'];
        yield 'leading zero' => ['01'];
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_page_is_404(string $page): void
    {
        $this->bootWithManyLogins();
        $this->signIn();

        self::assertSame(404, $this->get('/admin/audit', ['akce' => 'auth.login', 'strana' => $page])->status);
    }

    public function test_empty_result_is_valid_first_page_but_second_is_404(): void
    {
        $this->boot(new InMemoryAuditLogRepository());
        $this->signIn();

        self::assertSame(200, $this->get('/admin/audit', ['strana' => '1'])->status);
        self::assertSame(404, $this->get('/admin/audit', ['strana' => '2'])->status);
    }

    // ---------------------------------------------------------------- AC 9: escapování

    public function test_summary_and_user_name_are_escaped(): void
    {
        $audit = new InMemoryAuditLogRepository();
        $audit->records = [
            InMemoryAuditLogRepository::record(1, '2026-10-04 09:00:00', 'auth.login_failed', summary: '<script>alert(1)</script>'),
            InMemoryAuditLogRepository::record(2, '2026-10-04 09:01:00', 'auth.login', 8, '<b>Eva</b>'),
        ];
        $this->boot($audit);
        $this->signIn();

        $body = $this->get('/admin/audit')->body;

        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringNotContainsString('<b>Eva</b>', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringContainsString('&lt;b&gt;Eva&lt;/b&gt;', $body);
    }

    public function test_invalid_date_is_echoed_only_escaped(): void
    {
        $this->signIn();

        $response = $this->get('/admin/audit', ['od' => '"><script>']);

        self::assertSame(422, $response->status);
        self::assertStringNotContainsString('"><script>', $response->body);
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;"', $response->body);
    }

    // ---------------------------------------------------------------- AC 10: rozcestník

    public function test_dashboard_links_to_audit_log(): void
    {
        $this->signIn();

        self::assertStringContainsString('<a href="/admin/audit">Audit log</a>', $this->get('/admin')->body);
    }
}
