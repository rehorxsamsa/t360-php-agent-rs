<?php

declare(strict_types=1);

namespace App\Application\Auth;

/** Hashování hesel (argon2id s výchozími parametry PHP) a ověření včetně vyrovnání času. */
final readonly class PasswordHasher
{
    /**
     * Předem spočítaný argon2id hash náhodného řetězce (výchozí parametry, stejná cena jako skutečný hash).
     * Slouží jen k vyrovnání času odpovědi u neexistujícího účtu; neodpovídá žádnému heslu.
     */
    private const string DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$blF1Sm00ZUZoVE84MTRMSA$T7XsPrr8/exZHqdeqCRBBy3LtDUGy5P6+0311soJFaw';

    public function hash(#[\SensitiveParameter] string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }

    public function verify(#[\SensitiveParameter] string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    /** Spotřebuje stejný čas jako ověření skutečného hesla; výsledek se zahazuje. */
    public function verifyDummy(#[\SensitiveParameter] string $plain): void
    {
        password_verify($plain, self::DUMMY_HASH);
    }
}
