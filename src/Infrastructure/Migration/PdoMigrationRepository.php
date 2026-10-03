<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

/**
 * Evidence provedených migrací v tabulce `migrations` (jediné SQL migrátoru).
 */
final readonly class PdoMigrationRepository
{
    public function __construct(private \PDO $pdo) {}

    public function ensureTable(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS migrations (
                name VARCHAR(190) NOT NULL,
                executed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL);
    }

    /**
     * @return array<string, string> název migrace => čas provedení
     */
    public function executed(): array
    {
        $statement = $this->pdo->query('SELECT name, executed_at FROM migrations ORDER BY name');
        if ($statement === false) {
            return [];
        }

        $result = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['name'] ?? null) && is_string($row['executed_at'] ?? null)) {
                $result[$row['name']] = $row['executed_at'];
            }
        }

        return $result;
    }

    public function markExecuted(string $name): void
    {
        $statement = $this->pdo->prepare('INSERT INTO migrations (name) VALUES (?)');
        $statement->execute([$name]);
    }

    public function forget(string $name): void
    {
        $statement = $this->pdo->prepare('DELETE FROM migrations WHERE name = ?');
        $statement->execute([$name]);
    }

    /**
     * @return list<string> názvy od nejnovější provedené migrace
     */
    public function lastExecuted(int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT name FROM migrations ORDER BY executed_at DESC, name DESC LIMIT ?');
        $statement->bindValue(1, $limit, \PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_filter($statement->fetchAll(\PDO::FETCH_COLUMN), is_string(...)));
    }
}
