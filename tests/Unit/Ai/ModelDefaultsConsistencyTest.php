<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\AiConfig;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 012, AC 7: výchozí modely jsou na třech místech (AiConfig, compose.yaml, .env.example).
 * Test čte oba soubory jako text a hlídá, aby se hodnoty nerozjely a aby všechny byly v katalogu.
 */
final class ModelDefaultsConsistencyTest extends TestCase
{
    /** Hledaný prefix je složený, aby ho grep z AC 16 nenašel v tomto souboru. */
    private const string LEGACY_NEEDLE = 'claude-haiku-' . '4-5';

    /** @return iterable<string, array{string, string}> */
    public static function defaults(): iterable
    {
        yield 'AI_MODEL' => ['AI_MODEL', AiConfig::DEFAULT_MODEL];
        yield 'AI_MODEL_LEVNY' => ['AI_MODEL_LEVNY', AiConfig::DEFAULT_CHEAP_MODEL];
    }

    #[DataProvider('defaults')]
    public function test_compose_default_equals_ai_config_default(string $variable, string $expected): void
    {
        $source = self::read('compose.yaml');

        $count = preg_match_all('~^\s*' . $variable . ':\s*\$\{' . $variable . ':-([^}]*)\}\s*$~m', $source, $matches);

        self::assertGreaterThan(0, $count, $variable . ' v compose.yaml nemá výchozí hodnotu ${' . $variable . ':-…}.');
        self::assertSame(array_fill(0, (int) $count, $expected), $matches[1], $variable . ' v compose.yaml');
    }

    #[DataProvider('defaults')]
    public function test_env_example_value_equals_ai_config_default(string $variable, string $expected): void
    {
        $source = self::read('.env.example');

        // Zakomentované řádky (`# AI_MODEL=` v produkční části) se nepočítají.
        $count = preg_match_all('~^' . $variable . '=(.*)$~m', $source, $matches);

        self::assertSame(1, $count, $variable . ' má být v .env.example právě jednou (nezakomentovaný).');
        self::assertSame($expected, trim($matches[1][0]), $variable . ' v .env.example');
    }

    #[DataProvider('defaults')]
    public function test_ai_config_default_is_in_catalog(string $variable, string $expected): void
    {
        self::assertSame($expected, AiFixtures::catalog()->get($expected)->id, $variable);
    }

    /**
     * Plán 012, AC 15 (část v kódu a konfiguraci): starý levný model se mimo katalog nikde nepoužívá.
     * README.md a docs/DEMO.md upravuje technicky-spisovatel (T4), ty ověří grep v T3.
     */
    public function test_legacy_haiku_id_is_not_used_in_code_or_configuration(): void
    {
        $hits = [];
        foreach (['src', 'templates', 'bin', 'public', 'compose.yaml', '.env.example'] as $path) {
            foreach (self::files($path) as $file) {
                if (str_contains(self::read($file), self::LEGACY_NEEDLE)) {
                    $hits[] = $file;
                }
            }
        }

        self::assertSame([], $hits);
    }

    /** Plán 012, AC 16: v testech je ID legacy modelu jen v definici AiFixtures::LEGACY_HAIKU. */
    public function test_legacy_haiku_id_appears_in_tests_only_in_fixture_constant(): void
    {
        $hits = [];
        foreach (['tests/Unit', 'tests/Integration'] as $path) {
            foreach (self::files($path) as $file) {
                $count = substr_count(self::read($file), self::LEGACY_NEEDLE);
                if ($count > 0) {
                    $hits[$file] = $count;
                }
            }
        }

        self::assertSame(['tests/Unit/Support/AiFixtures.php' => 1], $hits);
        self::assertStringStartsWith(self::LEGACY_NEEDLE, AiFixtures::LEGACY_HAIKU);
    }

    /** @return list<string> cesty relativně ke kořeni repozitáře */
    private static function files(string $path): array
    {
        $root = AiFixtures::root();
        if (is_file($root . '/' . $path)) {
            return [$path];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    private static function read(string $file): string
    {
        $source = file_get_contents(AiFixtures::root() . '/' . $file);
        self::assertIsString($source, $file);

        return $source;
    }
}
