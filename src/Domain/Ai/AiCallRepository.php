<?php

declare(strict_types=1);

namespace App\Domain\Ai;

interface AiCallRepository
{
    public function add(AiCall $call): void;

    /** Souhrn volání s `created_at >= $since`. */
    public function usageSince(\DateTimeImmutable $since): AiUsageTotals;

    /** @return list<AiCall> nejnovější první (`created_at DESC, id DESC`) */
    public function recent(int $limit): array;
}
