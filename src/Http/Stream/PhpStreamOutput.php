<?php

declare(strict_types=1);

namespace App\Http\Stream;

/**
 * Výstup přímo do PHP-FPM. Každý zápis se hned odešle (`flush()`); odpojení klienta PHP pozná
 * až při zápisu, proto se `isAborted()` ptá po něm. `Response::send()` předtím vyprázdní
 * output buffery a zapne `ignore_user_abort(true)`, aby skript po odpojení doběhl a zalogoval volání.
 */
final class PhpStreamOutput implements StreamOutput
{
    public function write(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        echo $chunk;
        flush();
    }

    public function isAborted(): bool
    {
        return connection_aborted() === 1;
    }
}
