<?php

declare(strict_types=1);

namespace App\Domain\Audit;

interface AuditLogRepository
{
    public function add(AuditEntry $entry): void;
}
