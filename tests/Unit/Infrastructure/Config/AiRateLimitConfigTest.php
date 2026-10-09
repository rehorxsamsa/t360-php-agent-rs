<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Config;

use App\Domain\Ai\RateLimit;
use App\Infrastructure\Config\AiRateLimitConfig;
use App\Infrastructure\Config\MissingConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 013, AC 1–3: limity AI tras z prostředí (`počet/sekundy`), výchozí 10/60 a 3/600. */
final class AiRateLimitConfigTest extends TestCase
{
    private const string STANDARD = 'AI_LIMIT_POZADAVKU';
    private const string HEAVY = 'AI_LIMIT_NAROCNYCH';

    private static function assertLimit(int $limit, int $windowSeconds, RateLimit $actual, string $context = ''): void
    {
        self::assertSame([$limit, $windowSeconds], [$actual->limit, $actual->windowSeconds], $context);
    }

    // ---------------------------------------------------------------- AC 1: výchozí hodnoty

    public function test_default_constants_are_ten_per_minute_and_three_per_ten_minutes(): void
    {
        self::assertSame('10/60', AiRateLimitConfig::DEFAULT_STANDARD);
        self::assertSame('3/600', AiRateLimitConfig::DEFAULT_HEAVY);
    }

    public function test_empty_environment_gives_default_limits(): void
    {
        $config = AiRateLimitConfig::fromEnvironment([]);

        self::assertLimit(10, 60, $config->standard, 'běžný');
        self::assertLimit(3, 600, $config->heavy, 'náročný');
    }

    public function test_empty_or_blank_values_give_default_limits_like_ai_config(): void
    {
        $config = AiRateLimitConfig::fromEnvironment([self::STANDARD => '', self::HEAVY => '   ']);

        self::assertLimit(10, 60, $config->standard);
        self::assertLimit(3, 600, $config->heavy);
    }

    public function test_unrelated_variables_are_ignored(): void
    {
        $config = AiRateLimitConfig::fromEnvironment(['PATH' => '/usr/bin', 'AI_DENNI_LIMIT_TOKENU' => '5']);

        self::assertLimit(10, 60, $config->standard);
        self::assertLimit(3, 600, $config->heavy);
    }

    // ---------------------------------------------------------------- AC 2: vlastní hodnoty

    public function test_reads_both_limits_from_environment(): void
    {
        $config = AiRateLimitConfig::fromEnvironment([self::STANDARD => '5/30', self::HEAVY => '1/120']);

        self::assertLimit(5, 30, $config->standard);
        self::assertLimit(1, 120, $config->heavy);
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $config = AiRateLimitConfig::fromEnvironment([self::STANDARD => "  5/30\n", self::HEAVY => "\t1/120 "]);

        self::assertLimit(5, 30, $config->standard);
        self::assertLimit(1, 120, $config->heavy);
    }

    public function test_each_variable_falls_back_to_its_own_default(): void
    {
        self::assertLimit(3, 600, AiRateLimitConfig::fromEnvironment([self::STANDARD => '5/30'])->heavy);
        self::assertLimit(10, 60, AiRateLimitConfig::fromEnvironment([self::HEAVY => '1/120'])->standard);
    }

    public function test_range_boundaries_are_accepted(): void
    {
        $config = AiRateLimitConfig::fromEnvironment([self::STANDARD => '1/1', self::HEAVY => '9999/86400']);

        self::assertLimit(1, 1, $config->standard);
        self::assertLimit(9999, 86400, $config->heavy);
    }

    // ---------------------------------------------------------------- AC 3: neplatné hodnoty

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'not a number' => ['abc'];
        yield 'count only' => ['10'];
        yield 'zero count' => ['0/60'];
        yield 'zero window' => ['10/0'];
        yield 'negative count' => ['-1/60'];
        yield 'three parts' => ['10/60/5'];
        yield 'window over a day' => ['10/86401'];
        yield 'count over 9999' => ['100000/60'];
        yield 'decimal count' => ['2.5/60'];
        yield 'missing count' => ['/60'];
        yield 'missing window' => ['10/'];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_standard_limit_names_variable_without_value(string $value): void
    {
        $this->assertRejected([self::STANDARD => $value], self::STANDARD, $value);
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_heavy_limit_names_variable_without_value(string $value): void
    {
        $this->assertRejected([self::STANDARD => '10/60', self::HEAVY => $value], self::HEAVY, $value);
    }

    /** @param array<string, string> $environment */
    private function assertRejected(array $environment, string $variable, string $value): void
    {
        try {
            AiRateLimitConfig::fromEnvironment($environment);
        } catch (MissingConfiguration $exception) {
            self::assertSame(MissingConfiguration::invalidVariable($variable)->getMessage(), $exception->getMessage());
            self::assertStringContainsString($variable, $exception->getMessage());
            self::assertStringNotContainsString($value, $exception->getMessage(), 'Hláška nesmí obsahovat hodnotu.');

            return;
        }

        self::fail(sprintf('Hodnota „%s“ proměnné %s měla být odmítnuta.', $value, $variable));
    }
}
