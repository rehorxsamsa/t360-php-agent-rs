<?php

declare(strict_types=1);

/**
 * Katalog modelů Claude, které aplikace smí volat, a jejich ceník.
 *
 * Ověřeno 2026-10-03: https://platform.claude.com/docs/en/about-claude/pricing
 * (modely: https://platform.claude.com/docs/en/about-claude/models/overview)
 *
 * Ceny jsou v USD za milion tokenů (MTok). `cache_write_per_mtok` platí pro cache s životností 5 minut.
 * `supports_effort`: model přijímá `output_config.effort` (Haiku 4.5 ne – klient mu ho neposílá).
 * Model mimo tento seznam se nevolá: bez ceny nejde hlídat denní limit.
 * Ceny a modely zastarávají – při změně uprav hodnoty i datum ověření výše.
 *
 * `claude-haiku-4-5-20251001`: v dokumentaci aktivní, vyřazení „nejdříve 15. 10. 2026“ (zatím neohlášeno).
 */
return [
    'claude-sonnet-5-5' => [
        'input_per_mtok' => 2.00,
        'output_per_mtok' => 10.00,
        'cache_write_per_mtok' => 2.50,
        'cache_read_per_mtok' => 0.20,
        'supports_effort' => true,
    ],
    'claude-haiku-4-5-20251001' => [
        'input_per_mtok' => 1.00,
        'output_per_mtok' => 5.00,
        'cache_write_per_mtok' => 1.25,
        'cache_read_per_mtok' => 0.10,
        'supports_effort' => false,
    ],
];
