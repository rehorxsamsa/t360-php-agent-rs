<?php

declare(strict_types=1);

namespace App\Ai\Editor;

/** Verdikt sebekontroly konceptu: v pořádku, nebo doporučeno přepracovat. */
enum ReviewVerdict: string
{
    case Ok = 'ok';
    case Revise = 'revise';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'V pořádku',
            self::Revise => 'Doporučeno přepracovat',
        };
    }
}
