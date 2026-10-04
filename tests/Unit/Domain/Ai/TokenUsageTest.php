<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ai;

use App\Domain\Ai\AiCallStatus;
use App\Domain\Ai\TokenUsage;
use PHPUnit\Framework\TestCase;

/** Plán 006, §2: hodnotový objekt tokenů a stav volání. */
final class TokenUsageTest extends TestCase
{
    public function test_cache_tokens_default_to_zero(): void
    {
        $usage = new TokenUsage(10, 5);

        self::assertSame(10, $usage->input);
        self::assertSame(5, $usage->output);
        self::assertSame(0, $usage->cacheWrite);
        self::assertSame(0, $usage->cacheRead);
    }

    public function test_total_sums_all_four_kinds(): void
    {
        self::assertSame(600, new TokenUsage(100, 200, 150, 150)->total());
    }

    public function test_plus_adds_each_kind(): void
    {
        $sum = new TokenUsage(1, 2, 3, 4)->plus(new TokenUsage(10, 20, 30, 40));

        self::assertSame([11, 22, 33, 44], [$sum->input, $sum->output, $sum->cacheWrite, $sum->cacheRead]);
    }

    /** PŘEDPOKLAD: „vše ≥ 0“ hlídá konstruktor výjimkou \InvalidArgumentException. */
    public function test_negative_tokens_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TokenUsage(-1, 0);
    }

    public function test_status_values_match_database_enum(): void
    {
        self::assertSame('ok', AiCallStatus::Ok->value);
        self::assertSame('error', AiCallStatus::Error->value);
    }
}
