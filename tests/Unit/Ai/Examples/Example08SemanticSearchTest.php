<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiConfig;
use App\Ai\Client\FakeLlmClient;
use App\Ai\Embedding\EmbeddingClient;
use App\Ai\Embedding\EmbeddingFailed;
use App\Ai\Embedding\FakeEmbeddingClient;
use App\Ai\Examples\Example08SemanticSearch;
use App\Ai\Examples\ExampleDescription;
use App\Ai\Examples\ExampleResult;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\InvalidModelOutput;
use App\Ai\LlmCallFailed;
use App\Ai\LlmErrorType;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\PromptLibrary;
use App\Ai\Rag\ArticleIndexer;
use App\Container\Container;
use App\Domain\Ai\TokenUsage;
use App\Domain\Article\ArticleEmbeddingRepository;
use App\Domain\Article\ArticleStatus;
use App\Domain\Time\Clock;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\EmbeddingFixtures;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\InMemoryArticleEmbeddingRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\ScriptedEmbeddingClient;
use App\Tests\Unit\Support\ScriptedLlmClient;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 009, AC 10–17: příklad 08 – sémantické vyhledávání s citacemi. Příklad se skládá skutečným kontejnerem
 * nad kontraktem testovacích dat (InMemoryArticleEmbeddingRepository::contract, čas 2026-10-08 12:00 Europe/Prague).
 * Výchozí scénář: dotaz = unit(0), studie o spánku ve vzdálenosti 0,123, Docker 0,5, naplánovaný článek se shodným
 * vektorem (musí se odfiltrovat datem); všechny publikované mají aktuální vektor (bez varování o indexu).
 */
final class Example08SemanticSearchTest extends TestCase
{
    private const string QUESTION = 'Jak spánek ovlivňuje paměť?';
    private const string STUDY_TITLE = 'Nová studie: spánek ovlivňuje paměť víc, než se čekalo';
    private const string STUDY_URL = '/clanek/nova-studie-o-spanku';
    private const string DOCKER_URL = '/clanek/docker-pro-vyvojare';
    private const string NOTHING_FOUND = 'V publikovaných článcích jsem k tomu nic nenašel.';
    private const string NO_SOURCES = 'Žádné – odpověď necituje články.';
    private const string UNKNOWN_SOURCE = 'Model citoval neznámý zdroj, citace byla vynechána.';
    private const string NO_CITATION = 'Odpověď necituje žádný článek – ověřte ji ve zdrojích.';
    private const string CITED = 'Spánek ovlivňuje paměť víc, než se čekalo.';

    private ScriptedLlmClient $llm;
    private ScriptedEmbeddingClient $embeddings;
    private InMemoryArticleEmbeddingRepository $repository;
    private InMemoryAiCallRepository $aiCalls;
    private Container $container;

    protected function setUp(): void
    {
        $this->llm = new ScriptedLlmClient();
        $this->embeddings = new ScriptedEmbeddingClient();
        $this->repository = InMemoryArticleEmbeddingRepository::contract();
        $this->boot();
    }

    private function boot(?EmbeddingClient $embeddingClient = null, ?ScriptedLlmClient $llm = null): void
    {
        $this->aiCalls = new InMemoryAiCallRepository();
        $this->container = TestContainer::withoutSession(
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
            clock: FixedClock::at('2026-10-08 12:00:00'),
            aiCalls: $this->aiCalls,
            llmClient: $llm ?? $this->llm,
            embeddings: $this->repository,
            embeddingClient: $embeddingClient ?? $this->embeddings,
        );
    }

    private function example(): Example08SemanticSearch
    {
        return $this->container->get(Example08SemanticSearch::class);
    }

    /** Výchozí index: studie 0,123, Docker 0,5, naplánovaný (budoucí) shodný s dotazem; dotaz = unit(0). */
    private function indexDefault(): void
    {
        $this->repository->setVector('nova-studie-o-spanku', EmbeddingFixtures::atDistance(0.123));
        $this->repository->setVector('docker-pro-vyvojare', EmbeddingFixtures::atDistance(0.5));
        $this->repository->setVector('planovany-clanek', EmbeddingFixtures::unit(0));
        $this->pushQuery();
    }

    private function pushQuery(int $tokens = 7, int $durationMs = 3): void
    {
        $this->embeddings->push(EmbeddingFixtures::result([EmbeddingFixtures::unit(0)], tokens: $tokens, durationMs: $durationMs));
    }

    private static function prompt(): string
    {
        return (string) file_get_contents(AiFixtures::root() . '/src/Ai/Prompts/08-semantic-search.md');
    }

    /**
     * Odpověď modelu s bloky `content` (text odpovědi = spojení textových bloků).
     *
     * @param list<array<string, mixed>> $content
     */
    private static function cited(array $content, string $stopReason = 'end_turn', ?float $costUsd = 0.002): LlmResponse
    {
        $text = '';
        foreach ($content as $block) {
            $text .= is_string($block['text'] ?? null) ? $block['text'] : '';
        }

        return new LlmResponse(
            text: $text,
            model: AiFixtures::SONNET,
            stopReason: $stopReason,
            usage: new TokenUsage(1200, 40),
            provider: 'fake',
            costUsd: $costUsd,
            content: $content,
        );
    }

    /** @return array<string, mixed> */
    private static function citation(
        mixed $index = 0,
        string $source = self::STUDY_URL,
        string $citedText = self::CITED,
        int $start = 1,
        int $end = 2,
    ): array {
        return [
            'type' => 'search_result_location',
            'source' => $source,
            'title' => self::STUDY_TITLE,
            'cited_text' => $citedText,
            'search_result_index' => $index,
            'start_block_index' => $start,
            'end_block_index' => $end,
        ];
    }

    /**
     * Odpověď AC 13: „Podle studie “ + citující blok.
     *
     * @param array<string, mixed>|null $citation
     */
    private static function studyAnswer(?array $citation = null, string $stopReason = 'end_turn'): LlmResponse
    {
        return self::cited([
            ['type' => 'text', 'text' => 'Podle studie '],
            ['type' => 'text', 'text' => 'spánek ovlivňuje paměť.', 'citations' => [$citation ?? self::citation()]],
        ], $stopReason);
    }

    /** @return list<string> */
    private static function labels(ExampleResult $result): array
    {
        return array_map(static fn(array $field): string => $field['label'], $result->fields);
    }

    private static function field(ExampleResult $result, string $label): ?string
    {
        foreach ($result->fields as $field) {
            if ($field['label'] === $label) {
                return $field['value'];
            }
        }

        return null;
    }

    private function onlyRequest(): LlmRequest
    {
        self::assertCount(1, $this->llm->requests, 'Očekáváno právě jedno volání LLM.');

        return $this->llm->requests[0];
    }

    /** @return list<array<string, mixed>> */
    private static function userBlocks(LlmRequest $request): array
    {
        self::assertCount(1, $request->messages);
        self::assertSame('user', $request->messages[0]['role']);
        $content = $request->messages[0]['content'];
        self::assertIsArray($content);

        return $content;
    }

    /** @return list<array<string, mixed>> */
    private static function searchResults(LlmRequest $request): array
    {
        return array_values(array_filter(
            self::userBlocks($request),
            static fn(array $block): bool => ($block['type'] ?? null) === 'search_result',
        ));
    }

    /**
     * Texty bloků prvního výsledku hledání v požadavku.
     *
     * @return list<string>
     */
    private static function blockTexts(LlmRequest $request): array
    {
        $content = self::searchResults($request)[0]['content'] ?? null;
        self::assertIsArray($content);
        $texts = [];
        foreach ($content as $block) {
            self::assertIsArray($block);
            self::assertSame('text', $block['type'] ?? null);
            self::assertIsString($block['text'] ?? null);
            $texts[] = $block['text'];
        }

        return $texts;
    }

    // ---------------------------------------------------------------- popis

    public function test_describes_itself_as_example_08(): void
    {
        $example = $this->example();

        self::assertInstanceOf(ExampleDescription::class, $example);
        self::assertSame('08', $example->id());
        self::assertSame('Sémantické vyhledávání (RAG)', $example->title());
        self::assertNotSame('', trim($example->description()));
        self::assertSame('Jak spánek ovlivňuje paměť?', Example08SemanticSearch::DEMO_QUESTION);
        self::assertSame(3000, Example08SemanticSearch::SOURCE_CHAR_LIMIT);
        self::assertSame(12, Example08SemanticSearch::SOURCE_BLOCK_LIMIT);
        self::assertSame(1024, Example08SemanticSearch::MAX_TOKENS);
    }

    // ---------------------------------------------------------------- AC 10: vstup

    /** @return iterable<string, array{string}> */
    public static function invalidQuestions(): iterable
    {
        yield 'empty' => [''];
        yield 'two characters after trim' => ['  ab  '];
        yield 'whitespace only' => ["\n\t   "];
        yield '501 characters' => [str_repeat('ř', 501)];
    }

    #[DataProvider('invalidQuestions')]
    public function test_invalid_question_is_rejected_without_calling_embeddings_or_llm(string $question): void
    {
        try {
            $this->example()->ask($question, 7);
            self::fail('Očekávána výjimka InvalidExampleInput.');
        } catch (InvalidExampleInput $exception) {
            self::assertSame('Zadejte otázku (3–500 znaků).', $exception->getMessage());
        }

        self::assertSame(0, $this->embeddings->calls());
        self::assertSame([], $this->llm->requests);
    }

    public function test_question_boundaries_3_and_500_characters_are_accepted(): void
    {
        $this->indexDefault();
        $this->pushQuery();
        $this->llm->push(self::studyAnswer(), self::studyAnswer());

        $this->example()->ask('abc', 7);
        $this->example()->ask(str_repeat('ř', 500), 7);

        self::assertSame(['abc', str_repeat('ř', 500)], $this->embeddings->queries);
    }

    // ---------------------------------------------------------------- AC 11: vyhledání

    public function test_query_is_embedded_once_with_trimmed_question_and_searched_with_client_model(): void
    {
        $this->indexDefault();
        $this->llm->push(self::studyAnswer());

        $this->example()->ask("  " . self::QUESTION . "\n", 7);

        self::assertSame([self::QUESTION], $this->embeddings->queries);
        self::assertSame([], $this->embeddings->documentBatches);
        self::assertCount(1, $this->repository->nearestCalls);
        $call = $this->repository->nearestCalls[0];
        self::assertEquals(EmbeddingFixtures::unit(0), $call['query']);
        self::assertSame('fake-hash-768', $call['model']);
        self::assertEquals(FixedClock::at('2026-10-08 12:00:00')->now(), $call['now']);
        self::assertSame(3, $call['limit']);
    }

    public function test_sources_farther_than_max_distance_are_dropped(): void
    {
        $this->repository->setVector('nova-studie-o-spanku', EmbeddingFixtures::atDistance(0.123));
        $this->repository->setVector('docker-pro-vyvojare', EmbeddingFixtures::atDistance(0.96));
        $this->repository->setVector('planovany-clanek', EmbeddingFixtures::unit(0));
        $this->pushQuery();
        $this->llm->push(self::studyAnswer());

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertSame([self::STUDY_URL], array_column(self::searchResults($this->onlyRequest()), 'source'));
        self::assertStringNotContainsString(self::DOCKER_URL, (string) self::field($result, 'Nalezené články'));
    }

    public function test_max_distance_and_source_limit_are_constructor_parameters(): void
    {
        $this->indexDefault();
        $this->llm->push(self::studyAnswer());
        $example = new Example08SemanticSearch(
            $this->llm,
            $this->embeddings,
            $this->repository,
            $this->container->get(PromptLibrary::class),
            $this->container->get(AiConfig::class),
            $this->container->get(Clock::class),
            sourceLimit: 1,
            maxDistance: 0.2,
        );

        $example->ask(self::QUESTION, 7);

        self::assertSame(1, $this->repository->nearestCalls[0]['limit']);
        self::assertSame([self::STUDY_URL], array_column(self::searchResults($this->onlyRequest()), 'source'));
    }

    public function test_draft_archived_and_scheduled_articles_never_reach_sources_or_llm(): void
    {
        foreach (['druhy-koncept', 'archivni-clanek', 'planovany-clanek'] as $slug) {
            $this->repository->setVector($slug, EmbeddingFixtures::unit(0));
        }
        $this->repository->setVector('nova-studie-o-spanku', EmbeddingFixtures::atDistance(0.3));
        $this->pushQuery();
        $this->llm->push(self::studyAnswer());

        $result = $this->example()->ask(self::QUESTION, 7);

        $request = $this->onlyRequest();
        self::assertSame([self::STUDY_URL], array_column(self::searchResults($request), 'source'));
        $serialized = json_encode($request->messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . json_encode($result->fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['druhy-koncept', 'archivni-clanek', 'planovany-clanek', 'Druhý koncept', 'Archivní článek', 'Plánovaný článek'] as $hidden) {
            self::assertStringNotContainsString($hidden, $serialized);
        }
    }

    // ---------------------------------------------------------------- AC 12: požadavek na LLM

    public function test_prompt_file_states_grounding_data_and_no_answer_rules(): void
    {
        $prompt = self::prompt();

        self::assertNotSame('', trim($prompt));
        self::assertStringContainsString('V nalezených článcích odpověď není.', $prompt);
        self::assertMatchesRegularExpression('~jen z (výsledků hledání|nalezených)~u', $prompt);
        self::assertMatchesRegularExpression('~jsou data~u', $prompt);
        self::assertStringContainsString('neprovádějí', $prompt);
        self::assertMatchesRegularExpression('~česky~iu', $prompt);
        self::assertMatchesRegularExpression('~(5|pěti) vět~u', $prompt);
        self::assertDoesNotMatchRegularExpression('~sk-ant-~', $prompt);
    }

    public function test_request_shape_with_search_results_in_distance_order(): void
    {
        $this->indexDefault();
        $this->llm->push(self::studyAnswer());

        $this->example()->ask(self::QUESTION, 7);

        $request = $this->onlyRequest();
        self::assertSame(self::prompt(), $request->system);
        self::assertSame('claude-sonnet-5-5', $request->model);
        self::assertSame(1024, $request->maxTokens);
        self::assertSame('low', $request->effort);
        self::assertSame('08', $request->exampleId);
        self::assertSame(7, $request->userId);
        self::assertNull($request->tools);
        self::assertNull($request->jsonSchema);
        self::assertFalse($request->cacheSystem);
        self::assertEquals(
            [
                [
                    'type' => 'search_result',
                    'source' => self::STUDY_URL,
                    'title' => self::STUDY_TITLE,
                    'content' => [
                        ['type' => 'text', 'text' => 'Vědci popsali, jak spánek ovlivňuje paměť.'],
                        ['type' => 'text', 'text' => 'Spánek ovlivňuje paměť víc, než se čekalo.'],
                        ['type' => 'text', 'text' => 'Kdo spí málo, pamatuje si hůř a dělá víc chyb.'],
                    ],
                    'citations' => ['enabled' => true],
                ],
                [
                    'type' => 'search_result',
                    'source' => self::DOCKER_URL,
                    'title' => 'Docker pro vývojáře',
                    'content' => [['type' => 'text', 'text' => 'Docker sjednocuje prostředí.']],
                    'citations' => ['enabled' => true],
                ],
                ['type' => 'text', 'text' => 'Otázka: ' . self::QUESTION],
            ],
            self::userBlocks($request),
        );
        $citations = self::searchResults($request)[0]['citations'] ?? null;
        self::assertIsArray($citations);
        self::assertTrue($citations['enabled'] ?? null);
    }

    public function test_console_question_is_sent_without_user(): void
    {
        $this->indexDefault();
        $this->llm->push(self::studyAnswer());

        $this->example()->ask(self::QUESTION, null);

        self::assertNull($this->onlyRequest()->userId);
    }

    public function test_paragraphs_are_trimmed_and_empty_ones_skipped(): void
    {
        $this->repository = new InMemoryArticleEmbeddingRepository();
        $this->repository->addArticle('odstavce', 'Odstavce', "  První odstavec.  \n\n\n\n   \n\nDruhý\nřádek odstavce.\n\n", publishedAt: '2026-09-01 08:00:00');
        $this->repository->setVector('odstavce', EmbeddingFixtures::unit(0));
        $this->boot();
        $this->pushQuery();
        $this->llm->push(AiFixtures::finalResponse('V nalezených článcích odpověď není.'));

        $this->example()->ask(self::QUESTION, 7);

        self::assertEquals(
            [['type' => 'text', 'text' => 'První odstavec.'], ['type' => 'text', 'text' => "Druhý\nřádek odstavce."]],
            self::searchResults($this->onlyRequest())[0]['content'] ?? null,
        );
    }

    public function test_source_is_limited_to_12_blocks(): void
    {
        $paragraphs = [];
        for ($i = 1; $i <= 20; $i++) {
            $paragraphs[] = 'Odstavec ' . $i . '.';
        }
        $this->repository = new InMemoryArticleEmbeddingRepository();
        $this->repository->addArticle('mnoho', 'Mnoho odstavců', implode("\n\n", $paragraphs), excerpt: 'Perex.');
        $this->repository->setVector('mnoho', EmbeddingFixtures::unit(0));
        $this->boot();
        $this->pushQuery();
        $this->llm->push(AiFixtures::finalResponse('V nalezených článcích odpověď není.'));

        $this->example()->ask(self::QUESTION, 7);

        $texts = self::blockTexts($this->onlyRequest());
        self::assertCount(12, $texts);
        self::assertSame('Perex.', $texts[0]);
        self::assertSame('Odstavec 11.', $texts[11]);
    }

    public function test_source_is_limited_to_3000_characters_cut_on_word_boundary(): void
    {
        $paragraph = trim(str_repeat('Spánek ovlivňuje paměť a soustředění. ', 30));
        $this->repository = new InMemoryArticleEmbeddingRepository();
        $this->repository->addArticle('dlouhy', 'Dlouhý', implode("\n\n", array_fill(0, 5, $paragraph)), excerpt: 'Perex dlouhého článku.');
        $this->repository->setVector('dlouhy', EmbeddingFixtures::unit(0));
        $this->boot();
        $this->pushQuery();
        $this->llm->push(AiFixtures::finalResponse('V nalezených článcích odpověď není.'));

        $this->example()->ask(self::QUESTION, 7);

        $texts = self::blockTexts($this->onlyRequest());
        self::assertLessThanOrEqual(3000, array_sum(array_map('mb_strlen', $texts)));
        self::assertGreaterThan(2500, array_sum(array_map('mb_strlen', $texts)));
        $last = (string) end($texts);
        self::assertStringEndsWith('…', $last);
        $withoutEllipsis = rtrim(mb_substr($last, 0, -1));
        self::assertTrue(str_starts_with($paragraph, $withoutEllipsis), 'Poslední blok je začátek odstavce.');
        self::assertMatchesRegularExpression('~(^|\s)\S+$~u', $withoutEllipsis);
        $nextChar = mb_substr($paragraph, mb_strlen($withoutEllipsis), 1);
        self::assertContains($nextChar, [' ', ''], 'Zkrácení musí být na hranici slova.');
    }

    // ---------------------------------------------------------------- AC 13: odpověď s citacemi

    public function test_result_with_citation_has_fields_in_order(): void
    {
        $this->indexDefault();
        $this->llm->push(self::studyAnswer());

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertSame('08', $result->exampleId);
        self::assertSame(1, $result->calls);
        self::assertSame(['Otázka', 'Odpověď', 'Nalezené články', 'Citace [1]', 'Zdroje', 'Embedding dotazu'], self::labels($result));
        self::assertSame(self::QUESTION, self::field($result, 'Otázka'));
        self::assertSame('Podle studie spánek ovlivňuje paměť. [1]', self::field($result, 'Odpověď'));
        self::assertSame(
            '[1] ' . self::STUDY_TITLE . ' – ' . self::STUDY_URL . ' (vzdálenost 0,123)' . "\n"
            . '[2] Docker pro vývojáře – ' . self::DOCKER_URL . ' (vzdálenost 0,500)',
            self::field($result, 'Nalezené články'),
        );
        self::assertSame('„' . self::CITED . '“ – ' . self::STUDY_URL, self::field($result, 'Citace [1]'));
        self::assertSame(self::STUDY_URL, self::field($result, 'Zdroje'));
        self::assertMatchesRegularExpression('~^model fake-hash-768 · falešný klient · 7 tokenů · \d+ ms$~u', (string) self::field($result, 'Embedding dotazu'));
        self::assertSame('Podle studie spánek ovlivňuje paměť.', $result->rawOutput);
        self::assertSame([], $result->warnings);
        self::assertSame(1200, $result->usage->input);
        self::assertSame(40, $result->usage->output);
        self::assertEqualsWithDelta(0.002, $result->costUsd, 1e-12);
        self::assertSame('claude-sonnet-5-5', $result->model);
        self::assertSame('fake', $result->provider);
    }

    public function test_each_citing_block_gets_markers_once_per_number(): void
    {
        $this->indexDefault();
        $docker = self::citation(1, self::DOCKER_URL, 'Docker sjednocuje prostředí.', 0, 1);
        $this->llm->push(self::cited([
            ['type' => 'text', 'text' => 'Spánek pomáhá paměti'],
            ['type' => 'text', 'text' => ' a Docker sjednocuje prostředí.', 'citations' => [self::citation(), self::citation(0, start: 0, end: 1, citedText: 'Vědci popsali, jak spánek ovlivňuje paměť.'), $docker]],
            ['type' => 'text', 'text' => ' Konec.'],
        ]));

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertMatchesRegularExpression(
            '~^Spánek pomáhá paměti a Docker sjednocuje prostředí\. \[1\] ?\[2\] Konec\.$~u',
            (string) self::field($result, 'Odpověď'),
        );
        // Oddělovač adres není v plánu závazný (07 používá konec řádku) – rozhoduje pořadí první citace.
        self::assertSame([self::STUDY_URL, self::DOCKER_URL], preg_split('~\n|, ~', (string) self::field($result, 'Zdroje')));
        $citationLabels = array_values(array_filter(self::labels($result), static fn(string $label): bool => str_starts_with($label, 'Citace')));
        self::assertCount(3, $citationLabels, 'Jedno pole na unikátní dvojici zdroj + start_block_index.');
    }

    public function test_cited_text_is_cut_to_300_characters_and_at_most_10_citation_fields(): void
    {
        $long = str_repeat('Dlouhá citace o spánku. ', 30);
        $citations = [];
        for ($block = 0; $block < 12; $block++) {
            $citations[] = self::citation(0, citedText: $long, start: $block, end: $block + 1);
        }
        $this->indexDefault();
        $this->llm->push(self::cited([['type' => 'text', 'text' => 'Odpověď.', 'citations' => $citations]]));

        $result = $this->example()->ask(self::QUESTION, 7);

        $citationFields = array_values(array_filter($result->fields, static fn(array $field): bool => str_starts_with($field['label'], 'Citace')));
        self::assertCount(10, $citationFields);
        foreach ($citationFields as $field) {
            self::assertSame(1, preg_match('~^„(.*)“ – /clanek/nova-studie-o-spanku$~su', $field['value'], $match));
            self::assertLessThanOrEqual(300, mb_strlen($match[1] ?? ''));
        }
    }

    // ---------------------------------------------------------------- AC 14: kontrola citací a varování

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCitations(): iterable
    {
        yield 'index out of range' => [['search_result_index' => 5]];
        yield 'negative index' => [['search_result_index' => -1]];
        yield 'non-numeric index' => [['search_result_index' => 'abc']];
        yield 'missing index' => [['search_result_index' => null]];
        yield 'source not matching index' => [['source' => '/clanek/druhy-koncept']];
        yield 'source of other result' => [['source' => self::DOCKER_URL]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidCitations')]
    public function test_invalid_citation_is_dropped_with_warning(array $override): void
    {
        $this->indexDefault();
        $citation = array_merge(self::citation(), $override);
        if ($override === ['search_result_index' => null]) {
            unset($citation['search_result_index']);
        }
        $this->llm->push(self::studyAnswer($citation));

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertSame('Podle studie spánek ovlivňuje paměť.', self::field($result, 'Odpověď'));
        self::assertSame(self::NO_SOURCES, self::field($result, 'Zdroje'));
        self::assertNotContains('Citace [1]', self::labels($result));
        self::assertContains(self::UNKNOWN_SOURCE, $result->warnings);
        self::assertContains(self::NO_CITATION, $result->warnings);
        self::assertStringNotContainsString('druhy-koncept', json_encode($result->fields, JSON_THROW_ON_ERROR));
    }

    public function test_valid_citation_survives_next_to_invalid_one(): void
    {
        $this->indexDefault();
        $this->llm->push(self::cited([
            ['type' => 'text', 'text' => 'Spánek je důležitý.', 'citations' => [self::citation(9), self::citation()]],
        ]));

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertSame('Spánek je důležitý. [1]', self::field($result, 'Odpověď'));
        self::assertSame(self::STUDY_URL, self::field($result, 'Zdroje'));
        self::assertSame([self::UNKNOWN_SOURCE], $result->warnings);
    }

    public function test_answer_without_citations_warns(): void
    {
        $this->indexDefault();
        $this->llm->push(self::cited([['type' => 'text', 'text' => 'Spánek je důležitý.']]));

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertSame('Spánek je důležitý.', self::field($result, 'Odpověď'));
        self::assertSame(self::NO_SOURCES, self::field($result, 'Zdroje'));
        self::assertSame([self::NO_CITATION], $result->warnings);
        self::assertSame(['Otázka', 'Odpověď', 'Nalezené články', 'Zdroje', 'Embedding dotazu'], self::labels($result));
    }

    public function test_response_without_content_uses_text_without_markers(): void
    {
        $this->indexDefault();
        $this->llm->push(AiFixtures::response('Odpověď jen textem.'));

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertSame('Odpověď jen textem.', self::field($result, 'Odpověď'));
        self::assertSame(self::NO_SOURCES, self::field($result, 'Zdroje'));
        self::assertContains(self::NO_CITATION, $result->warnings);
    }

    public function test_outdated_index_warns_with_pending_count(): void
    {
        $this->indexDefault();
        $this->repository->updateArticle('docker-pro-vyvojare', body: 'Docker sjednocuje prostředí i nasazení.');
        $this->llm->push(self::studyAnswer());

        $result = $this->example()->ask(self::QUESTION, 7);

        $warnings = array_values(array_filter($result->warnings, static fn(string $warning): bool => str_starts_with($warning, 'Index není aktuální')));
        self::assertCount(1, $warnings);
        self::assertSame('Index není aktuální (1 článek čeká na indexaci) – výsledky nemusí odpovídat.', $warnings[0]);
    }

    /** @return iterable<string, array{int, string}> */
    public static function pendingCounts(): iterable
    {
        yield '1' => [1, '1 článek čeká'];
        yield '2' => [2, '2 články čekají'];
        yield '4' => [4, '4 články čekají'];
        yield '5' => [5, '5 článků čeká'];
        yield '11' => [11, '11 článků čeká'];
    }

    #[DataProvider('pendingCounts')]
    public function test_outdated_index_warning_uses_czech_plural_with_sources(int $pending, string $expected): void
    {
        $this->indexDefault();
        for ($i = 1; $i <= $pending; $i++) {
            $this->repository->addArticle('cekajici-' . $i, 'Čekající ' . $i, 'Text čekajícího článku.');
        }
        $this->llm->push(self::studyAnswer());

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertContains(
            sprintf('Index není aktuální (%s na indexaci) – výsledky nemusí odpovídat.', $expected),
            $result->warnings,
        );
    }

    #[DataProvider('pendingCounts')]
    public function test_outdated_index_warning_uses_czech_plural_when_nothing_found(int $pending, string $expected): void
    {
        $this->repository->setVector('nova-studie-o-spanku', EmbeddingFixtures::atDistance(0.96));
        $this->repository->setVector('docker-pro-vyvojare', EmbeddingFixtures::atDistance(1.0));
        $this->repository->setVector('planovany-clanek', EmbeddingFixtures::unit(0));
        for ($i = 1; $i <= $pending; $i++) {
            $this->repository->addArticle('cekajici-' . $i, 'Čekající ' . $i, 'Text čekajícího článku.');
        }
        $this->pushQuery();

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertSame([], $this->llm->requests);
        self::assertContains(
            sprintf('Index není aktuální (%s na indexaci) – výsledky nemusí odpovídat.', $expected),
            $result->warnings,
        );
    }

    public function test_max_tokens_stop_reason_warns_about_truncation(): void
    {
        $this->indexDefault();
        $this->llm->push(self::studyAnswer(stopReason: 'max_tokens'));

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertContains('Odpověď byla useknuta limitem max_tokens.', $result->warnings);
        self::assertSame('Podle studie spánek ovlivňuje paměť. [1]', self::field($result, 'Odpověď'));
    }

    /** @return iterable<string, array{LlmResponse}> */
    public static function unusableResponses(): iterable
    {
        yield 'refusal' => [AiFixtures::response('Nemohu odpovědět.', stopReason: 'refusal')];
        yield 'empty text' => [AiFixtures::finalResponse('')];
        yield 'whitespace text' => [AiFixtures::response("  \n ")];
    }

    #[DataProvider('unusableResponses')]
    public function test_refusal_or_empty_answer_is_invalid_model_output(LlmResponse $response): void
    {
        $this->indexDefault();
        $this->llm->push($response);

        $this->expectException(InvalidModelOutput::class);

        $this->example()->ask(self::QUESTION, 7);
    }

    // ---------------------------------------------------------------- AC 15: nic nenalezeno

    public function test_empty_index_answers_without_llm_and_warns(): void
    {
        $this->pushQuery();

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertSame([], $this->llm->requests);
        self::assertSame(0, $result->calls);
        self::assertSame(0, $result->usage->input);
        self::assertSame(0, $result->usage->output);
        self::assertSame(0.0, $result->costUsd);
        self::assertSame('claude-sonnet-5-5', $result->model);
        self::assertSame('fake', $result->provider);
        self::assertSame(self::NOTHING_FOUND, self::field($result, 'Odpověď'));
        self::assertSame(self::NO_SOURCES, self::field($result, 'Zdroje'));
        self::assertContains(
            'Index je prázdný – nejdřív ho aktualizujte (tlačítko Aktualizovat index nebo ai:indexuj).',
            $result->warnings,
        );
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_all_sources_too_far_answers_without_llm(): void
    {
        $this->repository->setVector('nova-studie-o-spanku', EmbeddingFixtures::atDistance(0.96));
        $this->repository->setVector('docker-pro-vyvojare', EmbeddingFixtures::atDistance(1.0));
        $this->repository->setVector('planovany-clanek', EmbeddingFixtures::unit(0));
        $this->pushQuery();

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertSame([], $this->llm->requests);
        self::assertSame(0, $result->calls);
        self::assertSame(self::NOTHING_FOUND, self::field($result, 'Odpověď'));
        self::assertSame(self::NO_SOURCES, self::field($result, 'Zdroje'));
        foreach ($result->warnings as $warning) {
            self::assertStringNotContainsString('Index je prázdný', $warning);
        }
    }

    // ---------------------------------------------------------------- AC 16: chyby

    public function test_embedding_failure_propagates_without_calling_llm(): void
    {
        $failure = EmbeddingFixtures::failed();
        $this->embeddings->push($failure);

        try {
            $this->example()->ask(self::QUESTION, 7);
            self::fail('Očekávána výjimka EmbeddingFailed.');
        } catch (EmbeddingFailed $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame([], $this->llm->requests);
    }

    public function test_budget_exceeded_propagates_unchanged(): void
    {
        $this->indexDefault();
        $exceeded = AiBudgetExceeded::forLimit(200000, 199500, 1024);
        $this->llm->push($exceeded);

        try {
            $this->example()->ask(self::QUESTION, 7);
            self::fail('Očekávána výjimka AiBudgetExceeded.');
        } catch (AiBudgetExceeded $exception) {
            self::assertSame($exceeded, $exception);
        }
    }

    public function test_llm_call_failure_propagates_unchanged(): void
    {
        $this->indexDefault();
        $failure = AiFixtures::llmCallFailed(LlmErrorType::Overloaded, 529, 3);
        $this->llm->push($failure);

        try {
            $this->example()->ask(self::QUESTION, 7);
            self::fail('Očekávána výjimka LlmCallFailed.');
        } catch (LlmCallFailed $exception) {
            self::assertSame($failure, $exception);
        }
    }

    // ---------------------------------------------------------------- AC 17: s falešnými klienty

    private function bootFakes(): ScriptedLlmClient
    {
        $llm = ScriptedLlmClient::delegatingTo(new FakeLlmClient());
        $this->boot(new FakeEmbeddingClient(), $llm);
        $this->container->get(ArticleIndexer::class)->update();

        return $llm;
    }

    public function test_fake_clients_answer_sleep_question_from_published_study(): void
    {
        $llm = $this->bootFakes();
        self::assertSame(3, $this->container->get(ArticleEmbeddingRepository::class)->status('fake-hash-768')->upToDate);

        $result = $this->example()->ask(self::QUESTION, 7);

        self::assertCount(1, $llm->requests);
        self::assertSame(1, $result->calls);
        self::assertSame(self::STUDY_URL, self::searchResults($llm->requests[0])[0]['source'] ?? null);
        self::assertStringContainsString('[1]', (string) self::field($result, 'Odpověď'));
        self::assertSame(self::STUDY_URL, self::field($result, 'Zdroje'));
        $everything = json_encode([$llm->requests[0]->messages, $result->fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['druhy-koncept', 'archivni-clanek', 'planovany-clanek'] as $hidden) {
            self::assertStringNotContainsString($hidden, $everything);
        }
    }

    public function test_fake_clients_find_nothing_about_yeast(): void
    {
        $llm = $this->bootFakes();

        $result = $this->example()->ask('Co víte o kvasinkách?', 7);

        self::assertSame([], $llm->requests);
        self::assertSame(0, $result->calls);
        self::assertSame(self::NOTHING_FOUND, self::field($result, 'Odpověď'));
    }

    /**
     * Celý ukázkový obsah (database/seeds/demo_content.php: 16 článků ve všech stavech, mezi nimi slovo „obsah“)
     * místo trojice z kontraktu. Falešné embeddingy při 768 dimenzích občas srazí hash dvou kmenů, takže
     * na malé fixtuře se kolize neukáže; regrese: stop-slovo „víte“ kolidovalo s „obsah“ (vzdálenost 0,910).
     */
    /** @return list<array{slug: string, title: string, status: string, published_at: ?string, excerpt: string, body: string}> */
    private static function seedArticles(): array
    {
        $seed = require AiFixtures::root() . '/database/seeds/demo_content.php';
        self::assertIsObject($seed);

        /** @var list<array{slug: string, title: string, status: string, published_at: ?string, excerpt: string, body: string}> */
        return new \ReflectionMethod($seed, 'articles')->invoke($seed);
    }

    private function bootFakesOverFullSeed(): ScriptedLlmClient
    {
        $articles = self::seedArticles();
        self::assertGreaterThanOrEqual(14, count($articles));

        $this->repository = new InMemoryArticleEmbeddingRepository();
        foreach ($articles as $article) {
            $this->repository->addArticle(
                $article['slug'],
                $article['title'],
                $article['body'],
                ArticleStatus::from($article['status']),
                $article['published_at'],
                $article['excerpt'],
            );
        }

        return $this->bootFakes();
    }

    public function test_full_seed_contains_the_colliding_word(): void
    {
        self::assertMatchesRegularExpression('~\bobsah~iu', json_encode(self::seedArticles(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function test_fake_clients_find_nothing_about_yeast_over_full_seed(): void
    {
        $llm = $this->bootFakesOverFullSeed();

        foreach (['Co víte o kvasinkách?', 'Kvasinky a pivo'] as $question) {
            $result = $this->example()->ask($question, 7);

            self::assertSame(0, $result->calls, $question);
            self::assertSame(self::NOTHING_FOUND, self::field($result, 'Odpověď'), $question);
            self::assertSame(self::NO_SOURCES, self::field($result, 'Zdroje'), $question);
        }
        self::assertSame([], $llm->requests);
    }

    /** @return iterable<string, array{string, string}> */
    public static function meaningfulSeedQuestions(): iterable
    {
        yield 'spánek' => ['Jak spánek ovlivňuje paměť?', '/clanek/nova-studie-o-spanku'];
        yield 'Docker' => ['Co víte o Dockeru?', '/clanek/docker-pro-vyvojare'];
        yield 'kvantové počítače' => ['Jak fungují kvantové počítače?', '/clanek/kvantove-pocitace-bez-mysticismu'];
        yield 'plasty' => ['Jak se třídí plasty?', '/clanek/recyklace-plastu-nove-metody'];
    }

    #[DataProvider('meaningfulSeedQuestions')]
    public function test_fake_clients_answer_meaningful_questions_over_full_seed(string $question, string $expectedUrl): void
    {
        $llm = $this->bootFakesOverFullSeed();

        $result = $this->example()->ask($question, 7);

        self::assertCount(1, $llm->requests);
        self::assertSame($expectedUrl, self::searchResults($llm->requests[0])[0]['source'] ?? null);
        self::assertStringContainsString('[1]', (string) self::field($result, 'Odpověď'));
    }

    public function test_draft_status_change_after_indexing_is_never_returned(): void
    {
        $llm = $this->bootFakes();
        $this->repository->updateArticle('nova-studie-o-spanku', status: ArticleStatus::Draft);

        $result = $this->example()->ask(self::QUESTION, 7);

        $everything = json_encode([array_map(static fn(LlmRequest $r): array => $r->messages, $llm->requests), $result->fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('nova-studie-o-spanku', $everything, 'Vektor konceptu zůstal v indexu, ale nesmí se vrátit.');
    }
}
