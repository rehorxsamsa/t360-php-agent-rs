<?php

declare(strict_types=1);

namespace App\Tests\Unit\Console;

use App\Console\Command;
use App\Console\Command\SeedCommand;
use App\Console\ConsoleApplication;
use App\Console\Output;
use App\Infrastructure\Config\DatabaseConfig;
use App\Infrastructure\Persistence\ConnectionFactory;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\TestCase;

final class SeedCommandTest extends TestCase
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

    /** Továrna na spojení, které by při jakémkoli pokusu o připojení selhalo (nedosažitelný host). */
    private function unreachableConnections(): ConnectionFactory
    {
        return new ConnectionFactory(new DatabaseConfig('unreachable.invalid', 3306, 'redakce_test', 'nobody', 'nothing'));
    }

    private function command(string $appEnv): SeedCommand
    {
        return new SeedCommand($this->unreachableConnections(), __DIR__ . '/../../../database/seeds/demo_content.php', $appEnv);
    }

    /** @param resource $stream */
    private function read(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function test_command_implements_console_contract(): void
    {
        self::assertInstanceOf(Command::class, $this->command('dev'));
    }

    public function test_production_environment_is_refused_before_connecting(): void
    {
        $code = $this->command('prod')->run([], new Output($this->stdout, $this->stderr));

        self::assertSame(1, $code);
        $error = $this->read($this->stderr);
        self::assertStringContainsString(
            'Ukázková data lze nahrát jen ve vývojovém nebo testovacím prostředí (APP_ENV=dev|test).',
            $error,
        );
        self::assertStringNotContainsString('SQLSTATE', $error, 'nesmí dojít k pokusu o připojení');
        self::assertStringNotContainsString('getaddrinfo', $error);
        self::assertSame('', $this->read($this->stdout));
    }

    public function test_empty_environment_is_refused(): void
    {
        self::assertSame(1, $this->command('')->run([], new Output($this->stdout, $this->stderr)));
    }

    public function test_unknown_argument_prints_usage_and_fails_before_connecting(): void
    {
        $code = $this->command('dev')->run(['--unknown'], new Output($this->stdout, $this->stderr));

        self::assertSame(1, $code);
        $error = $this->read($this->stderr);
        self::assertStringContainsString('Použití', $error);
        self::assertStringNotContainsString('SQLSTATE', $error);
    }

    public function test_console_application_lists_db_seed_command(): void
    {
        $application = TestContainer::withoutSession(new InMemoryUserRepository(), new InMemoryAuditLogRepository())
            ->get(ConsoleApplication::class);

        $code = $application->run([], new Output($this->stdout, $this->stderr));

        self::assertSame(0, $code);
        self::assertStringContainsString('db:seed', $this->read($this->stdout));
    }
}
