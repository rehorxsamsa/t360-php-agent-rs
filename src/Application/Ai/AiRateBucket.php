<?php

declare(strict_types=1);

namespace App\Application\Ai;

/** Kbelík rate limitu AI (plán 013); hodnota se ukládá do `ai_rate_limit_hits.bucket`. */
enum AiRateBucket: string
{
    /** Běžné AI akce: 01–05, 06 proud, 07, 08 dotaz a indexace. */
    case Standard = 'ai';
    /** Náročné AI akce: návrh AI redaktora (09). */
    case Heavy = 'ai_heavy';

    /** Český popisek do shrnutí audit logu („Limit … N za W s“). */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'běžných AI požadavků',
            self::Heavy => 'náročných AI požadavků (AI redaktor)',
        };
    }
}
