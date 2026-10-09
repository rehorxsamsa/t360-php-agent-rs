<?php

declare(strict_types=1);

namespace App\Domain\Ai;

interface AiRateLimitHitRepository
{
    public function add(int $userId, string $bucket, \DateTimeImmutable $at): void;

    /** Okno záznamů uživatele a kbelíku s `created_at > $since`. */
    public function windowSince(int $userId, string $bucket, \DateTimeImmutable $since): AiRateLimitWindow;
}
