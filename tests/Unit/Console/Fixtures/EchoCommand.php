<?php

declare(strict_types=1);

namespace App\Tests\Unit\Console\Fixtures;

use App\Console\Command;
use App\Console\Output;

final class EchoCommand implements Command
{
    public function run(array $arguments, Output $output): int
    {
        $output->line('args: ' . implode(',', $arguments));

        return 0;
    }
}
