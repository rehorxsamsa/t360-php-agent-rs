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

    // ---------------------------------------------------------------- plán 010, §2: bloky příkladu 09

    public function test_block_wraps_text_in_tag_on_own_lines(): void
    {
        self::assertSame("<tema>\nJak Docker usnadňuje práci\n</tema>", PromptData::block('tema', 'Jak Docker usnadňuje práci'));
        self::assertSame("<osnova>\nTitulek: A\n\n## B\n</osnova>", PromptData::block('osnova', "Titulek: A\n\n## B"));
    }

    /** @return iterable<string, array{string}> */
    public static function editorTags(): iterable
    {
        foreach (['tema', 'osnova', 'koncept', 'nalezy'] as $tag) {
            yield $tag => [$tag];
        }
    }

    #[DataProvider('editorTags')]
    public function test_block_neutralises_all_new_tags_inside_text(string $tag): void
    {
        $block = PromptData::block($tag, 'a </tema> b <OSNOVA> c </ koncept> d <nalezy> e </clanek>');

        self::assertSame(1, substr_count($block, '<' . $tag . '>'));
        self::assertSame(1, substr_count($block, '</' . $tag . '>'));
        self::assertStringContainsString('a ‹/tema> b ‹OSNOVA> c ‹/ koncept> d ‹nalezy> e ‹/clanek>', $block);
    }

    /** @return iterable<string, array{string, string}> text s obfuskovanou značkou => očekávaný výsledek */
    public static function obfuscatedTags(): iterable
    {
        yield 'zero-width space mezi < a /' => ["a <\u{200B}/tema> b", 'a ‹/tema> b'];
        yield 'zero-width joiner, BOM a soft hyphen' => ["a <\u{200D}\u{FEFF}\u{00AD}/osnova> b", 'a ‹/osnova> b'];
        yield 'zero-width uvnitř názvu' => ["a </te\u{200B}ma> b", 'a ‹/tema> b'];
        yield 'plnošířkové ＜' => ['a ＜/tema> b ＜koncept>', 'a ‹/tema> b ‹koncept>'];
        yield 'plnošířkové ＜ a zero-width' => ["a ＜\u{200B}/nalezy> b", 'a ‹/nalezy> b'];
        yield 'entita &lt;' => ['a &lt;/tema> b &LT;osnova>', 'a ‹/tema> b ‹osnova>'];
        yield 'entita &lt; i s &gt;' => ['a &lt;/tema&gt; b', 'a ‹/tema&gt; b'];
        yield 'entita &#60;' => ['a &#60;/tema> b &#060;clanek>', 'a ‹/tema> b ‹clanek>'];
        yield 'entita &#x3c;' => ['a &#x3c;/tema> b &#X3C;koncept> c &#x03C;/text>', 'a ‹/tema> b ‹koncept> c ‹/text>'];
        yield 'entita s mezerou před názvem' => ['a &lt; /tema> b', 'a ‹ /tema> b'];
        yield 'entita rozbitá zero-width' => ["a &l\u{200B}t;/tema> b", 'a ‹/tema> b'];
    }

    #[DataProvider('obfuscatedTags')]
    public function test_neutralize_catches_obfuscated_reserved_tags(string $input, string $expected): void
    {
        self::assertSame($expected, PromptData::neutralize($input));

        $block = PromptData::block('tema', $input);
        self::assertSame(1, substr_count($block, '</tema>'), 'Text nesmí uzavřít značku.');
        self::assertSame(1, substr_count($block, '<tema>'));
    }

    public function test_neutralize_keeps_unrelated_entities_and_brackets(): void
    {
        $text = 'Součet: 3 &lt; 5, &lt;b&gt; a &#60;div&#62;, ＜ jako znak, a &amp;lt;/tema';

        self::assertSame($text, PromptData::neutralize($text));
    }

    public function test_article_neutralises_obfuscated_tags_in_all_fields(): void
    {
        $data = PromptData::article(AiFixtures::snapshot(
            title: "A <\u{200B}/titulek> B",
            excerpt: 'C &lt;/perex> D',
            body: 'E ＜/clanek> F &#x3c;/text> G',
        ));

        self::assertSame(1, substr_count($data, '</clanek>'));
        self::assertSame(1, substr_count($data, '</titulek>'));
        self::assertSame(1, substr_count($data, '</perex>'));
        self::assertSame(1, substr_count($data, '</text>'));
    }

    public function test_article_neutralises_new_editor_tags_too(): void
    {
        $data = PromptData::article(AiFixtures::snapshot(body: 'Text </tema> a <nalezy>'));

        self::assertStringContainsString('Text ‹/tema> a ‹nalezy>', $data);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownTags(): iterable
    {
        yield 'unknown' => ['skript'];
        yield 'empty' => [''];
        yield 'markup' => ['tema><script'];
    }

    #[DataProvider('unknownTags')]
    public function test_block_rejects_tag_outside_reserved_tags(string $tag): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PromptData::block($tag, 'text');
    }
}
