<?php

declare(strict_types=1);

namespace App\Infrastructure\Seed;

/** Kontrakt souboru seedu v `database/seeds/` (soubor vrací instanci přes `return new class implements Seed`). */
interface Seed
{
    /**
     * Doplní chybějící data (nic nemaže ani nepřepisuje).
     *
     * @return array<string, int> počty nově vložených řádků s popisky, např. `['rubriky' => 3]`
     */
    public function run(\PDO $pdo): array;
}
