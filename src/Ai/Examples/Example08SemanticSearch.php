<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiConfig;
use App\Ai\Embedding\EmbeddingClient;
use App\Ai\Embedding\EmbeddingFailed;
use App\Ai\Embedding\EmbeddingProvider;
use App\Ai\Embedding\EmbeddingResult;
use App\Ai\LlmCallFailed;
use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\PromptLibrary;
use App\Domain\Ai\TokenUsage;
use App\Domain\Article\ArticleEmbeddingRepository;
use App\Domain\Article\SimilarArticle;
use App\Domain\Time\Clock;

/**
 * 08 – Sémantické vyhledávání (RAG): otázka → embedding → nejbližší publikované články → odpověď Claude s citacemi.
 *
 * Články se hledají podle významu (vektory v MariaDB, ADR-0009), ne podle shody slov. Model je dostane jako bloky
 * `search_result` s `citations.enabled` a jeho odpověď nese `citations` typu `search_result_location`. Citace jsou
 * nedůvěryhodný výstup modelu, proto se před zobrazením ověřují (index zdroje v rozsahu, `source` odpovídá zdroji
 * na tomto indexu) a značky `[n]` do odpovědi doplňuje tento kód, ne model. Žádné nástroje, nic se nezapisuje.
 * Obsah článků je nedůvěryhodný (nepřímá prompt injection): dopad je nejvýš zkreslená odpověď, která se jen
 * escapovaně zobrazí vedle doslovných citovaných úseků.
 */
final readonly class Example08SemanticSearch implements ExampleDescription
{
    public const string DEMO_QUESTION = 'Jak spánek ovlivňuje paměť?';

    public const int SOURCE_CHAR_LIMIT = 3000;
    public const int SOURCE_BLOCK_LIMIT = 12;
    public const int MAX_TOKENS = 1024;

    private const int MIN_QUESTION = 3;
    private const int MAX_QUESTION = 500;
    private const int CITED_TEXT_LIMIT = 300;
    private const int CITATION_FIELD_LIMIT = 10;
    private const string NOTHING_FOUND = 'V publikovaných článcích jsem k tomu nic nenašel.';
    private const string NO_SOURCES = 'Žádné – odpověď necituje články.';

    public function __construct(
        private LlmClient $client,
        private EmbeddingClient $embeddings,
        private ArticleEmbeddingRepository $repository,
        private PromptLibrary $prompts,
        private AiConfig $config,
        private Clock $clock,
        private int $sourceLimit = 3,
        private float $maxDistance = 0.95,
    ) {}

    public function id(): string
    {
        return '08';
    }

    public function title(): string
    {
        return 'Sémantické vyhledávání (RAG)';
    }

    public function description(): string
    {
        return 'Otázka vlastními slovy: články se najdou podle významu (embeddingy ve vektorovém indexu MariaDB) '
            . 'a model z nich odpoví s ověřenými citacemi. Odpovídá jen z publikovaných článků.';
    }

    /**
     * @throws InvalidExampleInput
     * @throws InvalidModelOutput
     * @throws EmbeddingFailed
     * @throws LlmCallFailed
     * @throws AiBudgetExceeded
     */
    public function ask(string $question, ?int $userId): ExampleResult
    {
        $question = trim($question);
        $length = mb_strlen($question);
        if ($length < self::MIN_QUESTION || $length > self::MAX_QUESTION) {
            throw new InvalidExampleInput('Zadejte otázku (3–500 znaků).');
        }

        $queryEmbedding = $this->embeddings->embedQuery($question);
        $vector = $queryEmbedding->first();
        if ($vector->dimensions() !== ArticleEmbeddingRepository::DIMENSIONS) {
            throw EmbeddingFailed::dimensionMismatch($vector->dimensions(), ArticleEmbeddingRepository::DIMENSIONS);
        }

        $sources = array_values(array_filter(
            $this->repository->nearestPublished($vector, $this->embeddings->model(), $this->clock->now(), $this->sourceLimit),
            fn(SimilarArticle $article): bool => $article->distance <= $this->maxDistance,
        ));

        if ($sources === []) {
            return $this->nothingFound($question, $queryEmbedding);
        }

        $warnings = [];
        $pending = $this->repository->status($this->embeddings->model())->pending();
        if ($pending > 0) {
            $warnings[] = $this->outdatedIndexWarning($pending);
        }

        $blocks = array_map($this->searchResult(...), $sources);
        $response = $this->client->complete(new LlmRequest(
            model: $this->config->model,
            system: $this->prompts->system('08-semantic-search'),
            messages: [['role' => 'user', 'content' => [...$blocks, ['type' => 'text', 'text' => 'Otázka: ' . $question]]]],
            maxTokens: self::MAX_TOKENS,
            exampleId: $this->id(),
            userId: $userId,
            effort: 'low',
        ));

        if ($response->stopReason === 'refusal') {
            throw new InvalidModelOutput('Model odmítl odpovědět (refusal).');
        }

        $composed = $this->composeAnswer($response, $sources);
        if ($composed['answer'] === '') {
            throw new InvalidModelOutput('Model vrátil prázdnou odpověď.');
        }

        if ($response->stopReason === 'max_tokens') {
            $warnings[] = 'Odpověď byla useknuta limitem max_tokens.';
        }
        if ($composed['unknownSource']) {
            $warnings[] = 'Model citoval neznámý zdroj, citace byla vynechána.';
        }
        if ($composed['citations'] === []) {
            $warnings[] = 'Odpověď necituje žádný článek – ověřte ji ve zdrojích.';
        }

        return new ExampleResult(
            $this->id(),
            [
                ['label' => 'Otázka', 'value' => $question],
                ['label' => 'Odpověď', 'value' => $composed['answer']],
                ['label' => 'Nalezené články', 'value' => $this->foundArticles($sources)],
                ...$this->citationFields($composed['citations'], $sources),
                ['label' => 'Zdroje', 'value' => $this->citedUrls($composed['citations'], $sources)],
                $this->embeddingField($queryEmbedding),
            ],
            $warnings,
            $response->text,
            $response->usage,
            $response->costUsd ?? 0.0,
            $response->model,
            $response->provider,
            1,
        );
    }

    /** Varování o neaktuálním indexu se správným českým skloňováním (1 článek čeká / 2–4 články čekají / 5+ článků čeká). */
    private function outdatedIndexWarning(int $pending): string
    {
        $waiting = match (true) {
            $pending === 1 => '1 článek čeká',
            $pending >= 2 && $pending <= 4 => sprintf('%d články čekají', $pending),
            default => sprintf('%d článků čeká', $pending),
        };

        return sprintf('Index není aktuální (%s na indexaci) – výsledky nemusí odpovídat.', $waiting);
    }

    /** Nic dost blízkého nenalezeno: model se nevolá (nic by nemělo z čeho odpovídat), stojí to 0 tokenů. */
    private function nothingFound(string $question, EmbeddingResult $queryEmbedding): ExampleResult
    {
        $warnings = [];
        $status = $this->repository->status($this->embeddings->model());
        if ($status->upToDate === 0) {
            $warnings[] = 'Index je prázdný – nejdřív ho aktualizujte (tlačítko Aktualizovat index nebo ai:indexuj).';
        } elseif ($status->pending() > 0) {
            $warnings[] = $this->outdatedIndexWarning($status->pending());
        }

        return new ExampleResult(
            $this->id(),
            [
                ['label' => 'Otázka', 'value' => $question],
                ['label' => 'Odpověď', 'value' => self::NOTHING_FOUND],
                ['label' => 'Zdroje', 'value' => self::NO_SOURCES],
                $this->embeddingField($queryEmbedding),
            ],
            $warnings,
            self::NOTHING_FOUND,
            new TokenUsage(0, 0),
            0.0,
            $this->config->model,
            $this->config->provider->logName(),
            0,
        );
    }

    /**
     * Zdroj jako blok `search_result`: perex a odstavce textu (dělené prázdným řádkem) jsou samostatné textové
     * bloky, které model cituje po blocích. Délka a počet bloků jsou omezené (náklady, kontext modelu).
     *
     * @return array<string, mixed>
     */
    private function searchResult(SimilarArticle $article): array
    {
        $candidates = [trim($article->excerpt), ...array_map(trim(...), preg_split('/\R{2,}/u', $article->body) ?: [])];

        $content = [];
        $remaining = self::SOURCE_CHAR_LIMIT;
        foreach ($candidates as $text) {
            if ($text === '') {
                continue;
            }
            if (count($content) >= self::SOURCE_BLOCK_LIMIT || $remaining < 2) {
                break;
            }

            if (mb_strlen($text) > $remaining) {
                $content[] = ['type' => 'text', 'text' => $this->cutAtWord($text, $remaining)];
                break;
            }

            $content[] = ['type' => 'text', 'text' => $text];
            $remaining -= mb_strlen($text);
        }

        return [
            'type' => 'search_result',
            'source' => $article->url(),
            'title' => $article->title,
            'content' => $content,
            'citations' => ['enabled' => true],
        ];
    }

    /** Zkrátí text na `$limit` znaků včetně „…“, pokud možno na hranici slova. */
    private function cutAtWord(string $text, int $limit): string
    {
        $cut = mb_substr($text, 0, $limit - 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut) . '…';
    }

    /**
     * Složí zobrazenou odpověď z textových bloků modelu a ověří jejich citace. Za blok s platnou citací se
     * přidá značka `[n]` (n = pořadí zdroje od 1, každé číslo v bloku jednou). Bez surových bloků (dvojník)
     * zůstane čistý text.
     *
     * @param list<SimilarArticle> $sources
     * @return array{answer: string, citations: list<array{index: int, start: int, text: string}>, unknownSource: bool}
     */
    private function composeAnswer(LlmResponse $response, array $sources): array
    {
        if ($response->content === []) {
            return ['answer' => trim($response->text), 'citations' => [], 'unknownSource' => false];
        }

        $answer = '';
        $citations = [];
        $unknownSource = false;

        foreach ($response->content as $block) {
            if (($block['type'] ?? null) !== 'text' || !is_string($block['text'] ?? null)) {
                continue;
            }

            $answer .= $block['text'];
            $markers = [];
            $rawCitations = $block['citations'] ?? null;
            foreach (is_array($rawCitations) ? $rawCitations : [] as $raw) {
                $citation = is_array($raw) ? $this->validCitation($raw, $sources) : null;
                if ($citation === null) {
                    $unknownSource = true;

                    continue;
                }

                $markers[$citation['index']] = sprintf('[%d]', $citation['index'] + 1);
                $key = $citation['index'] . ':' . $citation['start'];
                if (!isset($citations[$key])) {
                    $citations[$key] = $citation;
                }
            }

            if ($markers !== []) {
                $answer = rtrim($answer) . ' ' . implode('', $markers);
            }
        }

        return ['answer' => trim($answer), 'citations' => array_values($citations), 'unknownSource' => $unknownSource];
    }

    /**
     * @param array<mixed> $raw jedna citace z odpovědi modelu (nedůvěryhodná)
     * @param list<SimilarArticle> $sources
     * @return array{index: int, start: int, text: string}|null null = neplatná citace
     */
    private function validCitation(array $raw, array $sources): ?array
    {
        $index = $raw['search_result_index'] ?? null;
        if (($raw['type'] ?? null) !== 'search_result_location' || !is_int($index) || !isset($sources[$index])) {
            return null;
        }

        if (($raw['source'] ?? null) !== $sources[$index]->url()) {
            return null;
        }

        $start = $raw['start_block_index'] ?? 0;
        $text = $raw['cited_text'] ?? '';

        return [
            'index' => $index,
            'start' => is_int($start) && $start >= 0 ? $start : 0,
            'text' => is_string($text) ? $text : '',
        ];
    }

    /** @param list<SimilarArticle> $sources */
    private function foundArticles(array $sources): string
    {
        $lines = [];
        foreach ($sources as $index => $article) {
            $lines[] = sprintf(
                '[%d] %s – %s (vzdálenost %s)',
                $index + 1,
                $article->title,
                $article->url(),
                number_format($article->distance, 3, ',', ''),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{index: int, start: int, text: string}> $citations
     * @param list<SimilarArticle> $sources
     * @return list<array{label: string, value: string}>
     */
    private function citationFields(array $citations, array $sources): array
    {
        $fields = [];
        foreach (array_slice($citations, 0, self::CITATION_FIELD_LIMIT) as $citation) {
            $text = mb_strlen($citation['text']) > self::CITED_TEXT_LIMIT
                ? rtrim(mb_substr($citation['text'], 0, self::CITED_TEXT_LIMIT - 1)) . '…'
                : $citation['text'];

            $fields[] = [
                'label' => sprintf('Citace [%d]', $citation['index'] + 1),
                'value' => sprintf('„%s“ – %s', $text, $sources[$citation['index']]->url()),
            ];
        }

        return $fields;
    }

    /**
     * @param list<array{index: int, start: int, text: string}> $citations
     * @param list<SimilarArticle> $sources
     */
    private function citedUrls(array $citations, array $sources): string
    {
        $urls = [];
        foreach ($citations as $citation) {
            $urls[$sources[$citation['index']]->url()] = true;
        }

        return $urls === [] ? self::NO_SOURCES : implode("\n", array_keys($urls));
    }

    /** @return array{label: string, value: string} */
    private function embeddingField(EmbeddingResult $result): array
    {
        $provider = EmbeddingProvider::fromLogName($this->embeddings->provider());

        return [
            'label' => 'Embedding dotazu',
            'value' => sprintf(
                'model %s · %s · %d tokenů · %d ms',
                $this->embeddings->model(),
                $provider?->label() ?? $this->embeddings->provider(),
                $result->tokens,
                $result->durationMs,
            ),
        ];
    }
}
