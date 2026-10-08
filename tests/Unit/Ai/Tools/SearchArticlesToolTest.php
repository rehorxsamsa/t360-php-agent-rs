<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Tools;

use App\Ai\Tools\AgentTool;
use App\Ai\Tools\SearchArticlesTool;
use App\Ai\Tools\ToolResult;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryArticleRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 008, AC 20: nástroj `hledej_clanky` čte jen publikované články (čas 2026-10-04 12:00). */
final class SearchArticlesToolTest extends TestCase
{
    private InMemoryArticleRepository $articles;

    protected function setUp(): void
    {
        $this->articles = InMemoryArticleRepository::newsroomContract(withInjection: true);
    }

    private function tool(): SearchArticlesTool
    {
        return new SearchArticlesTool($this->articles, FixedClock::at('2026-10-04 12:00:00'));
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

    public function test_definition_is_read_only_search_with_strict_schema(): void
    {
        $tool = $this->tool();
        $definition = $tool->definition();

        self::assertInstanceOf(AgentTool::class, $tool);
        self::assertSame('hledej_clanky', $tool->name());
        self::assertSame('hledej_clanky', $definition['name']);
        self::assertNotSame('', trim($definition['description']));
        self::assertSame('object', $definition['input_schema']['type'] ?? null);
        self::assertSame(['query'], $definition['input_schema']['required'] ?? null);
        self::assertFalse($definition['input_schema']['additionalProperties'] ?? null);
        self::assertSame(['query'], array_keys((array) ($definition['input_schema']['properties'] ?? [])));
    }

    public function test_search_returns_only_published_article_in_contract_shape(): void
    {
        $result = $this->tool()->run(['query' => 'docker']);

        $data = self::decode($result);
        self::assertSame('docker', $data['query']);
        self::assertSame([[
            'slug' => 'docker-pro-vyvojare',
            'title' => 'Docker pro vývojáře: proč na něm záleží',
            'excerpt' => 'Kontejnery zjednodušují vývojové prostředí. Podívejte se, jak vypadá běžný denní postup.',
            'category' => 'Technologie',
            'published_at' => '2026-09-02',
        ]], $data['results']);
        self::assertNull($result->sourceUrl, 'Hledání samo není zdroj odpovědi.');
        self::assertStringContainsString('docker', $result->summary);
        self::assertStringContainsString('1', $result->summary);
    }

    public function test_search_uses_clock_and_limit_5(): void
    {
        $this->tool()->run(['query' => '  docker  ']);

        self::assertSame(1, $this->articles->searchCalls);
        self::assertSame('docker', $this->articles->lastQuery);
        self::assertSame(5, $this->articles->lastLimit);
        self::assertSame('2026-10-04 12:00:00', $this->articles->lastNow?->format('Y-m-d H:i:s'));
    }

    public function test_search_never_returns_draft_archived_or_scheduled_article(): void
    {
        $content = $this->tool()->run(['query' => 'docker'])->content;

        foreach (['druhy-koncept', 'Druhý koncept', 'archivni-clanek', 'planovany-clanek'] as $hidden) {
            self::assertStringNotContainsString($hidden, $content);
        }
    }

    public function test_search_returns_at_most_5_articles(): void
    {
        for ($day = 10; $day < 17; $day++) {
            $this->articles->addArticle('docker-' . $day, 'Docker díl ' . $day, 'Text o Dockeru.', publishedAt: '2026-09-' . $day . ' 08:00:00');
        }

        $data = self::decode($this->tool()->run(['query' => 'docker']));

        self::assertIsArray($data['results']);
        self::assertCount(5, $data['results']);
    }

    public function test_content_never_exceeds_8000_characters(): void
    {
        for ($day = 10; $day < 15; $day++) {
            $this->articles->addArticle(
                'dlouhy-' . $day,
                'Docker ' . str_repeat('ž', 190),
                'Text.',
                publishedAt: '2026-09-' . $day . ' 08:00:00',
                excerpt: str_repeat('Dlouhý perex o Dockeru. ', 120),
            );
        }

        $result = $this->tool()->run(['query' => 'docker']);

        self::assertFalse($result->isError);
        self::assertLessThanOrEqual(8000, mb_strlen($result->content));
        self::assertIsArray(json_decode($result->content, true), 'Obsah musí zůstat platný JSON.');
    }

    public function test_search_without_match_returns_empty_results(): void
    {
        $data = self::decode($this->tool()->run(['query' => 'kvasinky']));

        self::assertSame([], $data['results']);
    }

    public function test_extra_keys_are_ignored(): void
    {
        $data = self::decode($this->tool()->run(['query' => 'docker', 'limit' => 100, 'status' => 'draft']));

        self::assertIsArray($data['results']);
        self::assertCount(1, $data['results']);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'missing query' => [[]];
        yield 'query is number' => [['query' => 42]];
        yield 'query is array' => [['query' => ['docker']]];
        yield 'query is null' => [['query' => null]];
        yield 'query one char after trim' => [['query' => '  d  ']];
        yield 'query 101 characters' => [['query' => str_repeat('ž', 101)]];
    }

    /** @param array<mixed> $input */
    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_error_result_not_exception(array $input): void
    {
        $result = $this->tool()->run($input);

        self::assertTrue($result->isError);
        self::assertSame('Dotaz musí mít 2–100 znaků.', $result->content);
        self::assertNull($result->sourceUrl);
        self::assertSame(0, $this->articles->searchCalls, 'Při neplatném vstupu se repozitář nevolá.');
    }

    public function test_query_of_100_characters_is_accepted(): void
    {
        self::assertFalse($this->tool()->run(['query' => str_repeat('ž', 100)])->isError);
        self::assertFalse($this->tool()->run(['query' => 'ab'])->isError);
    }
}
