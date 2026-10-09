<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ai;

use App\Domain\Ai\AiRateLimitWindow;
use App\Domain\Ai\RateLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 013 (tabulka tříd): hodnotové objekty limitu a okna; rozsah 1–9999 požadavků a 1–86 400 s. */
final class RateLimitTest extends TestCase
{
    public function test_keeps_limit_and_window(): void
    {
        $limit = new RateLimit(10, 60);

        self::assertSame(10, $limit->limit);
        self::assertSame(60, $limit->windowSeconds);
    }

    public function test_range_boundaries_are_valid(): void
    {
        self::assertSame(1, new RateLimit(1, 1)->limit);
        self::assertSame(86400, new RateLimit(9999, 86400)->windowSeconds);
    }

    /** @return iterable<string, array{int, int}> */
    public static function outOfRange(): iterable
    {
        yield 'zero count' => [0, 60];
        yield 'negative count' => [-1, 60];
        yield 'count over 9999' => [10000, 60];
        yield 'zero window' => [10, 0];
        yield 'window over a day' => [10, 86401];
    }

    #[DataProvider('outOfRange')]
    public function test_out_of_range_values_are_rejected(int $limit, int $windowSeconds): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimit($limit, $windowSeconds);
    }

    public function test_window_carries_count_and_oldest(): void
    {
        $oldest = new \DateTimeImmutable('2026-10-09 12:00:00.250000', new \DateTimeZone('Europe/Prague'));

        $window = new AiRateLimitWindow(2, $oldest);
        $empty = new AiRateLimitWindow(0, null);

        self::assertSame(2, $window->count);
        self::assertSame($oldest, $window->oldest);
        self::assertSame(0, $empty->count);
        self::assertNull($empty->oldest);
    }
}
