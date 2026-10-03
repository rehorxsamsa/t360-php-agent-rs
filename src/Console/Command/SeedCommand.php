<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Output;
use App\Infrastructure\Persistence\ConnectionFactory;
use App\Infrastructure\Seed\Seed;

/** db:seed – nahraje ukázková data (jen APP_ENV=dev|test); opakované spuštění doplní jen chybějící záznamy. */
final readonly class SeedCommand implements Command
{
    /** @var list<string> */
    private const array ALLOWED_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private ConnectionFactory $connections,
        private string $seedFile,
        private string $appEnv,
    ) {}

    public function run(array $arguments, Output $output): int
    {
        // Obě kontroly proběhnou dřív, než se command pokusí připojit k databázi.
        if (!in_array($this->appEnv, self::ALLOWED_ENVIRONMENTS, true)) {
            $output->error('Ukázková data lze nahrát jen ve vývojovém nebo testovacím prostředí (APP_ENV=dev|test).');

            return 1;
        }

        if ($arguments !== []) {
            $output->error('Použití: php bin/konzole db:seed (příkaz nepřijímá žádné argumenty)');

            return 1;
        }

        $seed = $this->loadSeed();
        $pdo = $this->connections->create();

        $pdo->beginTransaction();
        try {
            $inserted = $seed->run($pdo);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        $this->report($inserted, $output);

        return 0;
    }

    private function loadSeed(): Seed
    {
        if (!is_file($this->seedFile)) {
            throw new \RuntimeException(sprintf('Soubor seedu %s neexistuje.', basename($this->seedFile)));
        }

        $seed = require $this->seedFile;
        if (!$seed instanceof Seed) {
            throw new \RuntimeException(sprintf('Soubor seedu %s musí vracet instanci %s.', basename($this->seedFile), Seed::class));
        }

        return $seed;
    }

    /** @param array<string, int> $inserted */
    private function report(array $inserted, Output $output): void
    {
        $parts = [];
        foreach ($inserted as $label => $count) {
            if ($count > 0) {
                $parts[] = sprintf('%s: %d', $label, $count);
            }
        }

        if ($parts === []) {
            $output->line('Ukázková data už jsou v databázi, nic nového se nevložilo.');

            return;
        }

        $output->line('Nově vloženo – ' . implode(', ', $parts) . '.');
    }
}
