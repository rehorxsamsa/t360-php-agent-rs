<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\User\Role;
use App\Domain\User\User;
use App\Domain\User\UserRepository;

final readonly class PdoUserRepository implements UserRepository
{
    private const string COLUMNS = 'id, email, display_name, password_hash, role';

    public function __construct(private \PDO $pdo) {}

    public function findByEmail(string $email): ?User
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);

        return $this->hydrate($statement->fetch());
    }

    public function findById(int $id): ?User
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function add(string $email, string $displayName, string $passwordHash, Role $role): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, display_name, password_hash, role) VALUES (:email, :display_name, :password_hash, :role)',
        );
        $statement->execute([
            'email' => $email,
            'display_name' => $displayName,
            'password_hash' => $passwordHash,
            'role' => $role->value,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $statement->execute(['hash' => $hash, 'id' => $id]);
    }

    public function touchLastLogin(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP(6) WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    private function hydrate(mixed $row): ?User
    {
        if ($row === false) {
            return null;
        }

        if (!is_array($row)
            || !is_numeric($row['id'] ?? null)
            || !is_string($row['email'] ?? null)
            || !is_string($row['display_name'] ?? null)
            || !is_string($row['password_hash'] ?? null)
            || !is_string($row['role'] ?? null)
        ) {
            throw new \UnexpectedValueException('Řádek tabulky users má neočekávaný tvar.');
        }

        return new User(
            (int) $row['id'],
            $row['email'],
            $row['display_name'],
            $row['password_hash'],
            Role::from($row['role']),
        );
    }
}
