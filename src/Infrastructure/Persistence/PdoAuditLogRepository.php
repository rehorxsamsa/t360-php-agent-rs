<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogFilter;
use App\Domain\Audit\AuditLogRecord;
use App\Domain\Audit\AuditLogRepository;

/**
 * Audit log v tabulce `audit_log`.
 *
 * Časy (ADR-0007): `audit_log.created_at` plní databáze (`DEFAULT CURRENT_TIMESTAMP(6)`), je tedy v UTC
 * (zónu spojení připíchne ConnectionFactory). Převod dělá jen tento repozitář: hranice filtru převede
 * z pražského času do UTC, načtené časy vrací v zóně aplikace (`date_default_timezone_get()`).
 * Kdo píše jiný dotaz nad `audit_log`, musí převádět stejně.
 */
final readonly class PdoAuditLogRepository implements AuditLogRepository
{
    private const string DATABASE_TIMEZONE = 'UTC';
    private const string DATABASE_FORMAT = 'Y-m-d H:i:s.u';

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

    public function count(AuditLogFilter $filter): int
    {
        [$where, $parameters] = $this->where($filter);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log a' . $where);
        $statement->execute($parameters);

        $total = $statement->fetchColumn();
        if (!is_numeric($total)) {
            throw new \UnexpectedValueException('Počet záznamů audit logu má neočekávaný tvar.');
        }

        return (int) $total;
    }

    public function search(AuditLogFilter $filter, int $limit, int $offset): array
    {
        [$where, $parameters] = $this->where($filter);
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.created_at, a.action, a.user_id, u.display_name, a.entity_type, a.entity_id,'
            . ' a.summary, a.ip_address'
            . ' FROM audit_log a'
            . ' LEFT JOIN users u ON u.id = a.user_id'
            . $where
            . ' ORDER BY a.created_at DESC, a.id DESC'
            . ' LIMIT :limit OFFSET :offset',
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', max(0, $limit), \PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $statement->execute();

        $records = [];
        foreach ($statement->fetchAll() as $row) {
            $records[] = $this->hydrate($row);
        }

        return $records;
    }

    /**
     * WHERE jen z pevných fragmentů; hodnoty ze vstupu vždy jako vázané parametry (každý v dotazu jednou).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function where(AuditLogFilter $filter): array
    {
        $conditions = [];
        $parameters = [];

        if ($filter->action !== null) {
            $conditions[] = 'a.action = :action';
            $parameters['action'] = $filter->action->value;
        }
        if ($filter->from !== null) {
            $conditions[] = 'a.created_at >= :from';
            $parameters['from'] = $this->toDatabase($filter->from);
        }
        if ($filter->until !== null) {
            $conditions[] = 'a.created_at < :until';
            $parameters['until'] = $this->toDatabase($filter->until);
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $parameters];
    }

    /** Okamžik z aplikace → text sloupce v UTC. */
    private function toDatabase(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone(self::DATABASE_TIMEZONE))->format(self::DATABASE_FORMAT);
    }

    /** Text sloupce v UTC → okamžik v zóně aplikace. */
    private function fromDatabase(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone(self::DATABASE_TIMEZONE))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function hydrate(mixed $row): AuditLogRecord
    {
        if (!is_array($row)
            || !is_numeric($row['id'] ?? null)
            || !is_string($row['created_at'] ?? null)
            || !is_string($row['action'] ?? null)
            || !is_string($row['summary'] ?? null)
        ) {
            throw new \UnexpectedValueException('Řádek tabulky audit_log má neočekávaný tvar.');
        }

        return new AuditLogRecord(
            (int) $row['id'],
            $this->fromDatabase($row['created_at']),
            $row['action'],
            $this->nullableInt($row['user_id'] ?? null),
            $this->nullableString($row['display_name'] ?? null),
            $this->nullableString($row['entity_type'] ?? null),
            $this->nullableInt($row['entity_id'] ?? null),
            $row['summary'],
            $this->nullableString($row['ip_address'] ?? null),
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Číselný sloupec audit_log má neočekávaný tvar.');
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Textový sloupec audit_log má neočekávaný tvar.');
        }

        return $value;
    }
}
