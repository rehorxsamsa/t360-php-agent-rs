<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/** Souhrn spotřeby AI za období: počet volání, tokeny (všechny čtyři druhy) a cena v USD. */
final readonly class AiUsageTotals
{
    public function __construct(
        public int $calls,
        public int $tokens,
        public float $costUsd,
    ) {}
}
