<?php

declare(strict_types=1);

namespace App\Http\Stream;

/**
 * Cíl streamované odpovědi. Producent `Response::stream()` píše jen sem, takže v testech
 * stačí dvojník v paměti a v produkci `PhpStreamOutput` (echo + flush).
 */
interface StreamOutput
{
    /** Zapíše kousek odpovědi a hned ho odešle klientovi. */
    public function write(string $chunk): void;

    /** Klient spojení zavřel (např. tlačítko „Přerušit“) – další zápisy nemají smysl. */
    public function isAborted(): bool;
}
