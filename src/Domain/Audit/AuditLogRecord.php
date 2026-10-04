<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Řádek výpisu audit logu (čtení). Čas je v zóně aplikace (Europe/Prague) – převod z UTC dělá repozitář (ADR-0007).
 * Akce je surová hodnota z DB, aby výpis nespadl na akci z budoucí verze aplikace.
 */
final readonly class AuditLogRecord
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $createdAt,
        public string $action,
        public ?int $userId,
        public ?string $userName,
        public ?string $entityType,
        public ?int $entityId,
        public string $summary,
        public ?string $ipAddress,
    ) {}

    /** Český popisek akce; neznámá akce se zobrazí doslova. */
    public function actionLabel(): string
    {
        return AuditAction::tryFrom($this->action)?->label() ?? $this->action;
    }

    /** Dotčený objekt: „článek #5“, „uživatel #3“, „{typ} #N“; bez typu „—“. */
    public function entityLabel(): string
    {
        if ($this->entityType === null || $this->entityType === '') {
            return '—';
        }

        $name = match ($this->entityType) {
            'article' => 'článek',
            'user' => 'uživatel',
            default => $this->entityType,
        };

        return $this->entityId === null ? $name : sprintf('%s #%d', $name, $this->entityId);
    }
}
