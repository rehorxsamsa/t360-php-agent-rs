<?php

declare(strict_types=1);

namespace App\Domain\Audit;

interface AuditLogRepository
{
    public function add(AuditEntry $entry): void;

    /** Počet záznamů odpovídajících filtru. */
    public function count(AuditLogFilter $filter): int;

    /**
     * Záznamy odpovídající filtru, nejnovější první (`createdAt DESC, id DESC`).
     *
     * @return list<AuditLogRecord>
     */
    public function search(AuditLogFilter $filter, int $limit, int $offset): array;
}
