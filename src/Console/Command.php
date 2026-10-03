<?php

declare(strict_types=1);

namespace App\Console;

interface Command
{
    /**
     * @param list<string> $arguments argumenty za názvem příkazu
     * @return int návratový kód procesu (0 = úspěch)
     */
    public function run(array $arguments, Output $output): int;
}
