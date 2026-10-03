<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Config\DatabaseConfig;

/**
 * Vytváří PDO spojení (líně – až při volání create()).
 */
final readonly class ConnectionFactory
{
    private const int CONNECT_TIMEOUT_SECONDS = 2;

    public function __construct(private DatabaseConfig $config) {}

    /**
     * @throws \PDOException když se nelze připojit
     */
    public function create(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->host,
            $this->config->port,
            $this->config->name,
        );

        return new \PDO($dsn, $this->config->user, $this->config->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);
    }
}
