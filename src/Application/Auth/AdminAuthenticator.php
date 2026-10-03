<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\User\EmailAddress;
use App\Domain\User\User;
use App\Domain\User\UserRepository;

/**
 * Ověření přihlašovacích údajů. Session nezná – ta je věcí HTTP vrstvy.
 */
final readonly class AdminAuthenticator
{
    private const int EMAIL_MAX_LENGTH = 190;
    private const int PASSWORD_MAX_LENGTH = 4096;

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private AuditLogRepository $audit,
    ) {}

    /**
     * @return User|null přihlášený uživatel, nebo null při neplatných údajích (bez rozlišení důvodu)
     */
    public function attempt(string $email, #[\SensitiveParameter] string $password, ?string $ipAddress): ?User
    {
        $email = EmailAddress::normalize($email);

        if ($email === '' || $password === ''
            || mb_strlen($email) > self::EMAIL_MAX_LENGTH
            || mb_strlen($password) > self::PASSWORD_MAX_LENGTH
        ) {
            return $this->fail($email, null, $ipAddress);
        }

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            // Vyrovnání času: neexistující účet nesmí odpovídat znatelně rychleji.
            $this->hasher->verifyDummy($password);

            return $this->fail($email, null, $ipAddress);
        }

        if (!$this->hasher->verify($password, $user->passwordHash)) {
            return $this->fail($email, $user->id, $ipAddress);
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $newHash = $this->hasher->hash($password);
            $this->users->updatePasswordHash($user->id, $newHash);
            $user = new User($user->id, $user->email, $user->displayName, $newHash, $user->role);
        }

        $this->users->touchLastLogin($user->id);
        $this->audit->add(new AuditEntry(AuditAction::LoginSucceeded, $user->id, ipAddress: $ipAddress));

        return $user;
    }

    public function recordLogout(User $user, ?string $ipAddress): void
    {
        $this->audit->add(new AuditEntry(AuditAction::Logout, $user->id, ipAddress: $ipAddress));
    }

    private function fail(string $email, ?int $userId, ?string $ipAddress): null
    {
        // E-mail pochází od útočníka: do auditu se ukládá jen (zkrácený) jako data, nikdy se nevykonává.
        $this->audit->add(new AuditEntry(AuditAction::LoginFailed, $userId, summary: $email, ipAddress: $ipAddress));

        return null;
    }
}
