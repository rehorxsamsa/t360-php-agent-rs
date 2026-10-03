<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogRepository;

final class InMemoryAuditLogRepository implements AuditLogRepository
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    public function add(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<AuditEntry> */
    public function byAction(AuditAction $action): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(AuditEntry $entry): bool => $entry->action === $action,
        ));
    }
}
