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

    /** Délka slugu z titulku; zbytek do MAX_LENGTH je rezerva pro příponu `-N` při kolizi. */
    public const int BASE_MAX_LENGTH = 200;

    /** Slug pro text, ze kterého nezbyde žádné písmeno ani číslice. */
    public const string FALLBACK = 'clanek';

    /**
     * Převod diakritiky na základní písmena (čeština, slovenština, němčina).
     * Vlastní tabulka místo intl / iconv //TRANSLIT, jejichž výsledek závisí na locale kontejneru.
     */
    private const array TRANSLITERATION = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
        'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ŕ' => 'r',
        'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y',
        'ž' => 'z', 'ß' => 'ss',
        'Á' => 'a', 'Ä' => 'a', 'Č' => 'c', 'Ď' => 'd', 'É' => 'e', 'Ě' => 'e', 'Í' => 'i',
        'Ľ' => 'l', 'Ĺ' => 'l', 'Ň' => 'n', 'Ó' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'Ŕ' => 'r',
        'Ř' => 'r', 'Š' => 's', 'Ť' => 't', 'Ú' => 'u', 'Ů' => 'u', 'Ü' => 'u', 'Ý' => 'y',
        'Ž' => 'z', 'ẞ' => 'ss',
    ];

    public static function isValid(string $value): bool
    {
        return strlen($value) <= self::MAX_LENGTH && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Vytvoří slug z libovolného textu (titulek, ručně zadaný slug). Výsledek je vždy platný
     * a nejvýše BASE_MAX_LENGTH znaků dlouhý; z textu bez písmen a číslic vznikne FALLBACK.
     */
    public static function fromText(string $text): string
    {
        $ascii = strtolower(strtr($text, self::TRANSLITERATION));
        // Vše mimo a–z a 0–9 (včetně zbylých bajtů vícebajtových znaků) se stane oddělovačem.
        $dashed = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        $slug = rtrim(substr($dashed, 0, self::BASE_MAX_LENGTH), '-');

        return $slug === '' ? self::FALLBACK : $slug;
    }

    /**
     * Vrátí `$base`, nebo první volné `$base-2`, `$base-3`, … podle seznamu obsazených slugů.
     *
     * @param list<string> $taken obsazené slugy (smí obsahovat i nesouvisející hodnoty)
     */
    public static function uniqueAmong(string $base, array $taken): string
    {
        $takenSet = array_flip($taken);
        if (!isset($takenSet[$base])) {
            return $base;
        }

        $suffix = 2;
        while (isset($takenSet[$base . '-' . $suffix])) {
            ++$suffix;
        }

        return $base . '-' . $suffix;
    }
}
