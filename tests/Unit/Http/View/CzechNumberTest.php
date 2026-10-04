<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 006, §2: czech_number() – mezera jako oddělovač tisíců, čárka jako desetinná čárka. */
final class CzechNumberTest extends TestCase
{
    /** @return iterable<string, array{int|float, int, string}> */
    public static function numbers(): iterable
    {
        yield 'zero' => [0, 0, '0'];
        yield 'small' => [600, 0, '600'];
        yield 'thousands' => [1234, 0, '1 234'];
        yield 'limit' => [200000, 0, '200 000'];
        yield 'cost' => [0.007, 6, '0,007000'];
        yield 'cost sum' => [0.006 + 0.001, 6, '0,007000'];
        yield 'rounded' => [0.0000004, 6, '0,000000'];
        yield 'big with decimals' => [12345.5, 2, '12 345,50'];
    }

    #[DataProvider('numbers')]
    public function test_formats_number_in_czech(int|float $value, int $decimals, string $expected): void
    {
        self::assertSame($expected, czech_number($value, $decimals));
    }

    public function test_default_has_no_decimals(): void
    {
        self::assertSame('1 235', czech_number(1234.6));
    }
}
