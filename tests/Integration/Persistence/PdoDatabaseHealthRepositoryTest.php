<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Health\DatabaseHealth;
use App\Infrastructure\Config\DatabaseConfig;
use App\Infrastructure\Persistence\ConnectionFactory;
use App\Infrastructure\Persistence\PdoDatabaseHealthRepository;
use PHPUnit\Framework\TestCase;

/**
 * Běží proti skutečné MariaDB (služba `db`, databáze redakce_test – vynuceno phpunit.xml.dist).
 */
final class PdoDatabaseHealthRepositoryTest extends TestCase
{
    private string|false $previousErrorLog = false;
    private string $errorLogFile = '';

    protected function setUp(): void
    {
        // error_log() z repozitáře nesmí zahlcovat výstup PHPUnitu.
        $this->errorLogFile = sys_get_temp_dir() . '/t360-health-' . bin2hex(random_bytes(4)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $environment = [];
        foreach (getenv() as $name => $value) {
            $environment[(string) $name] = (string) $value;
        }

        return $environment;
    }

    public function test_reports_reachable_for_running_database(): void
    {
        $config = DatabaseConfig::fromEnvironment($this->environment());
        $repository = new PdoDatabaseHealthRepository(new ConnectionFactory($config));

        self::assertInstanceOf(DatabaseHealth::class, $repository);
        self::assertTrue($repository->isReachable());
    }

    public function test_uses_test_database(): void
    {
        $config = DatabaseConfig::fromEnvironment($this->environment());

        self::assertSame('redakce_test', $config->name);
    }

    public function test_reports_unreachable_for_closed_port_within_three_seconds(): void
    {
        $base = DatabaseConfig::fromEnvironment($this->environment());
        $config = new DatabaseConfig($base->host, 1, $base->name, $base->user, $base->password);
        $repository = new PdoDatabaseHealthRepository(new ConnectionFactory($config));

        $started = microtime(true);
        $result = $repository->isReachable();
        $elapsed = microtime(true) - $started;

        self::assertFalse($result);
        self::assertLessThan(3.0, $elapsed);
    }

    public function test_reports_unreachable_for_wrong_password_without_throwing(): void
    {
        $base = DatabaseConfig::fromEnvironment($this->environment());
        $config = new DatabaseConfig($base->host, $base->port, $base->name, $base->user, 'spatne-heslo-xyz');
        $repository = new PdoDatabaseHealthRepository(new ConnectionFactory($config));

        self::assertFalse($repository->isReachable());
    }

    public function test_error_log_never_contains_password(): void
    {
        $base = DatabaseConfig::fromEnvironment($this->environment());
        $config = new DatabaseConfig($base->host, $base->port, $base->name, $base->user, 'spatne-heslo-xyz');

        new PdoDatabaseHealthRepository(new ConnectionFactory($config))->isReachable();

        $log = is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
        self::assertStringNotContainsString('spatne-heslo-xyz', $log);
        self::assertStringNotContainsString($base->password, $log);
    }
}
