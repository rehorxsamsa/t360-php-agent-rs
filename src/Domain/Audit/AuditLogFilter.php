<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Filtr výpisu audit logu. Hranice jsou okamžiky v zóně aplikace: `from` včetně, `until` vyjma
 * (celý den „do“ = následující den 00:00:00). Převod do UTC dělá repozitář (ADR-0007).
 */
final readonly class AuditLogFilter
{
    /** @throws \InvalidArgumentException `from` není před `until` */
    public function __construct(
        public ?AuditAction $action = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $until = null,
    ) {
        if ($from !== null && $until !== null && $from >= $until) {
            throw new \InvalidArgumentException('Začátek filtru musí být před jeho koncem.');
        }
    }

    public function isEmpty(): bool
    {
        return $this->action === null && $this->from === null && $this->until === null;
    }
}
