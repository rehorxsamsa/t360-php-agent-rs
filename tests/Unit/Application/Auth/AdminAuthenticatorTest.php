<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\AdminAuthenticator;
use App\Application\Auth\PasswordHasher;
use App\Domain\Audit\AuditAction;
use App\Domain\User\Role;
use App\Domain\User\User;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\TestCase;

final class AdminAuthenticatorTest extends TestCase
{
    private const string PASSWORD = 'spravne-heslo-123';
    private static string $argonHash;

    private InMemoryUserRepository $users;
    private InMemoryAuditLogRepository $audit;
    private AdminAuthenticator $authenticator;
    private int $adminId;

    public static function setUpBeforeClass(): void
    {
        self::$argonHash = new PasswordHasher()->hash(self::PASSWORD);
    }

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $container = TestContainer::withoutSession($this->users, $this->audit);
        $this->authenticator = $container->get(AdminAuthenticator::class);
        $this->adminId = $this->users->add('admin@example.cz', 'Admin', self::$argonHash, Role::Admin);
    }

    public function test_correct_credentials_return_user_and_audit_success(): void
    {
        $user = $this->authenticator->attempt('admin@example.cz', self::PASSWORD, '172.18.0.1');

        self::assertInstanceOf(User::class, $user);
        self::assertSame($this->adminId, $user->id);
        self::assertSame([$this->adminId], $this->users->touchedLogins);
        $entries = $this->audit->byAction(AuditAction::LoginSucceeded);
        self::assertCount(1, $entries);
        self::assertSame($this->adminId, $entries[0]->userId);
        self::assertSame('172.18.0.1', $entries[0]->ipAddress);
    }

    public function test_email_is_trimmed_and_case_insensitive(): void
    {
        $user = $this->authenticator->attempt("  ADMIN@Example.cz \t", self::PASSWORD, null);

        self::assertSame($this->adminId, $user?->id);
    }

    public function test_wrong_password_returns_null_and_audits_failure_with_user_id(): void
    {
        $result = $this->authenticator->attempt('admin@example.cz', 'spatne', '10.0.0.5');

        self::assertNull($result);
        self::assertSame([], $this->users->touchedLogins);
        self::assertSame([], $this->audit->byAction(AuditAction::LoginSucceeded));
        $entries = $this->audit->byAction(AuditAction::LoginFailed);
        self::assertCount(1, $entries);
        self::assertSame($this->adminId, $entries[0]->userId);
        self::assertStringContainsString('admin@example.cz', $entries[0]->summary);
        self::assertSame('10.0.0.5', $entries[0]->ipAddress);
    }

    public function test_unknown_email_returns_null_and_audits_failure_without_user_id(): void
    {
        $result = $this->authenticator->attempt('nikdo@example.cz', self::PASSWORD, null);

        self::assertNull($result);
        $entries = $this->audit->byAction(AuditAction::LoginFailed);
        self::assertCount(1, $entries);
        self::assertNull($entries[0]->userId);
        self::assertStringContainsString('nikdo@example.cz', $entries[0]->summary);
    }

    public function test_empty_fields_fail(): void
    {
        self::assertNull($this->authenticator->attempt('', '', null));
        self::assertNull($this->authenticator->attempt('admin@example.cz', '', null));
        self::assertCount(2, $this->audit->byAction(AuditAction::LoginFailed));
    }

    public function test_password_over_4096_chars_fails_even_if_it_would_match_prefix(): void
    {
        $result = $this->authenticator->attempt('admin@example.cz', str_repeat('a', 4097), null);

        self::assertNull($result);
        self::assertCount(1, $this->audit->byAction(AuditAction::LoginFailed));
    }

    public function test_bcrypt_hash_is_upgraded_to_argon2id_on_successful_login(): void
    {
        $bcrypt = password_hash(self::PASSWORD, PASSWORD_BCRYPT);
        $id = $this->users->add('stary@example.cz', 'Stary', $bcrypt, Role::Admin);

        $user = $this->authenticator->attempt('stary@example.cz', self::PASSWORD, null);

        self::assertSame($id, $user?->id);
        $stored = $this->users->findById($id)->passwordHash ?? '';
        self::assertStringStartsWith('$argon2id$', $stored);
        self::assertTrue(password_verify(self::PASSWORD, $stored));
    }

    public function test_up_to_date_hash_is_not_rewritten(): void
    {
        $this->authenticator->attempt('admin@example.cz', self::PASSWORD, null);

        self::assertSame(self::$argonHash, $this->users->findById($this->adminId)?->passwordHash);
    }

    public function test_record_logout_writes_audit_entry(): void
    {
        $user = $this->users->findById($this->adminId) ?? throw new \LogicException('seed');

        $this->authenticator->recordLogout($user, '172.18.0.1');

        $entries = $this->audit->byAction(AuditAction::Logout);
        self::assertCount(1, $entries);
        self::assertSame($this->adminId, $entries[0]->userId);
        self::assertSame('172.18.0.1', $entries[0]->ipAddress);
    }
}
