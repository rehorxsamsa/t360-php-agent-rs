<?php

declare(strict_types=1);

namespace App\Console;

use App\Container\Container;

/**
 * Rozcestník příkazů: první argument je název příkazu, zbytek dostane příkaz.
 */
final readonly class ConsoleApplication
{
    /**
     * @param array<string, class-string<Command>> $commands název příkazu => třída
     */
    public function __construct(
        private Container $container,
        private array $commands,
    ) {}

    /**
     * @param list<string> $arguments argumenty bez názvu skriptu
     * @return int návratový kód procesu
     */
    public function run(array $arguments, Output $output): int
    {
        if ($arguments === []) {
            $output->line('Použití: php bin/konzole <příkaz> [argumenty]');
            $output->line('Příkazy:');
            foreach (array_keys($this->commands) as $name) {
                $output->line('  ' . $name);
            }

            return 0;
        }

        $name = $arguments[0];
        if (!isset($this->commands[$name])) {
            $output->error(sprintf('Neznámý příkaz: %s', $name));
            $output->error('Dostupné příkazy: ' . implode(', ', array_keys($this->commands)));

            return 1;
        }

        try {
            return $this->container->get($this->commands[$name])->run(array_slice($arguments, 1), $output);
        } catch (\Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
