<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/** Limit „nejvýše $limit požadavků za $windowSeconds sekund“ (posuvné okno). */
final readonly class RateLimit
{
    public const int MAX_LIMIT = 9999;
    public const int MAX_WINDOW_SECONDS = 86400;

    public function __construct(public int $limit, public int $windowSeconds)
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Počet požadavků limitu musí být 1 až ' . self::MAX_LIMIT . '.');
        }
        if ($windowSeconds < 1 || $windowSeconds > self::MAX_WINDOW_SECONDS) {
            throw new \InvalidArgumentException('Okno limitu musí být 1 až ' . self::MAX_WINDOW_SECONDS . ' s.');
        }
    }
}
