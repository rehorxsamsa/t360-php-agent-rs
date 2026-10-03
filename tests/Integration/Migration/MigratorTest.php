<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Infrastructure\Migration\InvalidMigration;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    private function migrator(string $fixtureSet): Migrator
    {
        return new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/fixtures/' . $fixtureSet);
    }

    private function countMigrationRows(): int
    {
        return TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM migrations');
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function test_migrate_runs_files_in_name_order_and_second_run_is_noop(): void
    {
        $migrator = $this->migrator('valid');

        $executed = $migrator->migrate();

        self::assertSame(['202601010001_create_fixture_a', '202601010002_create_fixture_b'], $executed);
        self::assertSame(2, $this->countMigrationRows());
        self::assertTrue($this->tableExists('fixture_a'));
        self::assertTrue($this->tableExists('fixture_b'));
        self::assertSame([], $migrator->migrate());
    }

    public function test_rollback_one_step_reverts_only_the_last_migration(): void
    {
        $migrator = $this->migrator('valid');
        $migrator->migrate();

        $reverted = $migrator->rollback(1);

        self::assertSame(['202601010002_create_fixture_b'], $reverted);
        self::assertTrue($this->tableExists('fixture_a'));
        self::assertFalse($this->tableExists('fixture_b'));
        self::assertSame(1, $this->countMigrationRows());
    }

    public function test_rollback_more_steps_than_executed_reverts_the_rest_without_error(): void
    {
        $migrator = $this->migrator('valid');
        $migrator->migrate();

        $reverted = $migrator->rollback(5);

        self::assertSame(['202601010002_create_fixture_b', '202601010001_create_fixture_a'], $reverted);
        self::assertSame(0, $this->countMigrationRows());
    }

    public function test_failing_migration_keeps_previous_ones_and_propagates_exception(): void
    {
        $migrator = $this->migrator('failing');

        try {
            $migrator->migrate();
            self::fail('Očekávána výjimka z up().');
        } catch (\RuntimeException $exception) {
            self::assertSame('migrace selhala', $exception->getMessage());
        }

        self::assertSame(1, $this->countMigrationRows());
        $names = TestDatabase::column($this->pdo, 'SELECT name FROM migrations');
        self::assertSame(['202601010001_create_fixture_a'], $names);
    }

    public function test_status_lists_executed_and_pending_migrations(): void
    {
        $migrator = $this->migrator('valid');
        $migrator->migrate();
        $migrator->rollback(1);

        $status = $migrator->status();

        self::assertCount(2, $status);
        self::assertSame('202601010001_create_fixture_a', $status[0]->name);
        self::assertNotNull($status[0]->executedAt);
        self::assertSame('202601010002_create_fixture_b', $status[1]->name);
        self::assertNull($status[1]->executedAt);
    }

    public function test_file_with_invalid_name_throws_invalid_migration_naming_the_file(): void
    {
        try {
            $this->migrator('invalid_name')->migrate();
            self::fail('Očekávána výjimka InvalidMigration.');
        } catch (InvalidMigration $exception) {
            self::assertStringContainsString('moje.php', $exception->getMessage());
        }
    }

    public function test_file_not_returning_migration_throws_invalid_migration_naming_the_file(): void
    {
        try {
            $this->migrator('not_migration')->status();
            self::fail('Očekávána výjimka InvalidMigration.');
        } catch (InvalidMigration $exception) {
            self::assertStringContainsString('202601010001_returns_nothing_useful', $exception->getMessage());
        }
    }

    public function test_test_database_helper_leaves_database_without_tables(): void
    {
        $this->migrator('valid')->migrate();

        $pdo = TestDatabase::reset();

        self::assertSame(
            0,
            TestDatabase::count($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'),
        );
    }
}
