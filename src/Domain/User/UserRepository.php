<?php

declare(strict_types=1);

namespace App\Domain\User;

interface UserRepository
{
    public function findByEmail(string $email): ?User;

    public function findById(int $id): ?User;

    /** @return int ID nového uživatele */
    public function add(string $email, string $displayName, string $passwordHash, Role $role): int;

    public function updatePasswordHash(int $id, string $hash): void;

    public function touchLastLogin(int $id): void;
}
