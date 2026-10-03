<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\User\Role;
use App\Domain\User\UserRepository;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoUserRepository;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

final class PdoUserRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoUserRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();
        $this->repository = new PdoUserRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    public function test_implements_domain_interface(): void
    {
        self::assertInstanceOf(UserRepository::class, $this->repository);
    }

    public function test_add_then_find_by_email_is_case_insensitive_and_returns_admin_role(): void
    {
        $id = $this->repository->add('admin@example.cz', 'Administrátor', 'hash-1', Role::Admin);

        $user = $this->repository->findByEmail('ADMIN@example.cz');

        self::assertNotNull($user);
        self::assertSame($id, $user->id);
        self::assertSame('admin@example.cz', $user->email);
        self::assertSame('Administrátor', $user->displayName);
        self::assertSame('hash-1', $user->passwordHash);
        self::assertSame(Role::Admin, $user->role);
    }

    public function test_find_by_id_returns_user(): void
    {
        $id = $this->repository->add('admin@example.cz', 'Admin', 'hash-1', Role::Admin);

        $user = $this->repository->findById($id);

        self::assertNotNull($user);
        self::assertSame('admin@example.cz', $user->email);
        self::assertSame(Role::Admin, $user->role);
    }

    public function test_unknown_email_and_id_return_null(): void
    {
        self::assertNull($this->repository->findByEmail('nikdo@example.cz'));
        self::assertNull($this->repository->findById(999));
    }

    public function test_update_password_hash_changes_row(): void
    {
        $id = $this->repository->add('admin@example.cz', 'Admin', 'stary', Role::Admin);

        $this->repository->updatePasswordHash($id, 'novy-hash');

        self::assertSame('novy-hash', $this->repository->findById($id)?->passwordHash);
    }

    public function test_touch_last_login_sets_timestamp(): void
    {
        $id = $this->repository->add('admin@example.cz', 'Admin', 'hash', Role::Admin);
        self::assertSame(1, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM users WHERE last_login_at IS NULL'));

        $this->repository->touchLastLogin($id);

        self::assertSame(1, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM users WHERE last_login_at IS NOT NULL'));
    }

    public function test_sql_metacharacters_in_email_are_treated_as_data(): void
    {
        $this->repository->add('admin@example.cz', 'Admin', 'hash', Role::Admin);

        self::assertNull($this->repository->findByEmail("' OR '1'='1"));
    }
}
