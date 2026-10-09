<?php

declare(strict_types=1);

namespace App\Ai\Editor;

/** Druh nálezu sebekontroly. */
enum ReviewIssueType: string
{
    case Structure = 'structure';
    case Facts = 'facts';
    case Tone = 'tone';
    case Language = 'language';
    case Length = 'length';
    case PromptInjection = 'prompt_injection';

    public function label(): string
    {
        return match ($this) {
            self::Structure => 'struktura',
            self::Facts => 'fakta k ověření',
            self::Tone => 'tón',
            self::Language => 'jazyk',
            self::Length => 'délka',
            self::PromptInjection => 'prompt injection',
        };
    }

    /** @return list<string> hodnoty pro schéma a validaci */
    public static function values(): array
    {
        return array_map(static fn(self $type): string => $type->value, self::cases());
    }
}
