<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Ai\AiRateLimitHitRepository;
use App\Domain\Ai\AiRateLimitWindow;

/**
 * Záznamy rate limitu AI v paměti (plán 013). `windowSince` počítá stejně jako SQL v PdoAiRateLimitHitRepository:
 * jen záznamy uživatele a kbelíku s `created_at > $since` (ostrá nerovnost), `oldest` = nejmenší čas, prázdné okno = null.
 */
final class InMemoryAiRateLimitHitRepository implements AiRateLimitHitRepository
{
    /** @var list<array{userId: int, bucket: string, at: \DateTimeImmutable}> v pořadí zápisu */
    public array $hits = [];

    public int $windowCalls = 0;

    public function add(int $userId, string $bucket, \DateTimeImmutable $at): void
    {
        $this->hits[] = ['userId' => $userId, 'bucket' => $bucket, 'at' => $at];
    }

    public function windowSince(int $userId, string $bucket, \DateTimeImmutable $since): AiRateLimitWindow
    {
        ++$this->windowCalls;
        $count = 0;
        $oldest = null;
        foreach ($this->hits as $hit) {
            if ($hit['userId'] !== $userId || $hit['bucket'] !== $bucket || $hit['at'] <= $since) {
                continue;
            }
            ++$count;
            if ($oldest === null || $hit['at'] < $oldest) {
                $oldest = $hit['at'];
            }
        }

        return new AiRateLimitWindow($count, $oldest);
    }

    /** Pomocník pro testy: počet záznamů daného kbelíku (všech uživatelů). */
    public function countBucket(string $bucket): int
    {
        return \count(array_filter($this->hits, static fn(array $hit): bool => $hit['bucket'] === $bucket));
    }
}
