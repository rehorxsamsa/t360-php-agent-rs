<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogFilter;
use App\Domain\Audit\AuditLogRecord;
use App\Domain\Audit\AuditLogRepository;

/**
 * Audit log v paměti. Zápis (`add`) plní `$entries` (testy M3/M5), čtení (`count`/`search`, plán 007)
 * pracuje nad `$records` se stejnou sémantikou jako SQL v PdoAuditLogRepository: filtr akce,
 * `from` včetně, `until` vyjma, řazení `createdAt DESC, id DESC`, limit/offset.
 */
final class InMemoryAuditLogRepository implements AuditLogRepository
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    /** @var list<AuditLogRecord> záznamy pro čtení (stránka audit logu) */
    public array $records = [];

    public int $countCalls = 0;

    public int $searchCalls = 0;

    /** @var list<array{filter: AuditLogFilter, limit: int, offset: int}> argumenty volání search() */
    public array $searches = [];

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

    public function count(AuditLogFilter $filter): int
    {
        ++$this->countCalls;

        return \count($this->matching($filter));
    }

    /** @return list<AuditLogRecord> */
    public function search(AuditLogFilter $filter, int $limit, int $offset): array
    {
        ++$this->searchCalls;
        $this->searches[] = ['filter' => $filter, 'limit' => $limit, 'offset' => $offset];

        $records = $this->matching($filter);
        usort(
            $records,
            static fn(AuditLogRecord $a, AuditLogRecord $b): int => [$b->createdAt->getTimestamp(), $b->createdAt->format('u'), $b->id]
                <=> [$a->createdAt->getTimestamp(), $a->createdAt->format('u'), $a->id],
        );

        return array_slice($records, $offset, $limit);
    }

    /** @return list<AuditLogRecord> */
    private function matching(AuditLogFilter $filter): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(AuditLogRecord $record): bool => ($filter->action === null || $record->action === $filter->action->value)
                && ($filter->from === null || $record->createdAt >= $filter->from)
                && ($filter->until === null || $record->createdAt < $filter->until),
        ));
    }

    /**
     * Záznam pro čtení; čas v Europe/Prague. Jediné místo, které skládá AuditLogRecord (pojmenované argumenty
     * podle plánu 007, §2).
     */
    public static function record(
        int $id,
        string $createdAt,
        string $action,
        ?int $userId = null,
        ?string $userName = null,
        ?string $entityType = null,
        ?int $entityId = null,
        string $summary = '',
        ?string $ipAddress = '172.19.0.1',
    ): AuditLogRecord {
        return new AuditLogRecord(
            id: $id,
            createdAt: new \DateTimeImmutable($createdAt, new \DateTimeZone('Europe/Prague')),
            action: $action,
            userId: $userId,
            userName: $userName,
            entityType: $entityType,
            entityId: $entityId,
            summary: $summary,
            ipAddress: $ipAddress,
        );
    }

    /** Výchozí sada z kontraktu testovacích dat plánu 007 (#1–#5). */
    public static function withContractRecords(): self
    {
        $repository = new self();
        $repository->records = [
            self::record(1, '2026-10-02 23:59:59', 'auth.login_failed', summary: 'x@example.cz'),
            self::record(2, '2026-10-03 00:00:00', 'auth.login', 7, 'Administrátor'),
            self::record(3, '2026-10-03 23:59:59', 'article.created', 7, 'Administrátor', 'article', 5, 'Titulek [titulek]'),
            self::record(4, '2026-10-04 00:00:00', 'article.deleted', 7, 'Administrátor', 'article', 5),
            self::record(5, '2026-10-04 10:15:30', 'auth.logout', 7, 'Administrátor'),
        ];

        return $repository;
    }
}
