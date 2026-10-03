<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogRepository;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoAuditLogRepository;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

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
}
