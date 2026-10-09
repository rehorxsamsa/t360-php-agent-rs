<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Ai\AiConfig;
use App\Ai\Embedding\EmbeddingClient;
use App\Ai\Embedding\FakeEmbeddingClient;
use App\Ai\Examples\ExampleRegistry;
use App\Ai\LlmClient;
use App\Ai\LlmErrorType;
use App\Ai\LlmResponse;
use App\Ai\Rag\ArticleIndexer;
use App\Domain\Ai\TokenUsage;
use App\Http\Kernel;
use App\Http\Request;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\EmbeddingFixtures;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\InMemoryArticleEmbeddingRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\ScriptedEmbeddingClient;
use App\Tests\Unit\Support\ScriptedLlmClient;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plán 009, AC 23–28: příklad 08 přes Kernel (přístup, stránka s indexem, indexace a dotaz přes PRG, chyby,
 * escapování, přehled). Čas 2026-10-08 12:00 (Europe/Prague), články z kontraktu InMemoryArticleEmbeddingRepository,
 * výchozí klienti falešní (FakeEmbeddingClient, MeteredLlmClient nad FakeLlmClient), admin id 7.
 */
final class AdminAiSemanticSearchTest extends AdminAiM7TestCase
{
    private const string PATH = '/admin/ai/08';
    private const string INDEX_PATH = '/admin/ai/08/indexace';
    private const string DEMO_QUESTION = 'Jak spánek ovlivňuje paměť?';
    private const string STUDY_URL = '/clanek/nova-studie-o-spanku';

    private InMemoryArticleEmbeddingRepository $embeddings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->embeddings = InMemoryArticleEmbeddingRepository::contract();
        $this->bootRag();
    }

    private function bootRag(?LlmClient $llm = null, ?EmbeddingClient $embeddingClient = null, ?AiConfig $config = null): void
    {
        $this->container = TestContainer::create(
            $this->session,
            $this->users,
            new InMemoryAuditLogRepository(),
            articles: $this->articles,
            clock: FixedClock::at('2026-10-08 12:00:00'),
            adminArticles: $this->adminArticles,
            aiCalls: $this->aiCalls,
            llmClient: $llm,
            aiConfig: $config,
            embeddings: $this->embeddings,
            embeddingClient: $embeddingClient ?? new FakeEmbeddingClient(),
        );
        $this->kernel = $this->container->get(Kernel::class);
    }

    private function indexAll(): void
    {
        $this->container->get(ArticleIndexer::class)->update();
    }

    private function textarea(string $html): string
    {
        $textarea = self::element($html, 'textarea', ['name' => 'question', 'id' => 'question']);
        self::assertNotNull($textarea, 'Chybí <textarea name="question" id="question">.');

        return $textarea;
    }

    private function assertFormWithQuestion(string $html, string $question): void
    {
        $form = self::element($html, 'form', ['method' => 'post', 'action' => self::PATH]);
        self::assertNotNull($form, 'Chybí <form method="post" action="/admin/ai/08">.');
        self::assertStringContainsString('name="_csrf"', $form);
        self::assertStringContainsString(e($question), $this->textarea($form));
    }

    private static function assertStatus(string $html, string $message): void
    {
        self::assertMatchesRegularExpression(
            '~role="status"[^>]*>(?:(?!</(?:div|p|section)>).)*?' . preg_quote(e($message), '~') . '~su',
            $html,
            'Chybí role="status" se zprávou: ' . $message,
        );
    }

    // ---------------------------------------------------------------- AC 23: přístup

    public function test_anonymous_get_redirects_to_login(): void
    {
        self::assertRedirectsToLogin($this->get(self::PATH));
    }

    /** @return iterable<string, array{string}> */
    public static function postPaths(): iterable
    {
        yield 'ask' => [self::PATH];
        yield 'reindex' => [self::INDEX_PATH];
    }

    #[DataProvider('postPaths')]
    public function test_anonymous_post_with_valid_token_redirects_to_login_without_calling_ai(string $path): void
    {
        $llm = new ScriptedLlmClient();
        $client = ScriptedEmbeddingClient::delegatingTo(new FakeEmbeddingClient());
        $this->bootRag($llm, $client);

        $response = $this->post($path, ['question' => self::DEMO_QUESTION]);

        self::assertRedirectsToLogin($response, $path);
        self::assertSame(0, $client->calls());
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->embeddings->saved);
    }

    #[DataProvider('postPaths')]
    public function test_signed_in_post_without_or_with_wrong_token_is_403_without_calling_ai(string $path): void
    {
        $llm = new ScriptedLlmClient();
        $client = ScriptedEmbeddingClient::delegatingTo(new FakeEmbeddingClient());
        $this->bootRag($llm, $client);
        $this->signIn();

        $missing = $this->post($path, ['question' => self::DEMO_QUESTION], withToken: false);
        $wrong = $this->post($path, ['question' => self::DEMO_QUESTION], token: str_repeat('0', 64));

        self::assertSame(403, $missing->status, $path);
        self::assertSame(403, $wrong->status, $path);
        self::assertSame(0, $client->calls());
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->embeddings->saved);
    }

    public function test_get_on_reindex_endpoint_is_405(): void
    {
        $this->signIn();

        self::assertSame(405, $this->get(self::INDEX_PATH)->status);
    }

    /** Plán 010 (záměrná regrese M7b): 09 už existuje, neexistující je nově 10. */
    public function test_example_10_is_404(): void
    {
        $this->signIn();

        self::assertSame(404, $this->get('/admin/ai/10')->status);
        self::assertSame(404, $this->post('/admin/ai/10', ['question' => self::DEMO_QUESTION])->status);
    }

    // ---------------------------------------------------------------- AC 24: stránka

    public function test_page_shows_index_status_forms_and_never_calls_ai(): void
    {
        $llm = new ScriptedLlmClient();
        $client = ScriptedEmbeddingClient::delegatingTo(new FakeEmbeddingClient());
        $this->embeddings->setVector('nova-studie-o-spanku', EmbeddingFixtures::unit(1));
        $this->embeddings->setVector('docker-pro-vyvojare', EmbeddingFixtures::unit(2));
        $this->bootRag($llm, $client);
        $this->signIn();

        $response = $this->get(self::PATH);
        $body = $response->body;
        $text = self::text($body);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>08 – Sémantické vyhledávání (RAG)</h1>', $body);
        self::assertStringContainsString('<h2>Index článků</h2>', $body);
        self::assertStringContainsString('Index: 2 z 3 publikovaných článků je aktuálních (model fake-hash-768, falešný klient).', $text);

        $indexForm = self::element($body, 'form', ['method' => 'post', 'action' => self::INDEX_PATH]);
        self::assertNotNull($indexForm, 'Chybí <form method="post" action="/admin/ai/08/indexace">.');
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $indexForm);
        self::assertMatchesRegularExpression('~<button\b[^>]*>\s*Aktualizovat index\s*</button>~u', $indexForm);

        $askForm = self::element($body, 'form', ['method' => 'post', 'action' => self::PATH]);
        self::assertNotNull($askForm, 'Chybí <form method="post" action="/admin/ai/08">.');
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $askForm);
        self::assertStringContainsString('<label for="question">Otázka</label>', $askForm);
        self::assertStringContainsString(self::DEMO_QUESTION, $this->textarea($askForm));
        self::assertMatchesRegularExpression('~<button\b[^>]*>\s*Najít a odpovědět\s*</button>~u', $askForm);
        self::assertStringContainsString('Odpovídá jen z publikovaných článků a cituje je.', $text);
        self::assertStringNotContainsString('id="vysledek"', $body);

        self::assertSame(0, $client->calls(), 'GET nesmí volat embeddingy.');
        self::assertSame([], $llm->requests, 'GET nesmí volat LLM.');
        self::assertSame([], $this->embeddings->saved);
    }

    public function test_page_with_empty_index_says_so(): void
    {
        $this->signIn();

        $text = self::text($this->get(self::PATH)->body);

        self::assertStringContainsString('Index je prázdný.', $text);
        self::assertStringNotContainsString('je aktuálních', $text);
    }

    // ---------------------------------------------------------------- AC 25: indexace (PRG)

    public function test_reindex_redirects_and_shows_flash_exactly_once(): void
    {
        $this->signIn();

        $response = $this->post(self::INDEX_PATH);

        self::assertSame(303, $response->status);
        self::assertSame(self::PATH, $response->headers['Location'] ?? null);
        self::assertSame(['docker-pro-vyvojare', 'nova-studie-o-spanku', 'planovany-clanek'], $this->embeddings->indexedSlugs());
        self::assertSame([], $this->aiCalls->calls, 'Embeddingy se do ai_calls nelogují.');

        $page = $this->get(self::PATH)->body;
        self::assertStatus($page, 'Index aktualizován: zaindexováno 3, odebráno 0, čeká 0 (model fake-hash-768).');
        self::assertStringContainsString('Index: 3 z 3 publikovaných článků je aktuálních', self::text($page));

        $again = $this->get(self::PATH)->body;
        self::assertStringNotContainsString('Index aktualizován', $again);
    }

    public function test_reindex_failure_redirects_with_error_flash(): void
    {
        $client = new ScriptedEmbeddingClient();
        $client->push(EmbeddingFixtures::failed());
        $this->bootRag(embeddingClient: $client);
        $this->signIn();

        $response = $this->post(self::INDEX_PATH);

        self::assertSame(303, $response->status);
        self::assertSame(self::PATH, $response->headers['Location'] ?? null);
        self::assertStringContainsString(
            'Indexace selhala: Služba embeddingů (Ollama) neodpovídá na http://ollama:11434 – spusťte ji: make ai-local.',
            self::text($this->get(self::PATH)->body),
        );
    }

    // ---------------------------------------------------------------- AC 26: dotaz (PRG)

    public function test_ask_redirects_and_shows_result_exactly_once(): void
    {
        $this->indexAll();
        $this->signIn();
        $question = 'Proč je spánek důležitý pro paměť?';

        $response = $this->post(self::PATH, ['question' => $question]);

        self::assertSame(303, $response->status);
        self::assertSame(self::PATH, $response->headers['Location'] ?? null);
        self::assertCount(1, $this->aiCalls->calls);
        self::assertSame('08', $this->aiCalls->calls[0]->exampleId);
        self::assertSame(self::ADMIN_ID, $this->aiCalls->calls[0]->userId);
        self::assertSame('fake', $this->aiCalls->calls[0]->provider);

        $page = $this->get(self::PATH);
        $body = $page->body;
        $text = self::text($body);
        self::assertSame(200, $page->status);
        self::assertSame(1, substr_count($body, '<h2 id="vysledek">Výsledek</h2>'));
        $positions = [];
        foreach (['Otázka', 'Odpověď', 'Nalezené články', 'Citace [1]', 'Zdroje', 'Embedding dotazu'] as $label) {
            $position = strpos($text, $label, (int) strpos($text, 'Výsledek'));
            self::assertNotFalse($position, 'Ve výsledku chybí pole ' . $label);
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'Pole výsledku musí být v pořadí AC 13.');
        self::assertStringContainsString('[1]', $text);
        self::assertStringContainsString(self::STUDY_URL, $text);
        self::assertMatchesRegularExpression('~vzdálenost 0,\d{3}~u', $text);
        self::assertMatchesRegularExpression('~model fake-hash-768 · falešný klient · \d+ tokenů · \d+ ms~u', $text);
        self::assertMatchesRegularExpression('~ · volání 1 · ~u', $text);
        self::assertStringContainsString(e($question), $this->textarea($body), 'Otázka má být předvyplněná.');
        self::assertCount(1, $this->aiCalls->calls, 'GET nesmí volat AI.');

        $again = $this->get(self::PATH);
        self::assertStringNotContainsString('id="vysledek"', $again->body);
    }

    public function test_result_of_other_example_is_not_shown(): void
    {
        $this->signIn();
        self::assertSame(303, $this->post('/admin/ai/07', ['question' => 'Co redakce píše o Dockeru?'])->status);

        self::assertStringNotContainsString('id="vysledek"', $this->get(self::PATH)->body);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidQuestions(): iterable
    {
        yield 'too short' => [['question' => 'ab']];
        yield 'too long' => [['question' => str_repeat('ř', 501)]];
        yield 'missing' => [[]];
    }

    /** @param array<string, string> $body */
    #[DataProvider('invalidQuestions')]
    public function test_invalid_question_is_422_with_alert_and_form(array $body): void
    {
        $llm = new ScriptedLlmClient();
        $client = ScriptedEmbeddingClient::delegatingTo(new FakeEmbeddingClient());
        $this->bootRag($llm, $client);
        $this->signIn();

        $response = $this->post(self::PATH, $body);

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, 'Zadejte otázku (3–500 znaků).');
        if (isset($body['question'])) {
            $this->assertFormWithQuestion($response->body, $body['question']);
        }
        self::assertSame(0, $client->calls());
        self::assertSame([], $llm->requests);
    }

    public function test_embedding_failure_is_503_with_message_and_form(): void
    {
        $client = new ScriptedEmbeddingClient();
        $client->push(EmbeddingFixtures::failed());
        $llm = new ScriptedLlmClient();
        $this->bootRag($llm, $client);
        $this->signIn();

        $response = $this->post(self::PATH, ['question' => 'Co víte o spánku?']);

        self::assertSame(503, $response->status);
        self::assertAlert($response->body, e('Služba embeddingů (Ollama) neodpovídá na http://ollama:11434 – spusťte ji: make ai-local.'));
        $this->assertFormWithQuestion($response->body, 'Co víte o spánku?');
        self::assertSame([], $llm->requests);
    }

    public function test_budget_exceeded_is_429_with_form(): void
    {
        $this->indexAll();
        $this->aiCalls->add(InMemoryAiCallRepository::call('2026-10-08 09:00:00', new TokenUsage(199000, 900), 1.0));
        $this->signIn();

        $response = $this->post(self::PATH, ['question' => self::DEMO_QUESTION]);

        self::assertSame(429, $response->status);
        self::assertStringContainsString('Denní limit AI tokenů (200 000) by byl překročen', self::text($response->body));
        $this->assertFormWithQuestion($response->body, self::DEMO_QUESTION);
        self::assertCount(1, $this->aiCalls->calls, 'Odmítnuté volání se neloguje.');
    }

    public function test_llm_call_failure_is_502_with_form(): void
    {
        $this->indexAll();
        $llm = new ScriptedLlmClient();
        $llm->push(AiFixtures::llmCallFailed(LlmErrorType::Overloaded, 529, 3));
        $this->bootRag($llm);
        $this->signIn();

        $response = $this->post(self::PATH, ['question' => self::DEMO_QUESTION]);

        self::assertSame(502, $response->status);
        self::assertAlert($response->body, LlmErrorType::Overloaded->userMessage());
        $this->assertFormWithQuestion($response->body, self::DEMO_QUESTION);
        self::assertStringNotContainsString('id="vysledek"', $response->body);
    }

    public function test_invalid_model_output_is_502_with_form(): void
    {
        $this->indexAll();
        $llm = new ScriptedLlmClient();
        $llm->push(AiFixtures::finalResponse(''));
        $this->bootRag($llm);
        $this->signIn();

        $response = $this->post(self::PATH, ['question' => self::DEMO_QUESTION]);

        self::assertSame(502, $response->status);
        self::assertMatchesRegularExpression('~role="alert"~u', $response->body);
        $this->assertFormWithQuestion($response->body, self::DEMO_QUESTION);
    }

    // ---------------------------------------------------------------- AC 27: escapování

    public function test_article_title_cited_text_and_question_are_escaped(): void
    {
        $this->embeddings = new InMemoryArticleEmbeddingRepository();
        $this->embeddings->addArticle('xss-clanek', '<script>alert(1)</script>', 'Spánek ovlivňuje paměť.', publishedAt: '2026-09-20 08:00:00');
        $this->embeddings->setVector('xss-clanek', EmbeddingFixtures::unit(0));
        $client = new ScriptedEmbeddingClient();
        $client->push(EmbeddingFixtures::result([EmbeddingFixtures::unit(0)]));
        $llm = new ScriptedLlmClient();
        $llm->push(new LlmResponse(
            text: 'Odpověď <b>tučně</b>.',
            model: AiFixtures::SONNET,
            stopReason: 'end_turn',
            usage: new TokenUsage(100, 10),
            provider: 'fake',
            costUsd: 0.0003,
            content: [[
                'type' => 'text',
                'text' => 'Odpověď <b>tučně</b>.',
                'citations' => [[
                    'type' => 'search_result_location',
                    'source' => '/clanek/xss-clanek',
                    'title' => '<script>alert(1)</script>',
                    'cited_text' => '<img src=x onerror=alert(1)>',
                    'search_result_index' => 0,
                    'start_block_index' => 0,
                    'end_block_index' => 1,
                ]],
            ]],
        ));
        $this->bootRag($llm, $client);
        $this->signIn();

        self::assertSame(303, $this->post(self::PATH, ['question' => '<i>Otázka</i> o spánku'])->status);
        $body = $this->get(self::PATH)->body;

        self::assertStringContainsString('id="vysledek"', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringContainsString('&lt;img', $body);
        self::assertStringContainsString('&lt;i&gt;Otázka', $body);
        self::assertStringNotContainsString('<script>alert', $body);
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringNotContainsString('<b>tučně', $body);
        self::assertStringNotContainsString('<i>Otázka', $body);
    }

    // ---------------------------------------------------------------- AC 28: přehled

    public function test_overview_lists_examples_01_to_08_with_descriptions(): void
    {
        $this->signIn();

        $body = $this->get('/admin/ai')->body;

        $titles = [
            '01' => 'Perex na jedno kliknutí',
            '02' => 'SEO titulek a meta popis',
            '03' => 'Štítky a rubrika',
            '04' => 'Kontrola před publikací',
            '05' => 'Překlad CZ → EN',
            '06' => 'Asistent psaní',
            '07' => 'Zeptej se redakce',
            '08' => 'Sémantické vyhledávání (RAG)',
        ];
        foreach ($titles as $id => $title) {
            self::assertStringContainsString(sprintf('<a href="/admin/ai/%s">%s – %s</a>', $id, $id, e($title)), $body);
        }
        $listing = $this->container->get(ExampleRegistry::class)->listing();
        // Plán 010 (záměrná regrese M7b): přehled má nově i 09 (ověřuje AdminAiEditorTest).
        self::assertCount(9, $listing);
        foreach ($listing as $example) {
            self::assertStringContainsString(e($example->description()), $body, 'Popis příkladu ' . $example->id());
        }
    }

    // ---------------------------------------------------------------- AC 36: CSRF ve formulářích

    public function test_every_post_form_on_page_has_csrf_field(): void
    {
        $this->signIn();

        $response = $this->get(self::PATH);
        preg_match_all('~<form\b[^>]*method="post"[^>]*>(.*?)</form>~su', $response->body, $forms);

        self::assertSame(200, $response->status);
        self::assertGreaterThanOrEqual(2, count($forms[1]), 'Stránka má formulář indexace i dotazu.');
        foreach ($forms[1] as $index => $form) {
            self::assertStringContainsString('name="_csrf"', $form, sprintf('formulář %d bez _csrf', $index));
        }
    }

    public function test_misspelled_reindex_path_is_404(): void
    {
        // Pojistka proti překlepu v trase: přesně /admin/ai/08/indexace.
        $this->signIn();

        $response = $this->kernel->handle(new Request('POST', '/admin/ai/08/index', body: ['_csrf' => $this->csrf()], clientIp: '172.18.0.1'));

        self::assertSame(404, $response->status);
        self::assertSame([], $this->embeddings->saved);
    }
}
