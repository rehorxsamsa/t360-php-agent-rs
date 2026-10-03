<?php

declare(strict_types=1);

namespace App\Infrastructure\Time;

use App\Domain\Time\Clock;

/** Systémové hodiny; časová zóna vychází z `date.timezone` (Europe/Prague). */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
