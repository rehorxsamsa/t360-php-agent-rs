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
