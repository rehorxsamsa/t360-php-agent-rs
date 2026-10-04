<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Audit\AuditLogRecord;

/** Jedna stránka výpisu audit logu (vědomě bez sjednocení s ArticlePage – backlog M8b). */
final readonly class AuditLogPage
{
    /** @param list<AuditLogRecord> $records */
    public function __construct(
        public array $records,
        public int $page,
        public int $totalPages,
        public int $total,
    ) {}

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages;
    }
}
