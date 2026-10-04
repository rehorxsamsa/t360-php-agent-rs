<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\LlmResponse;
use App\Domain\Ai\TokenUsage;

/** Platná strukturovaná odpověď a všechna volání, která k ní vedla (1 nebo 2). */
final readonly class StructuredOutcome
{
    /**
     * @param array<mixed> $data
     * @param non-empty-list<LlmResponse> $responses
     */
    public function __construct(
        public array $data,
        public array $responses,
    ) {}

    public function usage(): TokenUsage
    {
        $total = new TokenUsage(0, 0);
        foreach ($this->responses as $response) {
            $total = $total->plus($response->usage);
        }

        return $total;
    }

    public function costUsd(): float
    {
        $total = 0.0;
        foreach ($this->responses as $response) {
            $total += $response->costUsd ?? 0.0;
        }

        return round($total, 6);
    }

    /** Surový text poslední (platné) odpovědi. */
    public function rawOutput(): string
    {
        return $this->last()->text;
    }

    public function last(): LlmResponse
    {
        return $this->responses[array_key_last($this->responses)];
    }

    public function calls(): int
    {
        return count($this->responses);
    }
}
