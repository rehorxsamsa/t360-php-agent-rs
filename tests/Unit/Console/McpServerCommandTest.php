<?php

declare(strict_types=1);

namespace App\Tests\Unit\Console;

use App\Console\Command;
use App\Console\Command\McpServerCommand;
use App\Console\ConsoleApplication;
use App\Console\Output;
use App\Container\Container;
use App\Tests\Unit\Support\InMemoryArticleRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 011, AC 15: příkaz `mcp:server` nepřijímá argumenty (usage na stderr, kód 1, stdout prázdný)
 * a je v přehledu příkazů. Běh nad skutečným STDIO ověřuje integrační McpServerProcessTest (AC 16).
 */
final class McpServerCommandTest extends TestCase
{
    private const string USAGE = 'Použití: php bin/konzole mcp:server (MCP server redakce přes STDIO, spouští ho Claude Code – návod na /admin/ai/10)';

    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;
    private InMemoryArticleRepository $articles;
    private Container $container;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stdout');
        $this->stderr = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stderr');
        $this->articles = InMemoryArticleRepository::newsroomContract();
        $this->container = TestContainer::withoutSession(
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
            articles: $this->articles,
        );
    }

    private function consoleOutput(): Output
    {
        return new Output($this->stdout, $this->stderr);
    }

    /** @param resource $stream */
    private function read(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function test_command_is_registered_and_listed(): void
    {
        $code = $this->container->get(ConsoleApplication::class)->run([], $this->consoleOutput());

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('~^\s+mcp:server$~m', $this->read($this->stdout));
    }

    public function test_command_is_composed_by_container(): void
    {
        self::assertInstanceOf(Command::class, $this->container->get(McpServerCommand::class));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function extraArguments(): iterable
    {
        yield 'one' => [['navic']];
        yield 'option' => [['--port=8080']];
        yield 'empty string' => [['']];
        yield 'two' => [['a', 'b']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('extraArguments')]
    public function test_arguments_print_usage_to_stderr_and_return_1(array $arguments): void
    {
        $code = $this->container->get(ConsoleApplication::class)->run(['mcp:server', ...$arguments], $this->consoleOutput());

        self::assertSame(1, $code);
        self::assertSame(self::USAGE . PHP_EOL, $this->read($this->stderr));
        self::assertSame('', $this->read($this->stdout), 'Stdout patří protokolu MCP.');
        self::assertSame(0, $this->articles->totalCalls());
    }
}
