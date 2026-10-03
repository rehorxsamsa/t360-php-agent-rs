<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogRepository;

final readonly class PdoAuditLogRepository implements AuditLogRepository
{
    public function __construct(private \PDO $pdo) {}

    public function add(AuditEntry $entry): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, summary, ip_address) '
            . 'VALUES (:user_id, :action, :entity_type, :entity_id, :summary, :ip_address)',
        );
        $statement->execute([
            'user_id' => $entry->userId,
            'action' => $entry->action->value,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            'summary' => $entry->summary,
            'ip_address' => $entry->ipAddress,
        ]);
    }
}
