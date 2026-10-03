<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Time\Clock;

/** Hodiny s pevným časem pro deterministické testy. */
final readonly class FixedClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now) {}

    public static function at(string $dateTime): self
    {
        return new self(new \DateTimeImmutable($dateTime, new \DateTimeZone('Europe/Prague')));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
