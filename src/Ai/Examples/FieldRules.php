<?php

declare(strict_types=1);

namespace App\Ai\Examples;

/**
 * Pomocná pravidla pro validaci výstupu modelu v PHP. Vracejí českou chybu ve tvaru
 * „pole: popis“ (jde zpět modelu při opakování), nebo null, když je hodnota v pořádku.
 */
final class FieldRules
{
    /**
     * Řetězec s délkou `$min` až `$max` znaků (po ořezání mezer).
     *
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key, int $min, int $max): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            return $key . ': musí být řetězec';
        }

        $length = mb_strlen(trim($value));
        if ($length < $min) {
            return $min === 1 ? $key . ': nesmí být prázdné' : sprintf('%s: alespoň %d znaků', $key, $min);
        }

        if ($length > $max) {
            return sprintf('%s: nejvýše %d znaků (je %d)', $key, $max, $length);
        }

        return null;
    }

    /**
     * Hodnota z povolené množiny.
     *
     * @param array<mixed> $data
     * @param list<string> $allowed
     */
    public static function oneOf(array $data, string $key, array $allowed): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            return sprintf('%s: musí být jedna z hodnot %s', $key, implode(', ', $allowed));
        }

        return null;
    }

    /**
     * Seznam řetězců s počtem `$minItems` až `$maxItems` položek, každá `$minLength` až `$maxLength` znaků.
     *
     * @param array<mixed> $data
     * @return list<string> chyby
     */
    public static function stringList(
        array $data,
        string $key,
        int $minItems,
        int $maxItems,
        int $minLength,
        int $maxLength,
    ): array {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            return [$key . ': musí být seznam řetězců'];
        }

        $count = count($value);
        if ($count < $minItems || $count > $maxItems) {
            return [sprintf('%s: %d až %d položek (je %d)', $key, $minItems, $maxItems, $count)];
        }

        $errors = [];
        foreach ($value as $index => $item) {
            if (!is_string($item)) {
                $errors[] = sprintf('%s[%d]: musí být řetězec', $key, $index);
                continue;
            }

            $length = mb_strlen(trim($item));
            if ($length < $minLength || $length > $maxLength) {
                $errors[] = sprintf('%s[%d]: %d až %d znaků (je %d)', $key, $index, $minLength, $maxLength, $length);
            }
        }

        return $errors;
    }

    /** @param array<mixed> $data */
    public static function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
