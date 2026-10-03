<?php

declare(strict_types=1);

namespace App\Domain\Time;

/** Zdroj aktuálního času (v testech nahraditelný pevným časem). */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
