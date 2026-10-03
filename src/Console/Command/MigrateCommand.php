<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Output;
use App\Infrastructure\Migration\Migrator;

/** migrace:spust – provede všechny čekající migrace. */
final readonly class MigrateCommand implements Command
{
    public function __construct(private Migrator $migrator) {}

    public function run(array $arguments, Output $output): int
    {
        $executed = $this->migrator->migrate();

        if ($executed === []) {
            $output->line('Žádné čekající migrace.');

            return 0;
        }

        foreach ($executed as $name) {
            $output->line('Spuštěno: ' . $name);
        }

        return 0;
    }
}
