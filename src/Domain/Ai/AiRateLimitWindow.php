<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/** Obsah okna: počet záznamů a čas nejstaršího z nich (`null`, je-li okno prázdné). */
final readonly class AiRateLimitWindow
{
    public function __construct(public int $count, public ?\DateTimeImmutable $oldest) {}
}
