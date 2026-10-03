<?php

declare(strict_types=1);

if (!function_exists('e')) {
    /** Escapuje text pro výstup do HTML (obsah i hodnoty atributů v uvozovkách). */
    function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
