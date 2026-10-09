<?php

declare(strict_types=1);

namespace App\Ai;

use App\Infrastructure\Config\MissingConfiguration;

/**
 * Konfigurace AI z proměnných prostředí. Jediné místo v `src/`, které čte `ANTHROPIC_API_KEY`.
 */
final readonly class AiConfig
{
    public const string DEFAULT_MODEL = 'claude-sonnet-5-5';
    public const string DEFAULT_CHEAP_MODEL = 'claude-haiku-5-5';
    public const int DEFAULT_DAILY_TOKEN_LIMIT = 200000;

    public function __construct(
        public AiProvider $provider,
        #[\SensitiveParameter]
        public string $apiKey,
        public string $model,
        public string $cheapModel,
        public int $dailyTokenLimit,
    ) {}

    /**
     * @param array<string, string> $environment typicky výsledek getenv()
     * @throws MissingConfiguration neplatná hodnota (hláška jmenuje jen proměnnou, nikdy hodnotu)
     */
    public static function fromEnvironment(array $environment): self
    {
        return new self(
            self::provider($environment['AI_PROVIDER'] ?? ''),
            trim($environment['ANTHROPIC_API_KEY'] ?? ''),
            self::model($environment, 'AI_MODEL', self::DEFAULT_MODEL),
            self::model($environment, 'AI_MODEL_LEVNY', self::DEFAULT_CHEAP_MODEL),
            self::limit($environment['AI_DENNI_LIMIT_TOKENU'] ?? ''),
        );
    }

    /** Klíč se nesmí dostat do `var_dump` ani do výpisu ladicích nástrojů. */
    public function __debugInfo(): array
    {
        return [
            'provider' => $this->provider,
            'apiKey' => $this->apiKey === '' ? '' : '***',
            'model' => $this->model,
            'cheapModel' => $this->cheapModel,
            'dailyTokenLimit' => $this->dailyTokenLimit,
        ];
    }

    private static function provider(string $value): AiProvider
    {
        if ($value === '') {
            return AiProvider::Fake;
        }

        return AiProvider::tryFrom($value) ?? throw MissingConfiguration::invalidVariable('AI_PROVIDER');
    }

    /** @param array<string, string> $environment */
    private static function model(array $environment, string $variable, string $default): string
    {
        $value = trim($environment[$variable] ?? '');
        if ($value === '') {
            return $default;
        }

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $value) !== 1) {
            throw MissingConfiguration::invalidVariable($variable);
        }

        return $value;
    }

    private static function limit(string $value): int
    {
        if ($value === '') {
            return self::DEFAULT_DAILY_TOKEN_LIMIT;
        }

        if (preg_match('/^[1-9][0-9]{0,11}$/', $value) !== 1) {
            throw MissingConfiguration::invalidVariable('AI_DENNI_LIMIT_TOKENU');
        }

        return (int) $value;
    }
}
