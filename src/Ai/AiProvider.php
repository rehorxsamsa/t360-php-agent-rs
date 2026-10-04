<?php

declare(strict_types=1);

namespace App\Ai;

/** Kdo odpovídá na volání LLM. Hodnoty enumu jsou kontrakt proměnné prostředí `AI_PROVIDER`. */
enum AiProvider: string
{
    case Fake = 'falesny';
    case Anthropic = 'anthropic';

    /** Název, jak se poskytovatel ukládá do `ai_calls.provider` a vrací v `LlmResponse::$provider`. */
    public function logName(): string
    {
        return match ($this) {
            self::Fake => 'fake',
            self::Anthropic => 'anthropic',
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

    /** Dlouhý popis pro přehled („Poskytovatel: …“). */
    public function label(): string
    {
        return match ($this) {
            self::Fake => 'falešný klient (bez API klíče, nic se neúčtuje)',
            self::Anthropic => 'Claude API (Anthropic)',
        };
    }

    /** Krátký popis pro řádek výsledku. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Fake => 'falešný klient',
            self::Anthropic => 'Claude API',
        };
    }
}
