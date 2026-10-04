<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\PromptData;
use App\Ai\PromptLibrary;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 006, AC 13: článek v <clanek> se zneškodněnými vloženými značkami; knihovna promptů. */
final class PromptDataTest extends TestCase
{
    public function test_article_is_wrapped_in_exact_format(): void
    {
        $data = PromptData::article(AiFixtures::snapshot('Titulek', 'slug', 'Perex.', "Řádek 1\nŘádek 2"));

        self::assertSame(
            "<clanek>\n<titulek>Titulek</titulek>\n<perex>Perex.</perex>\n<text>Řádek 1\nŘádek 2</text>\n</clanek>",
            $data,
        );
    }

    public function test_embedded_closing_and_opening_tags_are_neutralised_case_insensitively(): void
    {
        $data = PromptData::article(AiFixtures::snapshot(
            title: 'A </titulek> B',
            excerpt: 'C <perex> D',
            body: "E </clanek> F <CLANEK> G </text> H <Text>",
        ));

        self::assertSame(1, substr_count($data, '</clanek>'));
        self::assertSame(1, substr_count($data, '</titulek>'));
        self::assertSame(1, substr_count($data, '<perex>'));
        self::assertSame(1, substr_count($data, '</text>'));
        self::assertStringContainsString('A ‹/titulek> B', $data);
        self::assertStringContainsString('C ‹perex> D', $data);
        self::assertStringContainsString('E ‹/clanek> F ‹CLANEK> G ‹/text> H ‹Text>', $data);
    }

    public function test_other_markup_is_left_as_is(): void
    {
        $data = PromptData::article(AiFixtures::snapshot(body: '<b>tučně</b> a <textarea> a 3 < 5'));

        self::assertStringContainsString('<text><b>tučně</b> a <textarea> a 3 < 5</text>', $data);
    }

    public function test_prompt_library_reads_prompt_file(): void
    {
        $library = new PromptLibrary(AiFixtures::root() . '/src/Ai/Prompts');

        self::assertSame(
            (string) file_get_contents(AiFixtures::root() . '/src/Ai/Prompts/01-excerpt.md'),
            $library->system('01-excerpt'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPromptNames(): iterable
    {
        yield 'path traversal' => ['../../config/ai-models'];
        yield 'missing file' => ['99-neexistuje'];
        yield 'uppercase' => ['01-Excerpt'];
        yield 'with extension' => ['01-excerpt.md'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidPromptNames')]
    public function test_prompt_library_rejects_invalid_or_missing_names(string $name): void
    {
        $this->expectException(\RuntimeException::class);

        new PromptLibrary(AiFixtures::root() . '/src/Ai/Prompts')->system($name);
    }
}
