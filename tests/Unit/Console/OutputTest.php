<?php

declare(strict_types=1);

namespace App\Tests\Unit\Console;

use App\Console\Output;
use PHPUnit\Framework\TestCase;

/** Plán 008, §3: `Output::write()` vypisuje bez konce řádku (průběžný výpis proudu). */
final class OutputTest extends TestCase
{
    public function test_write_appends_without_line_break_and_line_ends_line(): void
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stdout');
        $stderr = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stderr');
        $output = new Output($stdout, $stderr);

        $output->write('Ahoj');
        $output->write(' světe');
        $output->line('');
        $output->line('Hotovo');

        rewind($stdout);
        rewind($stderr);
        self::assertSame('Ahoj světe' . PHP_EOL . 'Hotovo' . PHP_EOL, stream_get_contents($stdout));
        self::assertSame('', stream_get_contents($stderr));
    }
}
