<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Ai\AiConfig;
use App\Ai\AiProvider;
use App\Ai\Examples\ExampleRegistry;
use App\Ai\LlmClient;
use App\Container\Container;
use App\Domain\Ai\AiCallStatus;
use App\Domain\Ai\TokenUsage;
use App\Domain\Article\AdminArticleSummary;
use App\Domain\Article\ArticleStatus;
use App\Domain\User\Role;
use App\Domain\User\User;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\ScriptedHttpTransport;
use App\Tests\Unit\Support\ScriptedLlmClient;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 006, AC 22–28: stránky AI nástrojů přes skutečný Kernel (session, hodiny, repozitáře a log
 * volání v paměti; výchozí LlmClient = skutečný MeteredLlmClient nad FakeLlmClient; admin id 7).
 */
final class AdminAiTest extends TestCase
{
    private const int ADMIN_ID = 7;
    private const string SECRET = 'sk-ant-api03-tajny-klic-web';

    private ArraySession $session;
    private InMemoryUserRepository $users;
    private InMemoryAiCallRepository $aiCalls;
    private InMemoryArticleAdminRepository $articles;
    private Container $container;
    private Kernel $kernel;
    private string|false $previousErrorLog = false;
    private string $errorLogFile = '';

    protected function setUp(): void
    {
        // error_log() klientů (např. selhání AnthropicClient) nesmí zahlcovat výstup PHPUnitu.
        $this->errorLogFile = sys_get_temp_dir() . '/t360-admin-ai-' . bin2hex(random_bytes(4)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->errorLogFile);

        $this->session = new ArraySession();
        $this->users = new InMemoryUserRepository();
        $this->users->users[self::ADMIN_ID] = new User(self::ADMIN_ID, 'admin@example.cz', 'Administrátor', 'hash', Role::Admin);
        $this->aiCalls = new InMemoryAiCallRepository();
        $this->articles = new InMemoryArticleAdminRepository();
        $this->boot();
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
    }

    private function errorLog(): string
    {
        return is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
    }

    private function boot(?LlmClient $llm = null, ?AiConfig $config = null, ?ScriptedHttpTransport $transport = null): void
    {
        $this->container = TestContainer::create(
            $this->session,
            $this->users,
            new InMemoryAuditLogRepository(),
            clock: FixedClock::at('2026-10-03 12:00:00'),
            adminArticles: $this->articles,
            aiCalls: $this->aiCalls,
            llmClient: $llm,
            aiConfig: $config,
            httpTransport: $transport,
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

    private function get(string $path): Response
    {
        return $this->kernel->handle(new Request('GET', $path, clientIp: '172.18.0.1'));
    }

    /**
     * @param array<string, string> $body
     * @param array<string, list<string>> $lists pole formuláře (`name[]=…`), jak je předá Request::fromGlobals()
     */
    private function post(string $path, array $body = [], ?string $token = null, bool $withToken = true, array $lists = []): Response
    {
        if ($withToken) {
            $body['_csrf'] = $token ?? $this->csrf();
        }

        return $this->kernel->handle(new Request('POST', $path, body: $body, clientIp: '172.18.0.1', bodyLists: $lists));
    }

    /** Text stránky bez značek, entity dekódované, bílé znaky (i nezlomitelné mezery) sloučené. */
    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('~[\s\x{00A0}\x{202F}]+~u', ' ', $text));
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

    /** @return list<array{value: string, text: string, selected: bool}> */
    private static function options(string $html, string $name): array
    {
        if (preg_match('~<select\b[^>]*\sname="' . preg_quote($name, '~') . '"[^>]*>(.*?)</select>~su', $html, $select) !== 1) {
            self::fail(sprintf('Chybí <select name="%s">.', $name));
        }
        preg_match_all('~<option\b([^>]*)>(.*?)</option>~su', $select[1], $matches, PREG_SET_ORDER);
        $options = [];
        foreach ($matches as $match) {
            preg_match('~\svalue="([^"]*)"~u', $match[1], $value);
            $options[] = [
                'value' => $value[1] ?? '',
                'text' => trim($match[2]),
                'selected' => preg_match('~\sselected(?=[\s/>=]|$)~u', $match[1]) === 1,
            ];
        }

        return $options;
    }

    private static function selected(string $html, string $name): ?string
    {
        foreach (self::options($html, $name) as $option) {
            if ($option['selected']) {
                return $option['value'];
            }
        }

        return null;
    }

    private static function assertAlert(string $html, string $message): void
    {
        self::assertMatchesRegularExpression(
            '~role="alert"[^>]*>(?:(?!</(?:div|p|section)>).)*?' . preg_quote($message, '~') . '~su',
            $html,
            'Chybí role="alert" s hláškou: ' . $message,
        );
    }

    private function addArticle5(string $title = 'Článek pět', string $body = 'Text článku pět. Druhá věta.'): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, title: $title, slug: 'clanek-pet', body: $body));
        $this->articles->summaries = [new AdminArticleSummary(
            5,
            $title,
            'clanek-pet',
            ArticleStatus::Draft,
            'Technologie',
            null,
            new \DateTimeImmutable('2026-10-01 09:00:00', new \DateTimeZone('Europe/Prague')),
            null,
        )];
    }

    // ---------------------------------------------------------------- AC 22: přístup a CSRF

    /** @return iterable<string, array{string}> */
    public static function adminPaths(): iterable
    {
        yield 'overview' => ['/admin/ai'];
        yield 'example form' => ['/admin/ai/01'];
    }

    #[DataProvider('adminPaths')]
    public function test_anonymous_get_redirects_to_login(string $path): void
    {
        $response = $this->get($path);

        self::assertSame(303, $response->status);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null);
    }

    public function test_anonymous_post_with_valid_token_redirects_to_login_without_calling_llm(): void
    {
        $llm = new ScriptedLlmClient();
        $this->boot($llm);

        $response = $this->post('/admin/ai/01', ['article' => 'demo']);

        self::assertSame(303, $response->status);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null);
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_signed_in_post_without_token_is_403(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/01', ['article' => 'demo'], withToken: false);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('Neplatný formulář', $response->body);
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_signed_in_post_with_wrong_token_is_403(): void
    {
        $this->signIn();
        $this->csrf();

        $response = $this->post('/admin/ai/01', ['article' => 'demo'], token: 'spatny-token');

        self::assertSame(403, $response->status);
        self::assertStringContainsString('Neplatný formulář', $response->body);
        self::assertSame([], $this->aiCalls->calls);
    }

    // ---------------------------------------------------------------- AC 23: přehled

    private function seedCalls(): void
    {
        $this->aiCalls->add(InMemoryAiCallRepository::call('2026-10-02 23:59:59', new TokenUsage(500, 0), 0.5, exampleId: '05'));
        $this->aiCalls->add(InMemoryAiCallRepository::call(
            '2026-10-03 08:00:00',
            new TokenUsage(30, 4),
            0.001,
            exampleId: '02',
            status: AiCallStatus::Error,
            errorType: 'rate_limited',
        ));
        $this->aiCalls->add(InMemoryAiCallRepository::call('2026-10-03 11:00:00', new TokenUsage(1000, 200), 0.006, exampleId: '01'));
    }

    public function test_overview_shows_provider_models_usage_examples_and_recent_calls(): void
    {
        $this->signIn();
        $this->seedCalls();

        $response = $this->get('/admin/ai');
        $body = $response->body;
        $text = self::text($body);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>AI nástroje</h1>', $body);
        self::assertStringContainsString('Poskytovatel: falešný klient (bez API klíče, nic se neúčtuje)', $text);
        self::assertStringContainsString('claude-sonnet-5-5', $text);
        self::assertStringContainsString('claude-haiku-4-5-20251001', $text);
        self::assertStringContainsString('Dnes: 2 volání, 1 234 z 200 000 tokenů, 0,007000 USD', $text);

        $titles = [
            '01' => 'Perex na jedno kliknutí',
            '02' => 'SEO titulek a meta popis',
            '03' => 'Štítky a rubrika',
            '04' => 'Kontrola před publikací',
            '05' => 'Překlad CZ → EN',
        ];
        foreach ($titles as $id => $title) {
            self::assertStringContainsString(sprintf('<a href="/admin/ai/%s">%s – %s</a>', $id, $id, e($title)), $body);
        }
        foreach ($this->container->get(ExampleRegistry::class)->all() as $example) {
            self::assertStringContainsString(e($example->description()), $body, 'Popis příkladu ' . $example->id());
        }

        self::assertStringContainsString('Poslední volání', $text);
        self::assertStringContainsString('3. října 2026 11:00', $text);
        self::assertStringContainsString('3. října 2026 08:00', $text);
        self::assertStringContainsString('2. října 2026 23:59', $text);
        self::assertMatchesRegularExpression('~\bOK\b~u', $text);
        self::assertStringContainsString('Chyba (rate_limited)', $text);
        self::assertStringNotContainsString('Zatím žádná volání.', $text);

        // Řazení: nejnovější první.
        self::assertLessThan(strpos($text, '3. října 2026 08:00'), strpos($text, '3. října 2026 11:00'));
        self::assertLessThan(strpos($text, '2. října 2026 23:59'), strpos($text, '3. října 2026 08:00'));
    }

    public function test_overview_without_calls_shows_message_and_zero_usage(): void
    {
        $this->signIn();

        $text = self::text($this->get('/admin/ai')->body);

        self::assertStringContainsString('Zatím žádná volání.', $text);
        self::assertStringContainsString('Dnes: 0 volání, 0 z 200 000 tokenů, 0,000000 USD', $text);
    }

    public function test_overview_with_anthropic_provider_never_shows_key(): void
    {
        $this->boot(config: AiFixtures::config(AiProvider::Anthropic, self::SECRET));
        $this->signIn();

        $body = $this->get('/admin/ai')->body;

        self::assertStringContainsString('Poskytovatel: Claude API (Anthropic)', self::text($body));
        self::assertStringNotContainsString(self::SECRET, $body);
    }

    public function test_get_pages_never_call_llm(): void
    {
        $llm = new ScriptedLlmClient();
        $this->boot($llm);
        $this->signIn();

        $this->get('/admin/ai');
        foreach (['01', '02', '03', '04', '05'] as $id) {
            self::assertSame(200, $this->get('/admin/ai/' . $id)->status, $id);
        }

        self::assertSame([], $llm->requests);
        self::assertSame([], $this->aiCalls->calls);
    }

    // ---------------------------------------------------------------- AC 24: formulář

    public function test_example_form_has_article_select_with_demo_and_articles(): void
    {
        $this->signIn();
        $this->addArticle5();

        $response = $this->get('/admin/ai/01');
        $body = $response->body;

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>01 – Perex na jedno kliknutí</h1>', $body);
        self::assertStringContainsString('<form method="post" action="/admin/ai/01">', $body);
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $body);
        self::assertStringContainsString('<label for="article">Článek</label>', $body);
        self::assertNotNull(self::findTag($body, 'select', ['name="article"', 'id="article"']), 'Chybí <select name="article" id="article">.');

        $options = self::options($body, 'article');
        self::assertSame(['demo', 'demo-injection', '5'], array_column($options, 'value'));
        self::assertSame(['Ukázkový článek (bez databáze)', 'Ukázka: článek s vloženým pokynem', 'Článek pět'], array_column($options, 'text'));
        self::assertSame('demo', self::selected($body, 'article'));
        self::assertMatchesRegularExpression('~<button\b[^>]*>\s*Spustit příklad\s*</button>~u', $body);
        self::assertNull(self::findTag($body, 'select', ['name="model"']), 'Model se volí jen u příkladu 05.');
    }

    public function test_translation_form_offers_model_choice(): void
    {
        $this->signIn();

        $body = $this->get('/admin/ai/05')->body;

        self::assertStringContainsString('<h1>05 – Překlad CZ → EN</h1>', $body);
        self::assertSame(
            ['claude-sonnet-5-5', 'claude-haiku-4-5-20251001'],
            array_column(self::options($body, 'model'), 'value'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingExamples(): iterable
    {
        // Plán 008 (záměrná regrese M6): 06 a 07 už existují; plán 009 (regrese M7): i 08; plán 010 (regrese M7b): i 09,
        // neexistující je nově 10.
        foreach (['00', '10', '1', 'abc'] as $id) {
            yield 'GET ' . $id => ['GET', '/admin/ai/' . $id];
            yield 'POST ' . $id => ['POST', '/admin/ai/' . $id];
        }
    }

    #[DataProvider('missingExamples')]
    public function test_unknown_example_is_404(string $method, string $path): void
    {
        $this->signIn();

        $response = $method === 'GET' ? $this->get($path) : $this->post($path, ['article' => 'demo']);

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Stránka nenalezena', $response->body);
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_put_is_405(): void
    {
        $this->signIn();

        $response = $this->kernel->handle(new Request('PUT', '/admin/ai/01', clientIp: '172.18.0.1'));

        self::assertSame(405, $response->status);
    }

    // ---------------------------------------------------------------- AC 25: spuštění (PRG)

    public function test_run_redirects_and_shows_result_exactly_once(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/01', ['article' => 'demo']);

        self::assertSame(303, $response->status);
        self::assertSame('/admin/ai/01', $response->headers['Location'] ?? null);
        self::assertCount(1, $this->aiCalls->calls);
        $call = $this->aiCalls->calls[0];
        self::assertSame(self::ADMIN_ID, $call->userId);
        self::assertSame('01', $call->exampleId);
        self::assertSame('fake', $call->provider);

        $page = $this->get('/admin/ai/01');
        $text = self::text($page->body);
        self::assertSame(200, $page->status);
        self::assertSame(1, substr_count($page->body, '<h2 id="vysledek">Výsledek</h2>'));
        self::assertStringContainsString('Perex', $text);
        self::assertStringContainsString(
            sprintf(
                'Model claude-sonnet-5-5 · falešný klient · volání 1 · tokeny vstup %s / výstup %s · cena ',
                czech_number($call->usage->input),
                czech_number($call->usage->output),
            ),
            $text,
        );
        self::assertMatchesRegularExpression('~· cena [0-9 ]+(,[0-9]+)? USD~u', $text);
        self::assertStringContainsString('Falešný klient: cena je jen orientační, nic se neúčtovalo.', $text);
        self::assertMatchesRegularExpression('~<details\b[^>]*>\s*<summary\b[^>]*>\s*Surová odpověď modelu~u', $page->body);
        self::assertSame(1, count($this->aiCalls->calls), 'GET nesmí volat AI.');

        $again = $this->get('/admin/ai/01');
        self::assertStringNotContainsString('id="vysledek"', $again->body);
    }

    public function test_result_of_other_example_is_not_shown(): void
    {
        $this->signIn();
        $this->post('/admin/ai/01', ['article' => 'demo']);

        self::assertStringNotContainsString('id="vysledek"', $this->get('/admin/ai/02')->body);
    }

    public function test_translation_runs_with_chosen_cheap_model(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/05', ['article' => 'demo', 'model' => 'claude-haiku-4-5-20251001']);

        self::assertSame(303, $response->status);
        self::assertSame('claude-haiku-4-5-20251001', $this->aiCalls->calls[0]->model ?? null);
    }

    public function test_translation_with_unknown_model_is_422(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/05', ['article' => 'demo', 'model' => 'gpt-4o']);

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, 'Vyberte model ze seznamu.');
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_translation_without_model_field_uses_default_model(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/05', ['article' => 'demo']);

        self::assertSame(303, $response->status);
        self::assertSame('claude-sonnet-5-5', $this->aiCalls->calls[0]->model ?? null);
    }

    /** @return iterable<string, array{array<string, string>, array<string, list<string>>}> */
    public static function malformedModels(): iterable
    {
        yield 'empty string' => [['model' => ''], []];
        yield 'array model[]=x' => [[], ['model' => ['claude-haiku-4-5-20251001']]];
        yield 'empty array model[]' => [[], ['model' => []]];
    }

    /**
     * @param array<string, string> $body
     * @param array<string, list<string>> $lists
     */
    #[DataProvider('malformedModels')]
    public function test_translation_with_malformed_model_is_422(array $body, array $lists): void
    {
        $llm = new ScriptedLlmClient();
        $this->boot($llm);
        $this->signIn();

        $response = $this->post('/admin/ai/05', ['article' => 'demo'] + $body, lists: $lists);

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, 'Vyberte model ze seznamu.');
        self::assertStringContainsString('<form method="post" action="/admin/ai/05">', $response->body);
        self::assertSame('demo', self::selected($response->body, 'article'));
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->aiCalls->calls);
    }

    /** Plán 007, AC 17: `model[][]=x` z prohlížeče (přes Request::fromGlobals) je neplatná volba, ne výchozí model. */
    public function test_translation_with_nested_model_array_from_globals_is_422(): void
    {
        $llm = new ScriptedLlmClient();
        $this->boot($llm);
        $this->signIn();
        $server = $_SERVER;
        $post = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/admin/ai/05';
            $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
            $_POST = ['_csrf' => $this->csrf(), 'article' => 'demo', 'model' => [['x']]];

            $response = $this->kernel->handle(Request::fromGlobals());
        } finally {
            $_SERVER = $server;
            $_POST = $post;
        }

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, 'Vyberte model ze seznamu.');
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_model_field_is_ignored_for_examples_without_model_choice(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/01', ['article' => 'demo'], lists: ['model' => ['x']]);

        self::assertSame(303, $response->status);
        self::assertSame('claude-sonnet-5-5', $this->aiCalls->calls[0]->model ?? null);
    }

    public function test_form_after_redirect_is_prefilled_with_used_article_and_model(): void
    {
        $this->signIn();
        $this->addArticle5();

        $response = $this->post('/admin/ai/05', ['article' => '5', 'model' => 'claude-haiku-4-5-20251001']);
        self::assertSame(303, $response->status);

        $page = $this->get('/admin/ai/05')->body;
        self::assertStringContainsString('id="vysledek"', $page);
        self::assertSame('5', self::selected($page, 'article'));
        self::assertSame('claude-haiku-4-5-20251001', self::selected($page, 'model'));

        // Výsledek se zobrazí jen jednou; pak už formulář nabízí výchozí volby.
        $again = $this->get('/admin/ai/05')->body;
        self::assertSame('demo', self::selected($again, 'article'));
        self::assertSame('claude-sonnet-5-5', self::selected($again, 'model'));
    }

    public function test_form_after_redirect_keeps_demo_injection_article(): void
    {
        $this->signIn();

        self::assertSame(303, $this->post('/admin/ai/01', ['article' => 'demo-injection'])->status);

        self::assertSame('demo-injection', self::selected($this->get('/admin/ai/01')->body, 'article'));
    }

    public function test_stashed_choices_outside_options_fall_back_to_defaults(): void
    {
        $this->signIn();
        $this->addArticle5();
        self::assertSame(303, $this->post('/admin/ai/05', ['article' => '5', 'model' => 'claude-haiku-4-5-20251001'])->status);

        // Session je nedůvěryhodná: podvržené volby se neodrazí, formulář dostane výchozí hodnoty.
        $stored = json_decode((string) $this->session->data['ai_result'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        $stored['article'] = '"><script>alert(1)</script>';
        $stored['model'] = 'gpt-4o" onfocus="alert(1)';
        $this->session->data['ai_result'] = json_encode($stored, JSON_THROW_ON_ERROR);

        $page = $this->get('/admin/ai/05')->body;
        self::assertStringContainsString('id="vysledek"', $page);
        self::assertSame('demo', self::selected($page, 'article'));
        self::assertSame('claude-sonnet-5-5', self::selected($page, 'model'));
        self::assertStringNotContainsString('<script>alert', $page);
        self::assertStringNotContainsString('gpt-4o', $page);
    }

    public function test_deleted_article_falls_back_to_demo_after_redirect(): void
    {
        $this->signIn();
        $this->addArticle5();
        self::assertSame(303, $this->post('/admin/ai/01', ['article' => '5'])->status);
        $this->articles->summaries = [];

        $page = $this->get('/admin/ai/01')->body;

        self::assertStringContainsString('id="vysledek"', $page);
        self::assertSame('demo', self::selected($page, 'article'));
    }

    // ---------------------------------------------------------------- AC 26: chyby

    public function test_invalid_article_is_422_with_form(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/01', ['article' => 'abc']);

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, 'Vyberte článek.');
        self::assertStringContainsString('<form method="post" action="/admin/ai/01">', $response->body);
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_missing_article_field_is_422(): void
    {
        $this->signIn();

        $response = $this->post('/admin/ai/01');

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, 'Vyberte článek.');
    }

    public function test_exceeded_budget_is_429_with_message_and_preselected_article(): void
    {
        $this->signIn();
        $this->aiCalls->add(InMemoryAiCallRepository::call('2026-10-03 09:00:00', new TokenUsage(199000, 900), 1.0));

        $response = $this->post('/admin/ai/01', ['article' => 'demo-injection']);

        self::assertSame(429, $response->status);
        self::assertStringContainsString(
            'Denní limit AI tokenů (200 000) by byl překročen: dnes použito 199 900, požadavek si rezervuje až 400.'
            . ' Zkuste to zítra nebo zvyšte AI_DENNI_LIMIT_TOKENU.',
            self::text($response->body),
        );
        self::assertStringContainsString('<form method="post" action="/admin/ai/01">', $response->body);
        self::assertSame('demo-injection', self::selected($response->body, 'article'));
        self::assertCount(1, $this->aiCalls->calls, 'Odmítnuté volání se neloguje.');
    }

    public function test_llm_call_failure_is_502_with_error_message_without_key(): void
    {
        $transport = new ScriptedHttpTransport()->push(ScriptedHttpTransport::json(
            401,
            ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']],
            ['request-id' => 'req_401'],
        ));
        $this->boot(config: AiFixtures::config(AiProvider::Anthropic, self::SECRET), transport: $transport);
        $this->signIn();

        $response = $this->post('/admin/ai/01', ['article' => 'demo']);

        self::assertSame(502, $response->status);
        self::assertAlert($response->body, 'AI odmítla API klíč (401). Zkontrolujte ANTHROPIC_API_KEY v .env.');
        self::assertStringNotContainsString(self::SECRET, $response->body);
        self::assertSame('demo', self::selected($response->body, 'article'));
        self::assertCount(1, $transport->requests);
        self::assertCount(1, $this->aiCalls->calls);
        self::assertSame(AiCallStatus::Error, $this->aiCalls->calls[0]->status);
        self::assertSame('authentication', $this->aiCalls->calls[0]->errorType);
        self::assertSame('anthropic', $this->aiCalls->calls[0]->provider);
        self::assertStringContainsString('AnthropicClient: authentication (HTTP 401', $this->errorLog());
        self::assertStringNotContainsString(self::SECRET, $this->errorLog());
    }

    public function test_invalid_model_output_is_502_with_form_and_preselected_article(): void
    {
        $llm = new ScriptedLlmClient()->pushText('');
        $this->boot($llm);
        $this->signIn();
        $this->addArticle5();

        $response = $this->post('/admin/ai/01', ['article' => '5']);

        self::assertSame(502, $response->status);
        self::assertMatchesRegularExpression('~role="alert"~u', $response->body);
        self::assertStringContainsString('<form method="post" action="/admin/ai/01">', $response->body);
        self::assertSame('5', self::selected($response->body, 'article'));
        self::assertStringNotContainsString('id="vysledek"', $response->body);
    }

    // ---------------------------------------------------------------- AC 27: escapování

    public function test_article_title_and_model_output_are_escaped(): void
    {
        $llm = new ScriptedLlmClient()->pushText('<img src=x onerror=alert(1)>');
        $this->boot($llm);
        $this->signIn();
        $this->addArticle5('<script>alert(1)</script>');

        $form = $this->get('/admin/ai/01')->body;
        self::assertStringContainsString('&lt;script&gt;', $form);
        self::assertStringNotContainsString('<script>alert', $form);

        self::assertSame(303, $this->post('/admin/ai/01', ['article' => '5'])->status);
        $result = $this->get('/admin/ai/01')->body;

        self::assertStringContainsString('id="vysledek"', $result);
        self::assertStringContainsString('&lt;img', $result);
        self::assertStringNotContainsString('<img src=x', $result);
        self::assertStringNotContainsString('<script>alert', $result);
    }

    // ---------------------------------------------------------------- AC 28 a 33: rozcestník, CSRF ve formulářích

    public function test_dashboard_links_to_ai_tools(): void
    {
        $this->signIn();

        self::assertStringContainsString('<a href="/admin/ai">AI nástroje</a>', $this->get('/admin')->body);
    }

    public function test_every_post_form_on_ai_pages_has_csrf_field(): void
    {
        $this->signIn();

        foreach (['/admin/ai', '/admin/ai/01', '/admin/ai/05'] as $path) {
            $response = $this->get($path);
            self::assertSame(200, $response->status, $path);
            $body = $response->body;
            self::assertMatchesRegularExpression('~<form\b[^>]*method="post"~u', $body, $path . ': stránka bez POST formuláře');
            preg_match_all('~<form\b[^>]*method="post"[^>]*>(.*?)</form>~su', $body, $forms);
            foreach ($forms[1] as $index => $form) {
                self::assertStringContainsString('name="_csrf"', $form, sprintf('%s: formulář %d bez _csrf', $path, $index));
            }
        }
    }
}
