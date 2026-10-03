<?php

declare(strict_types=1);

namespace App\Tests\Unit\Console;

use App\Console\ConsoleApplication;
use App\Console\Output;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\TestCase;

final class CreateAdminCommandTest extends TestCase
{
    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;
    private InMemoryUserRepository $users;
    private ConsoleApplication $application;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stdout');
        $this->stderr = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stderr');
        $this->users = new InMemoryUserRepository();
        $this->application = TestContainer::withoutSession($this->users, new InMemoryAuditLogRepository())
            ->get(ConsoleApplication::class);
    }

    /** @param list<string> $arguments */
    private function runCommand(array $arguments): int
    {
        return $this->application->run($arguments, new Output($this->stdout, $this->stderr));
    }

    /** @param resource $stream */
    private function read(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function test_creates_admin_with_given_password(): void
    {
        $code = $this->runCommand(['admin:vytvor', '--email=admin@example.cz', '--jmeno=Administrátor', '--heslo=dlouhe-heslo-12']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Vytvořen administrátor admin@example.cz (ID 1).', $this->read($this->stdout));
        self::assertStringNotContainsString('Vygenerované heslo', $this->read($this->stdout));
        self::assertTrue(password_verify('dlouhe-heslo-12', $this->users->findById(1)->passwordHash ?? ''));
    }

    public function test_generates_password_when_missing_and_prints_it_once(): void
    {
        $code = $this->runCommand(['admin:vytvor', '--email=admin@example.cz', '--jmeno=Administrátor']);
        $out = $this->read($this->stdout);

        self::assertSame(0, $code);
        self::assertSame(1, preg_match('/Vygenerované heslo: (\S+) – uložte si ho, znovu se nezobrazí\./u', $out, $m));
        $password = $m[1] ?? '';
        self::assertGreaterThanOrEqual(20, strlen($password));
        self::assertTrue(password_verify($password, $this->users->findById(1)->passwordHash ?? ''));
    }

    public function test_missing_email_or_name_returns_1_with_usage_on_stderr(): void
    {
        self::assertSame(1, $this->runCommand(['admin:vytvor', '--jmeno=Admin']));
        self::assertSame(1, $this->runCommand(['admin:vytvor', '--email=a@example.cz']));
        self::assertStringContainsString('admin:vytvor', $this->read($this->stderr));
        self::assertSame([], $this->users->users);
    }

    public function test_duplicate_returns_1_with_message_on_stderr(): void
    {
        $args = ['admin:vytvor', '--email=admin@example.cz', '--jmeno=Admin', '--heslo=dlouhe-heslo-12'];
        $this->runCommand($args);

        self::assertSame(1, $this->runCommand($args));
        self::assertStringContainsString('už existuje', $this->read($this->stderr));
    }

    public function test_command_is_listed_in_command_overview(): void
    {
        $this->runCommand([]);

        self::assertStringContainsString('admin:vytvor', $this->read($this->stdout));
    }
}
