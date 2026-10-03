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
}
