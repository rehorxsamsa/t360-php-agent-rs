<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\AiConfig;
use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\PromptData;
use App\Ai\PromptLibrary;
use App\Domain\Category\Category;
use App\Domain\Category\CategoryRepository;
use App\Domain\Tag\Tag;
use App\Domain\Tag\TagRepository;

/** 03 – Štítky a rubrika: klasifikace levným modelem, `enum` rubrik ve schématu, `cacheSystem`. */
final readonly class Example03Classification implements AiExample
{
    private const int MAX_TOKENS = 500;

    /** Kolik existujících štítků se nejvýše pošle modelu (kvůli ceně vstupu). */
    private const int MAX_KNOWN_TAGS = 100;

    public function __construct(
        private LlmClient $client,
        private PromptLibrary $prompts,
        private AiConfig $config,
        private CategoryRepository $categories,
        private TagRepository $tags,
    ) {}

    public function id(): string
    {
        return '03';
    }

    public function title(): string
    {
        return 'Štítky a rubrika';
    }

    public function description(): string
    {
        return 'Navrhne rubriku z existujícího výčtu a 3 až 6 štítků. Klasifikace levným modelem, enum ve schématu, prompt caching.';
    }

    public function modelChoices(): array
    {
        return [];
    }

    public function run(ArticleSnapshot $article, ExampleContext $context): ExampleResult
    {
        $categoryNames = array_map(static fn(Category $category): string => $category->name, $this->categories->all());
        if ($categoryNames === []) {
            throw new InvalidExampleInput('V databázi nejsou žádné rubriky. Nejdřív nějakou vytvořte.');
        }

        $knownTags = array_map(static fn(Tag $tag): string => $tag->name, $this->tags->all());

        $request = new LlmRequest(
            model: $this->config->cheapModel,
            system: $this->prompts->system('03-classification'),
            messages: [['role' => 'user', 'content' => $this->userMessage($article, $categoryNames, $knownTags)]],
            maxTokens: self::MAX_TOKENS,
            exampleId: $this->id(),
            userId: $context->userId,
            effort: 'low',
            jsonSchema: self::schema($categoryNames),
            cacheSystem: true,
        );

        $outcome = new StructuredCall($this->client)->run(
            $request,
            fn(array $data): array => $this->validate($data, $categoryNames),
        );

        $known = array_flip(array_map(static fn(string $name): string => mb_strtolower($name), $knownTags));
        $tags = [];
        $rawTags = is_array($outcome->data['tags'] ?? null) ? $outcome->data['tags'] : [];
        foreach ($rawTags as $tag) {
            $name = is_string($tag) ? trim($tag) : '';
            $key = mb_strtolower($name);
            if ($name !== '' && !isset($tags[$key])) {
                $tags[$key] = $name . (isset($known[$key]) ? ' (existuje)' : ' (nový)');
            }
        }

        $last = $outcome->last();

        return new ExampleResult(
            $this->id(),
            [
                ['label' => 'Rubrika', 'value' => FieldRules::stringValue($outcome->data, 'category')],
                ['label' => 'Štítky', 'value' => implode(', ', $tags)],
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
     * Seznamy rubrik a štítků jdou do uživatelské zprávy (system prompt zůstává statický).
     *
     * @param list<string> $categoryNames
     * @param list<string> $knownTags
     */
    private function userMessage(ArticleSnapshot $article, array $categoryNames, array $knownTags): string
    {
        return PromptData::article($article)
            . "\n\n" . PromptData::list('rubriky', $categoryNames)
            . "\n" . PromptData::list('existujici_stitky', array_slice($knownTags, 0, self::MAX_KNOWN_TAGS))
            . "\n\nÚkol: vyber rubriku a navrhni štítky.";
    }

    /**
     * @param list<string> $categoryNames
     * @return array<string, mixed>
     */
    private static function schema(array $categoryNames): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'description' => 'Právě jedna rubrika ze seznamu',
                    'enum' => $categoryNames,
                ],
                'tags' => [
                    'type' => 'array',
                    'description' => '3 až 6 štítků, každý nejvýše 50 znaků',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                ],
            ],
            'required' => ['category', 'tags'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $categoryNames
     * @return list<string>
     */
    private function validate(array $data, array $categoryNames): array
    {
        return array_values(array_filter([
            FieldRules::oneOf($data, 'category', $categoryNames),
            ...FieldRules::stringList($data, 'tags', 3, 6, 1, 50),
        ]));
    }
}
