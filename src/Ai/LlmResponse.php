<?php

declare(strict_types=1);

namespace App\Ai;

use App\Domain\Ai\TokenUsage;

/** Odpověď modelu: text z bloků `text`, spotřeba tokenů a metadata volání. */
final readonly class LlmResponse
{
    public function __construct(
        public string $text,
        public string $model,
        public string $stopReason,
        public TokenUsage $usage,
        public string $provider,
        public ?string $requestId = null,
        public int $attempts = 1,
        public ?float $costUsd = null,
    ) {}

    public function withCost(float $costUsd): self
    {
        return new self(
            $this->text,
            $this->model,
            $this->stopReason,
            $this->usage,
            $this->provider,
            $this->requestId,
            $this->attempts,
            $costUsd,
        );
    }
}
