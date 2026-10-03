<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * Připojení k databázi načtené z proměnných prostředí (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD).
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        #[\SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @param array<string, string> $environment typicky výsledek getenv()
     */
    public static function fromEnvironment(array $environment): self
    {
        return new self(
            self::required($environment, 'DB_HOST'),
            self::port($environment),
            self::required($environment, 'DB_NAME'),
            self::required($environment, 'DB_USER'),
            self::required($environment, 'DB_PASSWORD'),
        );
    }

    /**
     * Připojení pro migrace: stejný server a databáze, ale účet s právem DDL
     * (DB_MIGRACE_USER, výchozí redakce_migrace; DB_MIGRACE_PASSWORD je povinné).
     *
     * @param array<string, string> $environment
     */
    public static function forMigrations(array $environment): self
    {
        $user = $environment['DB_MIGRACE_USER'] ?? '';

        return new self(
            self::required($environment, 'DB_HOST'),
            self::port($environment),
            self::required($environment, 'DB_NAME'),
            $user === '' ? 'redakce_migrace' : $user,
            self::required($environment, 'DB_MIGRACE_PASSWORD'),
        );
    }

    /**
     * @param array<string, string> $environment
     */
    private static function port(array $environment): int
    {
        $port = self::required($environment, 'DB_PORT');
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw MissingConfiguration::invalidVariable('DB_PORT');
        }

        return (int) $port;
    }

    /**
     * @param array<string, string> $environment
     */
    private static function required(array $environment, string $variable): string
    {
        $value = $environment[$variable] ?? '';
        if ($value === '') {
            throw MissingConfiguration::forVariable($variable);
        }

        return $value;
    }
}
