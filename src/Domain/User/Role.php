<?php

declare(strict_types=1);

namespace App\Domain\User;

/** Role uživatele; hodnoty odpovídají ENUM sloupci `users.role`. */
enum Role: string
{
    case Admin = 'admin';
}
