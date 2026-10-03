<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\User;

use App\Application\User\CreateAdmin;
use App\Application\User\CreateAdminFailed;
use App\Domain\Audit\AuditAction;
use App\Domain\User\Role;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateAdminTest extends TestCase
{
    private InMemoryUserRepository $users;
    private InMemoryAuditLogRepository $audit;
    private CreateAdmin $useCase;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->useCase = TestContainer::withoutSession($this->users, $this->audit)->get(CreateAdmin::class);
    }

    public function test_creates_admin_with_normalized_email_and_argon2id_hash(): void
    {
        $id = $this->useCase->handle('Admin@Example.cz ', 'Administrátor', 'dlouhe-heslo-12');

        $user = $this->users->findById($id);
        self::assertNotNull($user);
        self::assertSame('admin@example.cz', $user->email);
        self::assertSame('Administrátor', $user->displayName);
        self::assertSame(Role::Admin, $user->role);
        self::assertStringStartsWith('$argon2id$', $user->passwordHash);
        self::assertTrue(password_verify('dlouhe-heslo-12', $user->passwordHash));
    }

    public function test_writes_user_created_audit_entry(): void
    {
        $id = $this->useCase->handle('admin@example.cz', 'Admin', 'dlouhe-heslo-12');

        $entries = $this->audit->byAction(AuditAction::UserCreated);
        self::assertCount(1, $entries);
        self::assertSame('user', $entries[0]->entityType);
        self::assertSame($id, $entries[0]->entityId);
        self::assertSame('admin@example.cz', $entries[0]->summary);
        self::assertNull($entries[0]->userId);
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidInput(): array
    {
        return [
            'invalid email' => ['neni-email', 'Admin', 'dlouhe-heslo-12'],
            'email too long' => [str_repeat('a', 185) . '@x.cz', 'Admin', 'dlouhe-heslo-12'],
            'empty name' => ['a@example.cz', '   ', 'dlouhe-heslo-12'],
            'name too long' => ['a@example.cz', str_repeat('j', 101), 'dlouhe-heslo-12'],
            'short password' => ['a@example.cz', 'Admin', 'kratke-11-z'],
            'password too long' => ['a@example.cz', 'Admin', str_repeat('p', 4097)],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_fails_with_czech_message_and_stores_nothing(string $email, string $name, string $password): void
    {
        try {
            $this->useCase->handle($email, $name, $password);
            self::fail('Očekávána CreateAdminFailed.');
        } catch (CreateAdminFailed $e) {
            self::assertNotSame('', $e->getMessage());
        }

        self::assertSame([], $this->users->users);
        self::assertSame([], $this->audit->entries);
    }

    public function test_password_of_exactly_12_chars_is_accepted(): void
    {
        self::assertGreaterThan(0, $this->useCase->handle('a@example.cz', 'Admin', '123456789012'));
    }

    public function test_duplicate_email_fails_with_message_naming_the_email(): void
    {
        $this->useCase->handle('admin@example.cz', 'Admin', 'dlouhe-heslo-12');

        try {
            $this->useCase->handle('ADMIN@example.cz', 'Jiný', 'dlouhe-heslo-12');
            self::fail('Očekávána CreateAdminFailed.');
        } catch (CreateAdminFailed $e) {
            self::assertSame('Uživatel s e-mailem admin@example.cz už existuje.', $e->getMessage());
        }

        self::assertCount(1, $this->users->users);
        self::assertCount(1, $this->audit->entries);
    }
}
