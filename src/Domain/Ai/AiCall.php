<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/** Metadata jednoho volání AI (bez obsahu promptu a odpovědi). */
final readonly class AiCall
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public ?int $userId,
        public string $exampleId,
        public string $provider,
        public string $model,
        public TokenUsage $usage,
        public float $costUsd,
        public int $durationMs,
        public int $attempts,
        public AiCallStatus $status,
        public ?string $errorType = null,
        public ?string $stopReason = null,
        public ?string $requestId = null,
    ) {}
}
