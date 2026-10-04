<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogFilter;
use App\Domain\Audit\AuditLogRecord;
use App\Domain\Audit\AuditLogRepository;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoAuditLogRepository;
use App\Tests\Integration\StatementCounter;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Zápis auditu (plán 003) a čtení pro stránku audit logu (plán 007, AC 13–15, ADR-0007:
 * `audit_log.created_at` je v UTC, repozitář převádí hranice filtru i načtené časy z/do Europe/Prague).
 */
final class PdoAuditLogRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoAuditLogRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();
        $this->repository = new PdoAuditLogRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    public function test_implements_domain_interface(): void
    {
        self::assertInstanceOf(AuditLogRepository::class, $this->repository);
    }

    public function test_failed_login_entry_is_stored_with_null_user_and_truncated_summary(): void
    {
        $this->repository->add(new AuditEntry(AuditAction::LoginFailed, null, summary: str_repeat('x', 300), ipAddress: '172.18.0.1'));

        $rows = TestDatabase::rows($this->pdo, 'SELECT * FROM audit_log');
        self::assertCount(1, $rows);
        self::assertSame('auth.login_failed', $rows[0]['action']);
        self::assertSame(1, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM audit_log WHERE user_id IS NULL'));
        self::assertSame(255, mb_strlen($rows[0]['summary']));
        self::assertSame('172.18.0.1', $rows[0]['ip_address']);
        self::assertNotSame('', $rows[0]['created_at']);
    }

    public function test_entry_with_user_and_entity_is_stored(): void
    {
        $this->pdo->exec("INSERT INTO users (email, display_name, password_hash) VALUES ('a@example.cz', 'A', 'h')");
        $userId = (int) $this->pdo->lastInsertId();

        $this->repository->add(new AuditEntry(AuditAction::UserCreated, $userId, 'user', $userId, 'a@example.cz'));

        $rows = TestDatabase::rows($this->pdo, 'SELECT * FROM audit_log');
        self::assertSame((string) $userId, $rows[0]['user_id']);
        self::assertSame('user', $rows[0]['entity_type']);
        self::assertSame((string) $userId, $rows[0]['entity_id']);
        self::assertSame('user.created', $rows[0]['action']);
    }

    public function test_sql_in_summary_is_stored_as_data(): void
    {
        $this->repository->add(new AuditEntry(AuditAction::LoginFailed, summary: "'); DROP TABLE audit_log; --"));

        $rows = TestDatabase::rows($this->pdo, 'SELECT summary FROM audit_log');
        self::assertSame("'); DROP TABLE audit_log; --", $rows[0]['summary']);
    }

    // ---------------------------------------------------------------- plán 007: pomocníci

    private static function prague(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Prague'));
    }

    private function insertUser(string $displayName): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (email, display_name, password_hash) VALUES (:email, :name, :hash)');
        $statement->execute(['email' => bin2hex(random_bytes(4)) . '@example.cz', 'name' => $displayName, 'hash' => 'h']);

        return (int) $this->pdo->lastInsertId();
    }

    /** Řádek auditu s explicitním `created_at` v UTC (jak ho zapisuje databáze). */
    private function insertUtc(
        string $createdAtUtc,
        string $action = 'auth.login',
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        string $summary = '',
        ?string $ip = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (created_at, action, user_id, entity_type, entity_id, summary, ip_address)
             VALUES (:created_at, :action, :user_id, :entity_type, :entity_id, :summary, :ip)',
        );
        $statement->execute([
            'created_at' => $createdAtUtc,
            'action' => $action,
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => $summary,
            'ip' => $ip,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<AuditLogRecord> $records
     * @return list<int>
     */
    private static function ids(array $records): array
    {
        return array_map(static fn(AuditLogRecord $record): int => $record->id, $records);
    }

    // ---------------------------------------------------------------- AC 13: zóna spojení

    public function test_connection_time_zone_is_utc(): void
    {
        self::assertSame(['+00:00'], TestDatabase::column($this->pdo, 'SELECT @@session.time_zone'));
    }

    public function test_added_entry_has_created_at_in_utc(): void
    {
        $this->repository->add(new AuditEntry(AuditAction::Logout, summary: 'utc'));

        self::assertLessThan(
            5,
            TestDatabase::count($this->pdo, 'SELECT ABS(TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP(6))) FROM audit_log'),
        );
    }

    // ---------------------------------------------------------------- AC 14: převod při čtení

    public function test_from_bound_is_converted_from_prague_to_utc(): void
    {
        $beforeMidnight = $this->insertUtc('2026-10-02 21:59:59');
        $midnight = $this->insertUtc('2026-10-02 22:00:00');
        $winter = $this->insertUtc('2026-12-01 10:00:00');
        $filter = new AuditLogFilter(from: self::prague('2026-10-03 00:00:00'));

        $records = $this->repository->search($filter, 50, 0);

        self::assertSame([$winter, $midnight], self::ids($records));
        self::assertNotContains($beforeMidnight, self::ids($records));
        self::assertSame('2026-12-01 11:00:00 +01:00', $records[0]->createdAt->format('Y-m-d H:i:s P'));
        self::assertSame('2026-10-03 00:00:00 +02:00', $records[1]->createdAt->format('Y-m-d H:i:s P'));
        self::assertSame('Europe/Prague', $records[0]->createdAt->getTimezone()->getName());
        self::assertSame(2, $this->repository->count($filter));
    }

    public function test_until_bound_is_exclusive_and_converted(): void
    {
        $beforeMidnight = $this->insertUtc('2026-10-02 21:59:59');
        $this->insertUtc('2026-10-02 22:00:00');
        $filter = new AuditLogFilter(until: self::prague('2026-10-03 00:00:00'));

        self::assertSame([$beforeMidnight], self::ids($this->repository->search($filter, 50, 0)));
        self::assertSame(1, $this->repository->count($filter));
    }

    public function test_day_of_dst_change_covers_twenty_five_hours(): void
    {
        $this->insertUtc('2026-10-24 21:59:59');
        $first = $this->insertUtc('2026-10-24 22:00:00');
        $last = $this->insertUtc('2026-10-25 22:59:59');
        $this->insertUtc('2026-10-25 23:00:00');
        $filter = new AuditLogFilter(from: self::prague('2026-10-25 00:00:00'), until: self::prague('2026-10-26 00:00:00'));

        self::assertSame([$last, $first], self::ids($this->repository->search($filter, 50, 0)));
    }

    // ---------------------------------------------------------------- AC 15: JOIN, pořadí, limit, filtr akce

    public function test_record_is_hydrated_with_user_name_from_join(): void
    {
        $userId = $this->insertUser('Eva Nováková');
        $withUser = $this->insertUtc('2026-10-03 08:00:00', 'article.created', $userId, 'article', 5, 'Titulek', '172.19.0.1');
        $anonymous = $this->insertUtc('2026-10-03 07:00:00', 'auth.login_failed', summary: 'x@example.cz');

        $records = $this->repository->search(new AuditLogFilter(), 50, 0);

        self::assertSame([$withUser, $anonymous], self::ids($records));
        self::assertSame('article.created', $records[0]->action);
        self::assertSame($userId, $records[0]->userId);
        self::assertSame('Eva Nováková', $records[0]->userName);
        self::assertSame('article', $records[0]->entityType);
        self::assertSame(5, $records[0]->entityId);
        self::assertSame('Titulek', $records[0]->summary);
        self::assertSame('172.19.0.1', $records[0]->ipAddress);
        self::assertSame('2026-10-03 10:00:00 +02:00', $records[0]->createdAt->format('Y-m-d H:i:s P'));
        self::assertNull($records[1]->userId);
        self::assertNull($records[1]->userName);
        self::assertNull($records[1]->entityType);
        self::assertNull($records[1]->entityId);
        self::assertNull($records[1]->ipAddress);
        self::assertSame('x@example.cz', $records[1]->summary);
    }

    public function test_same_time_orders_by_higher_id_first(): void
    {
        $first = $this->insertUtc('2026-10-03 08:00:00');
        $second = $this->insertUtc('2026-10-03 08:00:00');
        $older = $this->insertUtc('2026-10-03 07:00:00');

        self::assertSame([$second, $first, $older], self::ids($this->repository->search(new AuditLogFilter(), 50, 0)));
    }

    public function test_limit_and_offset(): void
    {
        $ids = [];
        foreach (['01', '02', '03', '04'] as $hour) {
            $ids[] = $this->insertUtc(sprintf('2026-10-03 %s:00:00', $hour));
        }

        self::assertSame([$ids[2], $ids[1]], self::ids($this->repository->search(new AuditLogFilter(), 2, 1)));
        self::assertSame(4, $this->repository->count(new AuditLogFilter()));
    }

    public function test_action_filter_combined_with_dates(): void
    {
        $login = $this->insertUtc('2026-10-03 08:00:00', 'auth.login');
        $this->insertUtc('2026-10-03 09:00:00', 'auth.logout');
        $this->insertUtc('2026-10-01 08:00:00', 'auth.login');
        $filter = new AuditLogFilter(
            action: AuditAction::LoginSucceeded,
            from: self::prague('2026-10-03 00:00:00'),
            until: self::prague('2026-10-04 00:00:00'),
        );

        self::assertSame([$login], self::ids($this->repository->search($filter, 50, 0)));
        self::assertSame(1, $this->repository->count($filter));
    }

    public function test_unknown_action_from_database_is_read_verbatim(): void
    {
        $this->insertUtc('2026-10-03 08:00:00', 'legacy.x');

        $records = $this->repository->search(new AuditLogFilter(), 50, 0);

        self::assertSame('legacy.x', $records[0]->action);
        self::assertSame('legacy.x', $records[0]->actionLabel());
    }

    public function test_empty_table_gives_zero_and_empty_list(): void
    {
        self::assertSame(0, $this->repository->count(new AuditLogFilter()));
        self::assertSame([], $this->repository->search(new AuditLogFilter(), 50, 0));
    }

    public function test_each_read_is_single_query(): void
    {
        $userId = $this->insertUser('Eva');
        for ($i = 0; $i < 5; ++$i) {
            $this->insertUtc(sprintf('2026-10-03 0%d:00:00', $i), 'auth.login', $userId);
        }
        $filter = new AuditLogFilter(action: AuditAction::LoginSucceeded, from: self::prague('2026-10-01 00:00:00'));

        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->search($filter, 50, 0)));
        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->count($filter)));
    }
}
