<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CzechDateTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function dates(): iterable
    {
        yield 'new year' => ['2026-01-01', '1. ledna 2026'];
        yield 'with time' => ['2026-10-03 09:05', '3. října 2026'];
        yield 'new year eve' => ['2026-12-31', '31. prosince 2026'];
        yield 'february' => ['2026-02-15', '15. února 2026'];
        yield 'march' => ['2026-03-02', '2. března 2026'];
        yield 'april' => ['2026-04-09', '9. dubna 2026'];
        yield 'may' => ['2026-05-10', '10. května 2026'];
        yield 'june' => ['2026-06-11', '11. června 2026'];
        yield 'july' => ['2026-07-12', '12. července 2026'];
        yield 'august' => ['2026-08-13', '13. srpna 2026'];
        yield 'september' => ['2026-09-12', '12. září 2026'];
        yield 'november' => ['2026-11-30', '30. listopadu 2026'];
    }

    #[DataProvider('dates')]
    public function test_formats_date_with_genitive_month(string $input, string $expected): void
    {
        self::assertSame($expected, czech_date(new \DateTimeImmutable($input)));
    }

    public function test_accepts_mutable_date_time_interface(): void
    {
        self::assertSame('1. ledna 2026', czech_date(new \DateTime('2026-01-01')));
    }
}
