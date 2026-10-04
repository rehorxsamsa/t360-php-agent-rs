<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\AiConfig;
use App\Ai\AiProvider;
use App\Infrastructure\Config\MissingConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 006, AC 1: konfigurace AI z prostředí. */
final class AiConfigTest extends TestCase
{
    private const string SECRET = 'sk-ant-api03-tajny-klic-XYZ';

    public function test_empty_environment_uses_fake_provider_and_defaults(): void
    {
        $config = AiConfig::fromEnvironment([]);

        self::assertSame(AiProvider::Fake, $config->provider);
        self::assertSame('claude-sonnet-5-5', $config->model);
        self::assertSame('claude-haiku-4-5-20251001', $config->cheapModel);
        self::assertSame(200000, $config->dailyTokenLimit);
        self::assertSame('', $config->apiKey);
    }

    public function test_falesny_provider_is_fake(): void
    {
        self::assertSame(AiProvider::Fake, AiConfig::fromEnvironment(['AI_PROVIDER' => 'falesny'])->provider);
    }

    public function test_reads_anthropic_provider_key_models_and_limit(): void
    {
        $config = AiConfig::fromEnvironment([
            'AI_PROVIDER' => 'anthropic',
            'ANTHROPIC_API_KEY' => self::SECRET,
            'AI_MODEL' => 'claude-haiku-4-5-20251001',
            'AI_MODEL_LEVNY' => 'claude-sonnet-5-5',
            'AI_DENNI_LIMIT_TOKENU' => '5000',
            'PATH' => '/usr/bin',
        ]);

        self::assertSame(AiProvider::Anthropic, $config->provider);
        self::assertSame(self::SECRET, $config->apiKey);
        self::assertSame('claude-haiku-4-5-20251001', $config->model);
        self::assertSame('claude-sonnet-5-5', $config->cheapModel);
        self::assertSame(5000, $config->dailyTokenLimit);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidEnvironments(): iterable
    {
        yield 'provider ollama' => [['AI_PROVIDER' => 'ollama'], 'AI_PROVIDER'];
        yield 'provider xyz' => [['AI_PROVIDER' => 'xyz'], 'AI_PROVIDER'];
        yield 'limit letters' => [['AI_DENNI_LIMIT_TOKENU' => 'abc'], 'AI_DENNI_LIMIT_TOKENU'];
        yield 'limit zero' => [['AI_DENNI_LIMIT_TOKENU' => '0'], 'AI_DENNI_LIMIT_TOKENU'];
        yield 'limit negative' => [['AI_DENNI_LIMIT_TOKENU' => '-5'], 'AI_DENNI_LIMIT_TOKENU'];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('invalidEnvironments')]
    public function test_invalid_value_throws_missing_configuration_naming_variable_without_key(array $environment, string $variable): void
    {
        try {
            AiConfig::fromEnvironment($environment + ['ANTHROPIC_API_KEY' => self::SECRET]);
            self::fail('Očekávána výjimka MissingConfiguration.');
        } catch (MissingConfiguration $exception) {
            self::assertSame(MissingConfiguration::invalidVariable($variable)->getMessage(), $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getTraceAsString());
        }
    }

    public function test_provider_enum_values_are_environment_contract(): void
    {
        self::assertSame('falesny', AiProvider::Fake->value);
        self::assertSame('anthropic', AiProvider::Anthropic->value);
        self::assertNotSame('', AiProvider::Fake->label());
        self::assertNotSame('', AiProvider::Anthropic->label());
    }
}
