<?php

declare(strict_types=1);

namespace App\Domain\Article;

/**
 * Pravidla pro slug článku (část URL).
 *
 * Validace je nutná i při čtení: kolace utf8mb4_czech_ci je necitlivá na velikost písmen
 * a ignoruje mezery na konci, takže by bez ní jeden článek měl více platných adres.
 */
final class Slug
{
    /** Modifikátor D: `$` nesmí projít před koncovým novým řádkem. */
    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /** Délka sloupce `articles.slug`. */
    public const int MAX_LENGTH = 220;

    public static function isValid(string $value): bool
    {
        return strlen($value) <= self::MAX_LENGTH && preg_match(self::PATTERN, $value) === 1;
    }
}
