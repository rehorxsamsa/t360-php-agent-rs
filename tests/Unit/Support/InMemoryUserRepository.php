<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\User\Role;
use App\Domain\User\User;
use App\Domain\User\UserRepository;

final class InMemoryUserRepository implements UserRepository
{
    /** @var array<int, User> */
    public array $users = [];
    /** @var list<int> */
    public array $touchedLogins = [];
    private int $nextId = 1;

    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if (mb_strtolower($user->email) === mb_strtolower($email)) {
                return $user;
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function add(string $email, string $displayName, string $passwordHash, Role $role): int
    {
        $id = $this->nextId++;
        $this->users[$id] = new User($id, $email, $displayName, $passwordHash, $role);

        return $id;
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $user = $this->users[$id] ?? throw new \LogicException('Neexistující uživatel.');
        $this->users[$id] = new User($user->id, $user->email, $user->displayName, $hash, $user->role);
    }

    public function touchLastLogin(int $id): void
    {
        $this->touchedLogins[] = $id;
    }

    public function remove(int $id): void
    {
        unset($this->users[$id]);
    }
}
