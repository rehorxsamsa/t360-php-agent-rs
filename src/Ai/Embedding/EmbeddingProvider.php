<?php

declare(strict_types=1);

namespace App\Ai\Embedding;

/** Kdo počítá embeddingy. Hodnoty enumu jsou kontrakt proměnné prostředí `EMBED_PROVIDER`. */
enum EmbeddingProvider: string
{
    case Fake = 'falesny';
    case Ollama = 'ollama';

    /** Název, jak ho vrací {@see EmbeddingClient::provider()}. */
    public function logName(): string
    {
        return match ($this) {
            self::Fake => 'fake',
            self::Ollama => 'ollama',
        };
    }

    public static function fromLogName(string $logName): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->logName() === $logName) {
                return $case;
            }
        }

        return null;
    }

    /** Krátký popis pro řádek výsledku a stránku. */
    public function label(): string
    {
        return match ($this) {
            self::Fake => 'falešný klient',
            self::Ollama => 'Ollama (lokálně)',
        };
    }
}
