<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\PageNotFound;
use App\Http\PageNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 005, AC 15: sdílené číslo strany (titulní stránka i administrace). */
final class PageNumberTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function validPages(): iterable
    {
        yield 'missing means first' => ['', 1];
        yield 'two' => ['2', 2];
        yield 'six digits' => ['999999', 999999];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPages(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'leading zero' => ['01'];
        yield 'decimal' => ['1.5'];
        yield 'letters' => ['abc'];
        yield 'seven digits' => ['1000000'];
        yield 'trailing newline' => ["2\n"];
    }

    #[DataProvider('validPages')]
    public function test_valid_page_number(string $value, int $expected): void
    {
        self::assertSame($expected, PageNumber::fromQuery($value));
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_page_number_is_not_found(string $value): void
    {
        $this->expectException(PageNotFound::class);

        PageNumber::fromQuery($value);
    }
}
