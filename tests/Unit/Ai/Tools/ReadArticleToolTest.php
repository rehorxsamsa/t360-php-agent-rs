<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Tools;

use App\Ai\Tools\AgentTool;
use App\Ai\Tools\ReadArticleTool;
use App\Ai\Tools\ToolResult;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryArticleRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 008, AC 20: nástroj `nacti_clanek` čte jen publikované články (čas 2026-10-04 12:00). */
final class ReadArticleToolTest extends TestCase
{
    private const string NOT_AVAILABLE = 'Článek neexistuje nebo není publikovaný.';

    private InMemoryArticleRepository $articles;

    protected function setUp(): void
    {
        $this->articles = InMemoryArticleRepository::newsroomContract(withInjection: true);
    }

    private function tool(): ReadArticleTool
    {
        return new ReadArticleTool($this->articles, FixedClock::at('2026-10-04 12:00:00'));
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

    public function test_definition_is_read_only_with_strict_schema(): void
    {
        $tool = $this->tool();
        $definition = $tool->definition();

        self::assertInstanceOf(AgentTool::class, $tool);
        self::assertSame('nacti_clanek', $tool->name());
        self::assertSame('nacti_clanek', $definition['name']);
        self::assertNotSame('', trim($definition['description']));
        self::assertSame('object', $definition['input_schema']['type'] ?? null);
        self::assertSame(['slug'], $definition['input_schema']['required'] ?? null);
        self::assertFalse($definition['input_schema']['additionalProperties'] ?? null);
        self::assertSame(['slug'], array_keys((array) ($definition['input_schema']['properties'] ?? [])));
        self::assertSame(6000, ReadArticleTool::BODY_LIMIT);
    }

    public function test_published_article_is_returned_in_contract_shape(): void
    {
        $result = $this->tool()->run(['slug' => 'docker-pro-vyvojare']);

        $data = self::decode($result);
        self::assertSame(
            ['slug', 'title', 'excerpt', 'category', 'tags', 'published_at', 'url', 'body', 'truncated'],
            array_keys($data),
        );
        self::assertSame('docker-pro-vyvojare', $data['slug']);
        self::assertSame('Docker pro vývojáře: proč na něm záleží', $data['title']);
        self::assertSame('Technologie', $data['category']);
        self::assertSame(['Docker'], $data['tags']);
        self::assertSame('2026-09-02', $data['published_at']);
        self::assertSame('/clanek/docker-pro-vyvojare', $data['url']);
        self::assertIsString($data['body']);
        self::assertStringContainsString('Docker sjednocuje prostředí.', $data['body']);
        self::assertFalse($data['truncated']);
        self::assertSame('/clanek/docker-pro-vyvojare', $result->sourceUrl);
        self::assertStringContainsString('Docker pro vývojáře: proč na něm záleží', $result->summary);
    }

    /** @return iterable<string, array{string}> */
    public static function hiddenSlugs(): iterable
    {
        yield 'draft' => ['druhy-koncept'];
        yield 'archived' => ['archivni-clanek'];
        yield 'scheduled' => ['planovany-clanek'];
        yield 'missing' => ['neexistuje'];
    }

    #[DataProvider('hiddenSlugs')]
    public function test_unpublished_and_missing_articles_give_the_same_error(string $slug): void
    {
        $result = $this->tool()->run(['slug' => $slug]);

        self::assertTrue($result->isError);
        self::assertSame(self::NOT_AVAILABLE, $result->content);
        self::assertNull($result->sourceUrl);
        self::assertStringNotContainsString('Druhý koncept', $result->summary);
    }

    public function test_long_body_is_truncated_to_6000_characters(): void
    {
        $this->articles->addArticle('dlouhy-clanek', 'Dlouhý článek', str_repeat('ř', 7000), publishedAt: '2026-09-20 08:00:00');

        $result = $this->tool()->run(['slug' => 'dlouhy-clanek']);

        $data = self::decode($result);
        self::assertSame(str_repeat('ř', 6000), $data['body']);
        self::assertTrue($data['truncated']);
        self::assertLessThanOrEqual(8000, mb_strlen($result->content));
    }

    public function test_body_of_exactly_6000_characters_is_not_truncated(): void
    {
        $this->articles->addArticle('presny-clanek', 'Přesný článek', str_repeat('a', 6000), publishedAt: '2026-09-20 08:00:00');

        $data = self::decode($this->tool()->run(['slug' => 'presny-clanek']));

        self::assertFalse($data['truncated']);
    }

    public function test_content_stays_within_8000_characters_with_long_title_and_excerpt(): void
    {
        $this->articles->addArticle(
            'obri-clanek',
            str_repeat('Titulek ', 30),
            str_repeat('Text článku. ', 1000),
            publishedAt: '2026-09-20 08:00:00',
            excerpt: str_repeat('Velmi dlouhý perex. ', 150),
        );

        $result = $this->tool()->run(['slug' => 'obri-clanek']);

        self::assertFalse($result->isError);
        self::assertLessThanOrEqual(8000, mb_strlen($result->content));
        $data = self::decode($result);
        self::assertTrue($data['truncated']);
    }

    public function test_extra_keys_are_ignored(): void
    {
        $data = self::decode($this->tool()->run(['slug' => 'docker-pro-vyvojare', 'status' => 'draft', 'id' => 1]));

        self::assertSame('docker-pro-vyvojare', $data['slug']);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'missing slug' => [[]];
        yield 'slug is number' => [['slug' => 5]];
        yield 'slug is array' => [['slug' => ['docker-pro-vyvojare']]];
        yield 'empty slug' => [['slug' => '']];
        yield 'uppercase' => [['slug' => 'Docker-pro-vyvojare']];
        yield 'double dash' => [['slug' => 'docker--pro']];
        yield 'trailing dash' => [['slug' => 'docker-']];
        yield 'path traversal' => [['slug' => '../etc/passwd']];
        yield 'sql' => [['slug' => "x' OR 1=1 --"]];
        yield 'diacritics' => [['slug' => 'článek']];
        yield '201 characters' => [['slug' => str_repeat('a', 201)]];
    }

    /** @param array<mixed> $input */
    #[DataProvider('invalidInputs')]
    public function test_invalid_slug_is_error_result_not_exception(array $input): void
    {
        $result = $this->tool()->run($input);

        self::assertTrue($result->isError);
        self::assertSame('Neplatný slug.', $result->content);
        self::assertSame(0, $this->articles->findCalls, 'Při neplatném slugu se repozitář nevolá.');
    }

    public function test_slug_of_200_characters_is_valid(): void
    {
        $result = $this->tool()->run(['slug' => str_repeat('a', 200)]);

        self::assertSame(self::NOT_AVAILABLE, $result->content);
    }
}
