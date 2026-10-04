<?php

declare(strict_types=1);

namespace App\Ai\Cost;

use App\Domain\Ai\TokenUsage;

/** Ceník a schopnosti jednoho modelu (ceny v USD za milion tokenů). */
final readonly class ModelInfo
{
    private const int TOKENS_PER_UNIT = 1_000_000;

    public function __construct(
        public string $id,
        public float $inputPerMTok,
        public float $outputPerMTok,
        public float $cacheWritePerMTok,
        public float $cacheReadPerMTok,
        public bool $supportsEffort,
    ) {}

    /** Cena volání v USD, zaokrouhlená na 6 desetinných míst. */
    public function cost(TokenUsage $usage): float
    {
        $total = $usage->input * $this->inputPerMTok
            + $usage->output * $this->outputPerMTok
            + $usage->cacheWrite * $this->cacheWritePerMTok
            + $usage->cacheRead * $this->cacheReadPerMTok;

        return round($total / self::TOKENS_PER_UNIT, 6);
    }
}
