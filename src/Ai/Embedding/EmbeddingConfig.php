<?php

declare(strict_types=1);

namespace App\Ai\Embedding;

use App\Infrastructure\Config\MissingConfiguration;

/**
 * Konfigurace embeddingů z proměnných prostředí `EMBED_PROVIDER`, `EMBED_MODEL` a `OLLAMA_URL`.
 * Adresa Ollamy pochází jen z prostředí (nikdy od uživatele), přesto se kontroluje její tvar.
 */
final readonly class EmbeddingConfig
{
    public const string DEFAULT_MODEL = 'embeddinggemma';
    public const string DEFAULT_OLLAMA_URL = 'http://ollama:11434';

    public function __construct(
        public EmbeddingProvider $provider,
        public string $model,
        public string $ollamaUrl,
    ) {}

    /**
     * @param array<string, string> $environment typicky výsledek getenv()
     * @throws MissingConfiguration neplatná hodnota (hláška jmenuje jen proměnnou, nikdy hodnotu)
     */
    public static function fromEnvironment(array $environment): self
    {
        return new self(
            self::provider(trim($environment['EMBED_PROVIDER'] ?? '')),
            self::value($environment, 'EMBED_MODEL', self::DEFAULT_MODEL, '/^[a-z0-9][a-z0-9._:\/-]{0,99}$/'),
            self::value($environment, 'OLLAMA_URL', self::DEFAULT_OLLAMA_URL, '~^https?://[a-z0-9.-]+(:[0-9]{1,5})?$~'),
        );
    }

    private static function provider(string $value): EmbeddingProvider
    {
        if ($value === '') {
            return EmbeddingProvider::Fake;
        }

        return EmbeddingProvider::tryFrom($value) ?? throw MissingConfiguration::invalidVariable('EMBED_PROVIDER');
    }

    /** @param array<string, string> $environment */
    private static function value(array $environment, string $variable, string $default, string $pattern): string
    {
        $value = trim($environment[$variable] ?? '');
        if ($value === '') {
            return $default;
        }

        if (preg_match($pattern, $value) !== 1) {
            throw MissingConfiguration::invalidVariable($variable);
        }

        return $value;
    }
}
