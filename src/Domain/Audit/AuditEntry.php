<?php

declare(strict_types=1);

namespace App\Domain\Audit;

final readonly class AuditEntry
{
    public const int SUMMARY_MAX_LENGTH = 255;

    /** Shrnutí zkrácené na délku sloupce `audit_log.summary`. */
    public string $summary;

    public function __construct(
        public AuditAction $action,
        public ?int $userId = null,
        public ?string $entityType = null,
        public ?int $entityId = null,
        string $summary = '',
        public ?string $ipAddress = null,
    ) {
        $this->summary = mb_substr($summary, 0, self::SUMMARY_MAX_LENGTH);
    }
}
