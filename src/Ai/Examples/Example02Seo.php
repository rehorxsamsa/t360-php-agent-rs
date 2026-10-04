<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\AiConfig;
use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\PromptData;
use App\Ai\PromptLibrary;

/** 02 – SEO titulek a meta popis: strukturovaný výstup (`output_config.format`) a validace s opakováním. */
final readonly class Example02Seo implements AiExample
{
    private const int MAX_TOKENS = 600;

    public function __construct(
        private LlmClient $client,
        private PromptLibrary $prompts,
        private AiConfig $config,
    ) {}

    public function id(): string
    {
        return '02';
    }

    public function title(): string
    {
        return 'SEO titulek a meta popis';
    }

    public function description(): string
    {
        return 'Vrátí JSON {title, meta_description, keywords}. Strukturovaný výstup, validace v PHP a opakování s chybou.';
    }

    public function modelChoices(): array
    {
        return [];
    }

    public function run(ArticleSnapshot $article, ExampleContext $context): ExampleResult
    {
        $request = new LlmRequest(
            model: $this->config->model,
            system: $this->prompts->system('02-seo'),
            messages: [['role' => 'user', 'content' => PromptData::article($article) . "\n\nÚkol: navrhni SEO titulek, meta popis a klíčová slova."]],
            maxTokens: self::MAX_TOKENS,
            exampleId: $this->id(),
            userId: $context->userId,
            effort: 'low',
            jsonSchema: self::schema(),
        );

        $outcome = new StructuredCall($this->client)->run($request, $this->validate(...));

        $keywords = array_map(
            static fn(mixed $keyword): string => is_string($keyword) ? trim($keyword) : '',
            is_array($outcome->data['keywords'] ?? null) ? $outcome->data['keywords'] : [],
        );

        $last = $outcome->last();

        return new ExampleResult(
            $this->id(),
            [
                ['label' => 'Titulek', 'value' => FieldRules::stringValue($outcome->data, 'title')],
                ['label' => 'Meta popis', 'value' => FieldRules::stringValue($outcome->data, 'meta_description')],
                ['label' => 'Klíčová slova', 'value' => implode(', ', $keywords)],
            ],
            [],
            $outcome->rawOutput(),
            $outcome->usage(),
            $outcome->costUsd(),
            $last->model,
            $last->provider,
            $outcome->calls(),
        );
    }

    /**
     * API schéma nepodporuje maxLength ani maxItems – délky proto hlídá {@see validate()}.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'SEO titulek, nejvýše 60 znaků'],
                'meta_description' => ['type' => 'string', 'description' => 'Meta popis, nejvýše 160 znaků'],
                'keywords' => [
                    'type' => 'array',
                    'description' => '3 až 8 klíčových slov, každé nejvýše 40 znaků',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                ],
            ],
            'required' => ['title', 'meta_description', 'keywords'],
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
            FieldRules::string($data, 'title', 1, 60),
            FieldRules::string($data, 'meta_description', 1, 160),
            ...FieldRules::stringList($data, 'keywords', 3, 8, 1, 40),
        ]));
    }
}
