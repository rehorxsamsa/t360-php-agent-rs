<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Time\Clock;

/** Hodiny, které test posouvá ručně (např. simulace pomalého běhu). */
final class MutableClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
