<?php

declare(strict_types=1);

namespace App\Domain\User;

/** Jednotná normalizace e-mailu (přihlášení i vytvoření účtu musí e-mail porovnávat stejně). */
final class EmailAddress
{
    private function __construct() {}

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
