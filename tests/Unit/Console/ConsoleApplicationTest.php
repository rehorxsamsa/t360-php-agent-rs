<?php

declare(strict_types=1);

namespace App\Tests\Unit\Console;

use App\Console\ConsoleApplication;
use App\Console\Output;
use App\Container\Container;
use App\Tests\Unit\Console\Fixtures\EchoCommand;
use App\Tests\Unit\Console\Fixtures\FailingCommand;
use PHPUnit\Framework\TestCase;

final class ConsoleApplicationTest extends TestCase
{
    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stdout');
        $this->stderr = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stderr');
    }

    private function makeOutput(): Output
    {
        return new Output($this->stdout, $this->stderr);
    }

    /** @param resource $stream */
    private function read(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function fixtureApplication(): ConsoleApplication
    {
        return new ConsoleApplication(new Container(), [
            'test:echo' => EchoCommand::class,
            'test:fail' => FailingCommand::class,
        ]);
    }

    private function realApplication(): ConsoleApplication
    {
        /** @var Container $container */
        $container = require __DIR__ . '/../../../config/container.php';

        return $container->get(ConsoleApplication::class);
    }

    public function test_dispatches_command_with_remaining_arguments(): void
    {
        $code = $this->fixtureApplication()->run(['test:echo', 'a', 'b'], $this->makeOutput());

        self::assertSame(0, $code);
        self::assertStringContainsString('args: a,b', $this->read($this->stdout));
    }

    public function test_exception_in_command_prints_message_to_stderr_and_returns_1(): void
    {
        $code = $this->fixtureApplication()->run(['test:fail'], $this->makeOutput());

        self::assertSame(1, $code);
        self::assertStringContainsString('příkaz selhal', $this->read($this->stderr));
    }

    public function test_no_argument_lists_migration_commands_and_returns_0(): void
    {
        $code = $this->realApplication()->run([], $this->makeOutput());
        $out = $this->read($this->stdout);

        self::assertSame(0, $code);
        self::assertStringContainsString('migrace:spust', $out);
        self::assertStringContainsString('migrace:vrat', $out);
        self::assertStringContainsString('migrace:stav', $out);
    }

    public function test_unknown_command_returns_1_with_error_on_stderr(): void
    {
        $code = $this->realApplication()->run(['neznamy:prikaz'], $this->makeOutput());

        self::assertSame(1, $code);
        self::assertStringContainsString('neznamy:prikaz', $this->read($this->stderr));
    }
}
