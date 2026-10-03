<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Config\DatabaseConfig;
use App\Infrastructure\Persistence\ConnectionFactory;

/**
 * Pomocník integračních testů měnících schéma. Připojí se jako migrační uživatel
 * a smí pracovat výhradně s databází `redakce_test` (jinak odmítne – ochrana dev dat).
 */
final class TestDatabase
{
    private const string ALLOWED_DATABASE = 'redakce_test';

    /** Vrátí PDO (migrační uživatel) nad prázdnou databází redakce_test. */
    public static function reset(): \PDO
    {
        /** @var array<string, string> $environment */
        $environment = getenv();
        $config = DatabaseConfig::forMigrations($environment);

        if ($config->name !== self::ALLOWED_DATABASE) {
            throw new \RuntimeException(sprintf(
                'Integrační test smí mazat jen databázi %s, konfigurace míří na jinou.',
                self::ALLOWED_DATABASE,
            ));
        }

        $pdo = new ConnectionFactory($config)->create();

        $current = self::statement($pdo, 'SELECT DATABASE()')->fetchColumn();
        if ($current !== self::ALLOWED_DATABASE) {
            throw new \RuntimeException('Připojeno k jiné databázi než redakce_test, mazání odmítnuto.');
        }

        // Odolné mazání: IF EXISTS snese tabulku, která mezitím zmizela (souběžný běh).
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $tables = self::column(
                $pdo,
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
            );
            foreach ($tables as $table) {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $pdo;
    }

    /** Dotaz bez parametrů; při chybě výjimka (kvůli typům pro PHPStan). */
    public static function statement(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Dotaz selhal: ' . $sql);
        }

        return $statement;
    }

    /** První sloupec prvního řádku jako celé číslo (např. COUNT(*)). */
    public static function count(\PDO $pdo, string $sql): int
    {
        $value = self::statement($pdo, $sql)->fetchColumn();
        if (!is_numeric($value)) {
            throw new \RuntimeException('Dotaz nevrátil číslo: ' . $sql);
        }

        return (int) $value;
    }

    /**
     * Hodnoty prvního sloupce všech řádků.
     *
     * @return list<string>
     */
    public static function column(\PDO $pdo, string $sql): array
    {
        $values = [];
        foreach (self::statement($pdo, $sql)->fetchAll(\PDO::FETCH_COLUMN) as $value) {
            if (!is_scalar($value)) {
                throw new \RuntimeException('Dotaz vrátil nescalární hodnotu: ' . $sql);
            }
            $values[] = (string) $value;
        }

        return $values;
    }

    /**
     * Všechny řádky jako asociativní pole textů.
     *
     * @return list<array<string, string>>
     */
    public static function rows(\PDO $pdo, string $sql): array
    {
        $result = [];
        foreach (self::statement($pdo, $sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $texts = [];
            foreach ($row as $name => $value) {
                $texts[(string) $name] = is_scalar($value) ? (string) $value : '';
            }
            $result[] = $texts;
        }

        return $result;
    }
}
