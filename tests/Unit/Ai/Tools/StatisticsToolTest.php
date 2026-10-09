<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Tools;

use App\Ai\Tools\AgentTool;
use App\Ai\Tools\StatisticsTool;
use App\Ai\Tools\ToolResult;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryArticleRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 011, AC 3: nástroj `statistiky` – souhrn jen publikovaných článků (čas 2026-10-09 12:00 Europe/Prague,
 * kontrakt testovacích dat plánu 008). Vstup se ignoruje, schéma je prázdný objekt (`"properties":{}`).
 */
final class StatisticsToolTest extends TestCase
{
    private const string EXPECTED_WITH_INJECTION = '{"published_articles":3,"published_last_30_days":1,"latest_published_at":"2026-09-10",'
        . '"categories":[{"name":"Technologie","articles":1},{"name":"Věda a výzkum","articles":1},{"name":"Zprávy","articles":1}],'
        . '"top_tags":[{"name":"Docker","articles":1}],"generated_at":"2026-10-09"}';

    private function tool(InMemoryArticleRepository $articles): StatisticsTool
    {
        return new StatisticsTool($articles, FixedClock::at('2026-10-09 12:00:00'));
    }

    /** @return array<string, mixed> */
    private static function decode(ToolResult $result): array
    {
        self::assertFalse($result->isError, 'Očekáván úspěšný výsledek: ' . $result->content);
        $data = json_decode($result->content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $items): array
    {
        self::assertIsArray($items);
        $names = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['name'] ?? null);
            $names[] = $item['name'];
        }

        return $names;
    }

    public function test_constants_and_definition_without_parameters(): void
    {
        $tool = $this->tool(new InMemoryArticleRepository());
        $definition = $tool->definition();

        self::assertInstanceOf(AgentTool::class, $tool);
        self::assertSame('statistiky', StatisticsTool::NAME);
        self::assertSame(10, StatisticsTool::TAG_LIMIT);
        self::assertSame(50, StatisticsTool::CATEGORY_LIMIT);
        self::assertSame('statistiky', $tool->name());
        self::assertSame('statistiky', $definition['name']);
        self::assertStringContainsString('publikovan', $definition['description'], 'Popis říká, že jde jen o publikované články.');
        self::assertLessThanOrEqual(2048, mb_strlen($definition['description']));
        self::assertSame(['type', 'properties', 'additionalProperties'], array_keys($definition['input_schema']));
        self::assertSame('object', $definition['input_schema']['type']);
        self::assertFalse($definition['input_schema']['additionalProperties']);
    }

    /** PHP past ADR-0008: prázdné pole by se serializovalo jako `[]` a Claude Code by schéma odmítl. */
    public function test_input_schema_serializes_empty_properties_as_object(): void
    {
        $json = json_encode($this->tool(new InMemoryArticleRepository())->definition()['input_schema'], JSON_THROW_ON_ERROR);

        self::assertSame('{"type":"object","properties":{},"additionalProperties":false}', $json);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function ignoredInputs(): iterable
    {
        yield 'empty' => [[]];
        yield 'unexpected key' => [['cokoli' => 1]];
        yield 'status injection' => [['status' => 'draft', 'include_drafts' => true]];
    }

    /** @param array<mixed> $input */
    #[DataProvider('ignoredInputs')]
    public function test_statistics_of_published_articles_in_contract_shape(array $input): void
    {
        $articles = InMemoryArticleRepository::newsroomContract(withInjection: true);

        $result = $this->tool($articles)->run($input);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::EXPECTED_WITH_INJECTION, $result->content);
        self::assertSame('Statistiky: publikovaných článků 3.', $result->summary);
        self::assertSame(1, $articles->statisticsCalls);
        self::assertSame(10, $articles->lastTagLimit);
        self::assertSame('2026-10-09 12:00', $articles->lastNow?->format('Y-m-d H:i'));
    }

    /** Rubrika „Zprávy“ má bez injekce jen archivní a naplánovaný článek – neprozradí se. */
    public function test_category_without_published_article_is_not_revealed(): void
    {
        $result = $this->tool(InMemoryArticleRepository::newsroomContract())->run([]);
        $data = self::decode($result);

        self::assertSame(2, $data['published_articles']);
        self::assertSame(0, $data['published_last_30_days']);
        self::assertSame('2026-09-06', $data['latest_published_at']);
        self::assertSame(['Technologie', 'Věda a výzkum'], self::names($data['categories']));
        self::assertSame(['Docker'], self::names($data['top_tags']));
        self::assertStringNotContainsString('Zprávy', $result->content);
    }

    public function test_empty_newsroom_gives_zeroes_and_null_date(): void
    {
        $result = $this->tool(new InMemoryArticleRepository())->run([]);

        self::assertSame(
            '{"published_articles":0,"published_last_30_days":0,"latest_published_at":null,"categories":[],"top_tags":[],"generated_at":"2026-10-09"}',
            $result->content,
        );
        self::assertSame('Statistiky: publikovaných článků 0.', $result->summary);
    }

    public function test_output_is_limited_to_50_categories_10_tags_and_max_length(): void
    {
        $articles = new InMemoryArticleRepository();
        for ($i = 1; $i <= 60; ++$i) {
            $articles->addArticle(
                sprintf('clanek-%d', $i),
                sprintf('Článek %d', $i),
                'x',
                publishedAt: '2026-09-15 08:00:00',
                categoryName: sprintf('%02d ', $i) . str_repeat('ř', 97),
                tagNames: [sprintf('%02d ', $i % 15) . str_repeat('ž', 57)],
            );
        }

        $result = $this->tool($articles)->run([]);

        self::assertLessThanOrEqual(ToolResult::MAX_LENGTH, mb_strlen($result->content));
        $data = self::decode($result);
        self::assertSame(60, $data['published_articles']);
        self::assertCount(50, self::names($data['categories']));
        self::assertCount(10, self::names($data['top_tags']));
    }
}
