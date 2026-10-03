<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Output;
use App\Infrastructure\Migration\Migrator;

/** migrace:stav – vypíše provedené [x] a čekající [ ] migrace. */
final readonly class MigrationStatusCommand implements Command
{
    public function __construct(private Migrator $migrator) {}

    public function run(array $arguments, Output $output): int
    {
        foreach ($this->migrator->status() as $status) {
            $output->line(
                $status->executedAt === null
                    ? sprintf('[ ] %s', $status->name)
                    : sprintf('[x] %s (%s)', $status->name, $status->executedAt),
            );
        }

        return 0;
    }
}
