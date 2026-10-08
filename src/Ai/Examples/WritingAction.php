<?php

declare(strict_types=1);

namespace App\Ai\Examples;

/** Akce asistenta psaní (příklad 06). Hodnoty jsou kontrakt formuláře i konzole (`--akce=`). */
enum WritingAction: string
{
    case Continue = 'pokracuj';
    case Shorten = 'zkrat';
    case Simplify = 'zjednodus';

    /** Název akce v UI. */
    public function label(): string
    {
        return match ($this) {
            self::Continue => 'Pokračovat v textu',
            self::Shorten => 'Zkrátit',
            self::Simplify => 'Zjednodušit',
        };
    }

    /** Pokyn modelu; vkládá se za text uvnitř uživatelské zprávy (`Úkol: …`). */
    public function instruction(): string
    {
        return match ($this) {
            self::Continue => 'Pokračuj v tomto textu dvěma až třemi větami ve stejném stylu. Vypiš jen pokračování, původní text neopakuj.',
            self::Shorten => 'Zkrať tento text zhruba na polovinu a zachovej jeho význam. Vypiš jen zkrácený text.',
            self::Simplify => 'Přepiš tento text jednodušeji, srozumitelně pro laika a kratšími větami. Vypiš jen přepsaný text.',
        };
    }
}
