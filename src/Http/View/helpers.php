<?php

declare(strict_types=1);

if (!function_exists('e')) {
    /** Escapuje text pro výstup do HTML (obsah i hodnoty atributů v uvozovkách). */
    function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('e_attr')) {
    /** Escapuje hodnotu HTML atributu (vždy v uvozovkách); dnes totéž co e(), ale záměr je v šabloně čitelný. */
    function e_attr(string|int|float|null $value): string
    {
        return e($value);
    }
}

if (!function_exists('csrf_field')) {
    /** Skryté pole s CSRF tokenem pro každý POST formulář; token předává controller. */
    function csrf_field(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . e_attr($token) . '">';
    }
}

if (!function_exists('czech_number')) {
    /** České číslo: mezera jako oddělovač tisíců, desetinná čárka (`1 234`, `0,007000`). */
    function czech_number(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', ' ');
    }
}

if (!function_exists('czech_date')) {
    /** České datum bez rozšíření intl: `3. října 2026` (měsíc ve 2. pádě). */
    function czech_date(DateTimeInterface $date): string
    {
        /** @var array<int, string> $months */
        $months = [
            1 => 'ledna', 2 => 'února', 3 => 'března', 4 => 'dubna', 5 => 'května', 6 => 'června',
            7 => 'července', 8 => 'srpna', 9 => 'září', 10 => 'října', 11 => 'listopadu', 12 => 'prosince',
        ];

        return $date->format('j') . '. ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
    }
}
