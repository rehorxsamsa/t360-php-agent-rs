<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Article;

use App\Domain\Article\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validSlugs(): iterable
    {
        yield 'dashed words' => ['prvni-clanek'];
        yield 'single letter' => ['a'];
        yield 'with digits' => ['clanek-2026'];
        yield 'only digits' => ['2026'];
        yield 'max length' => [str_repeat('a', 220)];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSlugs(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Prvni-clanek'];
        yield 'double dash' => ['prvni--clanek'];
        yield 'leading dash' => ['-prvni'];
        yield 'trailing dash' => ['prvni-'];
        yield 'space inside' => ['prvni clanek'];
        yield 'trailing space' => ['prvni-clanek '];
        yield 'trailing newline' => ["prvni-clanek\n"];
        yield 'diacritics' => ['článek'];
        yield 'slash' => ['a/b'];
        yield 'too long' => [str_repeat('a', 221)];
    }

    #[DataProvider('validSlugs')]
    public function test_valid_slug_is_accepted(string $slug): void
    {
        self::assertTrue(Slug::isValid($slug));
    }

    #[DataProvider('invalidSlugs')]
    public function test_invalid_slug_is_rejected(string $slug): void
    {
        self::assertFalse(Slug::isValid($slug));
    }

    public function test_max_length_matches_database_column(): void
    {
        self::assertSame(220, Slug::MAX_LENGTH);
    }

    /** @return iterable<string, array{string, string}> plán 005, AC 1 */
    public static function textsToSlugs(): iterable
    {
        yield 'czech title with html and punctuation' => ['Šablony & escapování: <script> se nespustí', 'sablony-escapovani-script-se-nespusti'];
        yield 'all czech diacritics' => ['Žluťoučký kůň úpěl ďábelské ódy', 'zlutoucky-kun-upel-dabelske-ody'];
        yield 'surrounding spaces, dot and em dash' => ['  PHP 8.4 — novinky!  ', 'php-8-4-novinky'];
        yield 'repeated dashes collapse' => ['Ahoj---světe', 'ahoj-svete'];
        yield 'german umlauts and sharp s' => ['Ärger über Straße', 'arger-uber-strasse'];
        yield 'uppercase czech' => ['ŘEŘICHA', 'rericha'];
        yield 'slovak letters' => ['Ľúbostná pieseň ĺ ŕ ô', 'lubostna-piesen-l-r-o'];
        yield 'empty falls back' => ['', 'clanek'];
        yield 'only punctuation falls back' => ['!!!', 'clanek'];
        yield 'non-latin script falls back' => ['日本語', 'clanek'];
        yield 'long text is cut to base length' => [str_repeat('a', 300), str_repeat('a', 200)];
    }

    #[DataProvider('textsToSlugs')]
    public function test_from_text_builds_expected_valid_slug(string $text, string $expected): void
    {
        $slug = Slug::fromText($text);

        self::assertSame($expected, $slug);
        self::assertTrue(Slug::isValid($slug));
    }

    public function test_from_text_cut_never_ends_with_dash(): void
    {
        $slug = Slug::fromText(str_repeat('ab ', 100));

        self::assertLessThanOrEqual(200, strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
        self::assertStringStartsWith('ab-ab-', $slug);
        self::assertTrue(Slug::isValid($slug));
    }

    public function test_from_text_cut_at_dash_position_drops_trailing_dash(): void
    {
        // 199 znaků „a“ + mezera + další slovo: řez na 200 by skončil pomlčkou.
        $slug = Slug::fromText(str_repeat('a', 199) . ' konec');

        self::assertSame(str_repeat('a', 199), $slug);
        self::assertTrue(Slug::isValid($slug));
    }

    public function test_base_max_length_leaves_room_for_numeric_suffix(): void
    {
        self::assertSame(200, Slug::BASE_MAX_LENGTH);
        self::assertLessThan(Slug::MAX_LENGTH, Slug::BASE_MAX_LENGTH);
    }

    /** @return iterable<string, array{string, list<string>, string}> plán 005, AC 2 */
    public static function uniqueCases(): iterable
    {
        yield 'nothing taken' => ['clanek', [], 'clanek'];
        yield 'base taken' => ['clanek', ['clanek'], 'clanek-2'];
        yield 'base and two suffixes taken' => ['clanek', ['clanek', 'clanek-2', 'clanek-3'], 'clanek-4'];
        yield 'only suffix taken keeps base' => ['clanek', ['clanek-2'], 'clanek'];
        yield 'first gap is used' => ['clanek', ['clanek', 'clanek-3'], 'clanek-2'];
        yield 'similar slugs are not collisions' => ['clanek', ['clanek', 'clanek-x', 'clanekx'], 'clanek-2'];
    }

    /** @param list<string> $taken */
    #[DataProvider('uniqueCases')]
    public function test_unique_among_returns_first_free_slug(string $base, array $taken, string $expected): void
    {
        self::assertSame($expected, Slug::uniqueAmong($base, $taken));
    }

    public function test_unique_among_result_of_max_base_is_still_valid(): void
    {
        $base = str_repeat('a', Slug::BASE_MAX_LENGTH);

        $slug = Slug::uniqueAmong($base, [$base]);

        self::assertSame($base . '-2', $slug);
        self::assertTrue(Slug::isValid($slug));
    }
}
