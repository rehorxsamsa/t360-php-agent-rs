<?php

declare(strict_types=1);

namespace App\Application\Ai;

use App\Domain\Ai\AiRateLimitHitRepository;
use App\Domain\Ai\RateLimit;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\Time\Clock;

/**
 * Rate limit AI tras (plán 013, ADR-0013): posuvné okno se záznamy na uživatele a kbelík.
 * Okno = záznamy s `created_at > now − okno`. Při plném okně se zapíše audit a požadavek se odmítne
 * (odmítnutí se do okna nepočítá), jinak se zapíše nový záznam. Počet a zápis nejsou atomické (přijato, R2).
 */
final readonly class AiRateLimiter
{
    private const int MICROSECONDS = 1_000_000;

    public function __construct(
        private AiRateLimitHitRepository $hits,
        private AuditLogRepository $audit,
        private Clock $clock,
        private RateLimit $standard,
        private RateLimit $heavy,
    ) {}

    /**
     * @param string $target popis požadavku do auditu, např. `POST /admin/ai/01`
     * @throws AiRateLimitExceeded okno kbelíku je plné
     */
    public function consume(int $userId, AiRateBucket $bucket, ?string $ipAddress, string $target): void
    {
        $limit = $this->limitFor($bucket);
        $now = $this->clock->now();
        $window = $this->hits->windowSince(
            $userId,
            $bucket->value,
            $now->modify(sprintf('-%d seconds', $limit->windowSeconds)),
        );

        if ($window->count < $limit->limit) {
            $this->hits->add($userId, $bucket->value, $now);

            return;
        }

        $this->audit->add(new AuditEntry(
            AuditAction::AiRateLimited,
            $userId,
            summary: sprintf('Limit %s %d za %d s: %s', $bucket->label(), $limit->limit, $limit->windowSeconds, $target),
            ipAddress: $ipAddress,
        ));

        throw new AiRateLimitExceeded(
            self::retryAfterSeconds($window->oldest ?? $now, $limit->windowSeconds, $now),
            $limit->limit,
            $limit->windowSeconds,
        );
    }

    private function limitFor(AiRateBucket $bucket): RateLimit
    {
        return match ($bucket) {
            AiRateBucket::Standard => $this->standard,
            AiRateBucket::Heavy => $this->heavy,
        };
    }

    /** `ceil(oldest + okno − now)` v celých sekundách, nejméně 1 (počítáno v mikrosekundách bez zaokrouhlovací chyby float). */
    private static function retryAfterSeconds(\DateTimeImmutable $oldest, int $windowSeconds, \DateTimeImmutable $now): int
    {
        $remaining = self::microseconds($oldest) + $windowSeconds * self::MICROSECONDS - self::microseconds($now);
        if ($remaining <= 0) {
            return 1;
        }

        return max(1, intdiv($remaining + self::MICROSECONDS - 1, self::MICROSECONDS));
    }

    private static function microseconds(\DateTimeImmutable $time): int
    {
        return $time->getTimestamp() * self::MICROSECONDS + (int) $time->format('u');
    }
}
