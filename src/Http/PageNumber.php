<?php

declare(strict_types=1);

namespace App\Http;

/** Číslo strany z query stringu (`?strana=N`) – sdílí titulní stránka i administrace. */
final class PageNumber
{
    /** Číslo strany: 1–6 číslic bez úvodní nuly (omezuje délku vstupu, žádné `01`, `-1`, `1.5`). */
    private const string PATTERN = '/^[1-9][0-9]{0,5}\z/';

    /** @throws PageNotFound neplatné číslo strany */
    public static function fromQuery(string $value): int
    {
        if ($value === '') {
            return 1;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new PageNotFound();
        }

        return (int) $value;
    }
}
