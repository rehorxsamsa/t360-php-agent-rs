<?php

declare(strict_types=1);

namespace App\Ai\Editor;

/** Závažnost nálezu sebekontroly. */
enum ReviewSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'nízká',
            self::Medium => 'střední',
            self::High => 'vysoká',
        };
    }

    /** @return list<string> hodnoty pro schéma a validaci */
    public static function values(): array
    {
        return array_map(static fn(self $severity): string => $severity->value, self::cases());
    }
}
