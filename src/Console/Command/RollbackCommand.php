<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Output;
use App\Infrastructure\Migration\Migrator;

/** migrace:vrat [--kroky=N] – vrátí posledních N migrací (výchozí 1). */
final readonly class RollbackCommand implements Command
{
    public function __construct(private Migrator $migrator) {}

    public function run(array $arguments, Output $output): int
    {
        $reverted = $this->migrator->rollback($this->steps($arguments));

        if ($reverted === []) {
            $output->line('Není co vracet.');

            return 0;
        }

        foreach ($reverted as $name) {
            $output->line('Vráceno: ' . $name);
        }

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function steps(array $arguments): int
    {
        if ($arguments === []) {
            return 1;
        }

        if (count($arguments) === 1 && preg_match('/^--kroky=([1-9][0-9]{0,5})$/', $arguments[0], $matches) === 1) {
            return (int) $matches[1];
        }

        throw new \InvalidArgumentException('Neplatný argument. Použití: migrace:vrat [--kroky=N], N >= 1.');
    }
}
