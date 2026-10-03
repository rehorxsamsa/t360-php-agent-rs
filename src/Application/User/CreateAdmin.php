<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Application\Auth\PasswordHasher;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\User\EmailAddress;
use App\Domain\User\Role;
use App\Domain\User\UserRepository;

/** Use-case: vytvoření administrátorského účtu (volá ho příkaz admin:vytvor). */
final readonly class CreateAdmin
{
    private const int EMAIL_MAX_LENGTH = 190;
    private const int NAME_MAX_LENGTH = 100;
    private const int PASSWORD_MIN_LENGTH = 12;
    private const int PASSWORD_MAX_LENGTH = 4096;

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private AuditLogRepository $audit,
    ) {}

    /**
     * @return int ID nového uživatele
     * @throws CreateAdminFailed při neplatném vstupu nebo duplicitním e-mailu
     */
    public function handle(string $email, string $displayName, #[\SensitiveParameter] string $plainPassword): int
    {
        $email = EmailAddress::normalize($email);
        $displayName = trim($displayName);

        if ($email === '' || mb_strlen($email) > self::EMAIL_MAX_LENGTH
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new CreateAdminFailed('Neplatný e-mail.');
        }
        if ($displayName === '' || mb_strlen($displayName) > self::NAME_MAX_LENGTH) {
            throw new CreateAdminFailed(sprintf('Jméno musí mít 1 až %d znaků.', self::NAME_MAX_LENGTH));
        }
        $passwordLength = mb_strlen($plainPassword);
        if ($passwordLength < self::PASSWORD_MIN_LENGTH || $passwordLength > self::PASSWORD_MAX_LENGTH) {
            throw new CreateAdminFailed(sprintf(
                'Heslo musí mít %d až %d znaků.',
                self::PASSWORD_MIN_LENGTH,
                self::PASSWORD_MAX_LENGTH,
            ));
        }
        if ($this->users->findByEmail($email) !== null) {
            throw new CreateAdminFailed(sprintf('Uživatel s e-mailem %s už existuje.', $email));
        }

        $id = $this->users->add($email, $displayName, $this->hasher->hash($plainPassword), Role::Admin);
        $this->audit->add(new AuditEntry(AuditAction::UserCreated, null, 'user', $id, $email));

        return $id;
    }
}
