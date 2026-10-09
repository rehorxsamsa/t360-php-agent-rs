<?php

declare(strict_types=1);

/**
 * Katalog modelů Claude, které aplikace smí volat, a jejich ceník.
 *
 * Ověřeno 2026-10-09: https://platform.claude.com/docs/en/about-claude/pricing
 * (modely: https://platform.claude.com/docs/en/about-claude/models/overview,
 * vyřazování: https://platform.claude.com/docs/en/about-claude/model-deprecations)
 *
 * Ceny jsou v USD za milion tokenů (MTok). `cache_write_per_mtok` platí pro cache s životností 5 minut.
 * `supports_effort`: model přijímá `output_config.effort` (legacy Haiku 4.5 ne – klient mu ho neposílá).
 * Model mimo tento seznam se nevolá: bez ceny nejde hlídat denní limit.
 * Ceny a modely zastarávají – při změně uprav hodnoty i datum ověření výše.
 *
 * Cenové pásmo: katalog drží jen nižší pásmo Haiku 5.5, tedy vstup do 100 000 tokenů. U požadavku
 * s celým vstupem nad 100 000 tokenů (včetně cache) je cena ×5 (0,50 / 0,625 / 0,05 / 2,50 USD/MTok),
 * takže by se podhodnotila. Vstupy příkladů mají nejvýše asi 15 000 tokenů, logika pásem tu proto není.
 *
 * `claude-haiku-4-5-20251001` je legacy: v dokumentaci je zatím aktivní, vyřazení nejdříve 15. 10. 2026
 * (zatím neohlášeno, Anthropic slibuje aspoň 60 dní předem). Záznam je jen pro starší `.env`
 * a po ohlášení vyřazení se odstraní.
 */
return [
    'claude-sonnet-5-5' => [
        'input_per_mtok' => 2.00,
        'output_per_mtok' => 10.00,
        'cache_write_per_mtok' => 2.50,
        'cache_read_per_mtok' => 0.10,
        'supports_effort' => true,
    ],
    'claude-haiku-5-5' => [
        'input_per_mtok' => 0.10,
        'output_per_mtok' => 0.50,
        'cache_write_per_mtok' => 0.125,
        'cache_read_per_mtok' => 0.01,
        'supports_effort' => true,
    ],
    // Legacy: jen pro starší `.env`, odstranit po ohlášení vyřazení.
    'claude-haiku-4-5-20251001' => [
        'input_per_mtok' => 1.00,
        'output_per_mtok' => 5.00,
        'cache_write_per_mtok' => 1.25,
        'cache_read_per_mtok' => 0.10,
        'supports_effort' => false,
    ],
];
