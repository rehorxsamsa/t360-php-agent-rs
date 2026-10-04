<?php

declare(strict_types=1);

namespace App\Infrastructure\Seed;

/** Kontrakt souboru seedu v `database/seeds/` (soubor vrací instanci přes `return new class implements Seed`). */
interface Seed
{
    /**
     * Doplní chybějící data (nic nemaže; přepsat smí jen vlastní řádky, které nikdo neupravil).
     *
     * @return array<string, int> počty nově vložených (nebo dorovnaných) řádků s popisky, např. `['rubriky' => 3]`
     */
    public function run(\PDO $pdo): array;
}
