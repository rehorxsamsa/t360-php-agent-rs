<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Config;

use App\Infrastructure\Config\DatabaseConfig;
use App\Infrastructure\Config\MissingConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    private const SECRET = 'tajne-heslo-123';

    /** @return array<string, string> */
    private function validEnvironment(): array
    {
        return [
            'DB_HOST' => 'db',
            'DB_PORT' => '3306',
            'DB_NAME' => 'redakce_test',
            'DB_USER' => 'redakce_app',
            'DB_PASSWORD' => self::SECRET,
        ];
    }

    public function test_reads_all_values_from_environment(): void
    {
        $config = DatabaseConfig::fromEnvironment($this->validEnvironment());

        self::assertSame('db', $config->host);
        self::assertSame(3306, $config->port);
        self::assertSame('redakce_test', $config->name);
        self::assertSame('redakce_app', $config->user);
        self::assertSame(self::SECRET, $config->password);
    }

    public function test_ignores_unrelated_environment_variables(): void
    {
        $config = DatabaseConfig::fromEnvironment($this->validEnvironment() + ['PATH' => '/usr/bin']);

        self::assertSame('db', $config->host);
    }

    #[DataProvider('requiredVariableProvider')]
    public function test_missing_variable_throws_exception_naming_the_variable(string $variable): void
    {
        $environment = $this->validEnvironment();
        unset($environment[$variable]);

        try {
            DatabaseConfig::fromEnvironment($environment);
            self::fail('Očekávána výjimka MissingConfiguration.');
        } catch (MissingConfiguration $exception) {
            self::assertStringContainsString($variable, $exception->getMessage());
        }
    }

    #[DataProvider('requiredVariableProvider')]
    public function test_empty_variable_is_treated_as_missing(string $variable): void
    {
        $environment = $this->validEnvironment();
        $environment[$variable] = '';

        $this->expectException(MissingConfiguration::class);
        $this->expectExceptionMessage($variable);

        DatabaseConfig::fromEnvironment($environment);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredVariableProvider(): iterable
    {
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $variable) {
            yield $variable => [$variable];
        }
    }

    public function test_message_never_contains_password_value(): void
    {
        $environment = $this->validEnvironment();
        unset($environment['DB_HOST']);

        try {
            DatabaseConfig::fromEnvironment($environment);
            self::fail('Očekávána výjimka MissingConfiguration.');
        } catch (MissingConfiguration $exception) {
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getTraceAsString());
        }
    }

    public function test_missing_configuration_is_runtime_exception(): void
    {
        self::assertInstanceOf(\RuntimeException::class, MissingConfiguration::forVariable('X'));
    }

    public function test_empty_environment_throws_missing_configuration(): void
    {
        $this->expectException(MissingConfiguration::class);

        DatabaseConfig::fromEnvironment([]);
    }

    public function test_non_numeric_port_is_rejected(): void
    {
        $environment = $this->validEnvironment();
        $environment['DB_PORT'] = 'abc';

        $this->expectException(MissingConfiguration::class);
        $this->expectExceptionMessage('DB_PORT');

        DatabaseConfig::fromEnvironment($environment);
    }
}
