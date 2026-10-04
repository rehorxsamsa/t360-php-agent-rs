<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\AiConfig;
use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\PromptData;
use App\Ai\PromptLibrary;

/** 05 – Překlad CZ → EN se zachováním Markdownu; uživatel porovná dva modely (kvalita × cena). */
final readonly class Example05Translation implements AiExample
{
    private const int MAX_TOKENS = 6000;

    public function __construct(
        private LlmClient $client,
        private PromptLibrary $prompts,
        private AiConfig $config,
    ) {}

    public function id(): string
    {
        return '05';
    }

    public function title(): string
    {
        return 'Překlad CZ → EN';
    }

    public function description(): string
    {
        return 'Přeloží titulek, perex a text do angličtiny se zachováním Markdownu. Můžete porovnat silnější a levnější model.';
    }

    public function modelChoices(): array
    {
        return array_values(array_unique([$this->config->model, $this->config->cheapModel]));
    }

    public function run(ArticleSnapshot $article, ExampleContext $context): ExampleResult
    {
        $model = $context->model === '' ? $this->config->model : $context->model;
        if (!in_array($model, $this->modelChoices(), true)) {
            throw new InvalidExampleInput('Vyberte model ze seznamu.');
        }

        $request = new LlmRequest(
            model: $model,
            system: $this->prompts->system('05-translation'),
            messages: [['role' => 'user', 'content' => PromptData::article($article) . "\n\nÚkol: přelož článek do angličtiny."]],
            maxTokens: self::MAX_TOKENS,
            exampleId: $this->id(),
            userId: $context->userId,
            effort: 'low',
            jsonSchema: self::schema(),
        );

        $outcome = new StructuredCall($this->client)->run($request, $this->validate(...));

        // Text se vypisuje beze změny (i koncové zalomení), aby se Markdown nezkreslil.
        $translatedBody = is_string($outcome->data['body'] ?? null) ? $outcome->data['body'] : '';
        $warnings = [];
        $structureWarning = $this->compareStructure($article->body, $translatedBody);
        if ($structureWarning !== null) {
            $warnings[] = $structureWarning;
        }

        $last = $outcome->last();

        return new ExampleResult(
            $this->id(),
            [
                ['label' => 'Titulek (EN)', 'value' => FieldRules::stringValue($outcome->data, 'title')],
                ['label' => 'Perex (EN)', 'value' => FieldRules::stringValue($outcome->data, 'excerpt')],
                ['label' => 'Slug', 'value' => $article->slug],
                ['label' => 'Text (EN, Markdown)', 'value' => $translatedBody],
            ],
            $warnings,
            $outcome->rawOutput(),
            $outcome->usage(),
            $outcome->costUsd(),
            $last->model,
            $last->provider,
            $outcome->calls(),
        );
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Přeložený titulek'],
                'excerpt' => ['type' => 'string', 'description' => 'Přeložený perex (prázdný, když je originál prázdný)'],
                'body' => ['type' => 'string', 'description' => 'Přeložený text v Markdownu se stejnou strukturou'],
            ],
            'required' => ['title', 'excerpt', 'body'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private function validate(array $data): array
    {
        return array_values(array_filter([
            FieldRules::string($data, 'title', 1, 300),
            FieldRules::string($data, 'excerpt', 0, 600),
            FieldRules::string($data, 'body', 1, 40000),
        ]));
    }

    /** Porovná počty nadpisů, bloků kódu a položek seznamu; null = struktura sedí. */
    private function compareStructure(string $source, string $translation): ?string
    {
        $labels = ['headings' => 'nadpisy', 'codeBlocks' => 'bloky kódu', 'listItems' => 'položky seznamu'];
        $before = self::countStructure($source);
        $after = self::countStructure($translation);

        $differences = [];
        foreach ($labels as $key => $label) {
            if ($before[$key] !== $after[$key]) {
                $differences[] = sprintf('%s %d → %d', $label, $before[$key], $after[$key]);
            }
        }

        return $differences === []
            ? null
            : 'Překlad nezachoval strukturu Markdownu: ' . implode(', ', $differences) . '.';
    }

    /** @return array{headings: int, codeBlocks: int, listItems: int} */
    private static function countStructure(string $markdown): array
    {
        $counts = ['headings' => 0, 'codeBlocks' => 0, 'listItems' => 0];
        $fence = null;

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line, $matches) === 1) {
                if ($fence === null) {
                    $fence = $matches[1];
                    $counts['codeBlocks']++;
                } elseif ($fence === $matches[1]) {
                    $fence = null;
                }
                continue;
            }

            if ($fence !== null) {
                continue;
            }

            if (preg_match('/^\s{0,3}#{1,6}\s/', $line) === 1) {
                $counts['headings']++;
            } elseif (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/', $line) === 1) {
                $counts['listItems']++;
            }
        }

        return $counts;
    }
}
