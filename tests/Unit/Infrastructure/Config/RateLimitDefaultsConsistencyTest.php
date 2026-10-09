<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Config;

use App\Infrastructure\Config\AiRateLimitConfig;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 013, AC 4: výchozí limity AI jsou na třech místech (AiRateLimitConfig, compose.yaml, .env.example).
 * Test čte oba soubory jako text a hlídá, aby se hodnoty nerozjely (vzor ModelDefaultsConsistencyTest).
 */
final class RateLimitDefaultsConsistencyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function defaults(): iterable
    {
        // Konstanta se čte až v testu, aby chybějící třída byla chybou testu, ne prázdným data providerem.
        yield 'AI_LIMIT_POZADAVKU' => ['AI_LIMIT_POZADAVKU', 'DEFAULT_STANDARD'];
        yield 'AI_LIMIT_NAROCNYCH' => ['AI_LIMIT_NAROCNYCH', 'DEFAULT_HEAVY'];
    }

    private static function expected(string $constant): string
    {
        $value = constant(AiRateLimitConfig::class . '::' . $constant);
        self::assertIsString($value);

        return $value;
    }

    #[DataProvider('defaults')]
    public function test_compose_default_equals_rate_limit_config_default(string $variable, string $constant): void
    {
        $expected = self::expected($constant);
        $source = self::read('compose.yaml');

        $count = preg_match_all('~^\s*' . $variable . ':\s*\$\{' . $variable . ':-([^}]*)\}\s*$~m', $source, $matches);

        self::assertGreaterThan(0, $count, $variable . ' v compose.yaml nemá výchozí hodnotu ${' . $variable . ':-…}.');
        self::assertSame(array_fill(0, (int) $count, $expected), $matches[1], $variable . ' v compose.yaml');
    }

    #[DataProvider('defaults')]
    public function test_env_example_value_equals_rate_limit_config_default(string $variable, string $constant): void
    {
        $expected = self::expected($constant);
        $source = self::read('.env.example');

        // Zakomentované řádky (produkční část `# AI_…=`) se nepočítají.
        $count = preg_match_all('~^' . $variable . '=(.*)$~m', $source, $matches);

        self::assertSame(1, $count, $variable . ' má být v .env.example právě jednou (nezakomentovaný).');
        self::assertSame($expected, trim($matches[1][0]), $variable . ' v .env.example');
    }

    private static function read(string $file): string
    {
        $source = file_get_contents(AiFixtures::root() . '/' . $file);
        self::assertIsString($source, $file);

        return $source;
    }
}
