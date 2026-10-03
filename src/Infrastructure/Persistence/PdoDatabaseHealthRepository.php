<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Health\DatabaseHealth;

final readonly class PdoDatabaseHealthRepository implements DatabaseHealth
{
    public function __construct(private ConnectionFactory $connections) {}

    public function isReachable(): bool
    {
        try {
            $statement = $this->connections->create()->query('SELECT 1');

            return $statement !== false && (int) $statement->fetchColumn() === 1;
        } catch (\PDOException $exception) {
            // Do logu jen kód chyby – zpráva by mohla obsahovat hostitele či uživatele.
            error_log(sprintf('Kontrola databáze selhala (kód %s).', (string) $exception->getCode()));

            return false;
        }
    }
}
