<?php

declare(strict_types=1);

namespace App\Ai\Examples;

/** Popis příkladu pro přehled v administraci (společný pro všechny příklady 01–07). */
interface ExampleDescription
{
    /** Dvoumístné číslo příkladu, např. `01`. */
    public function id(): string;

    /** Název v UI, např. „Perex na jedno kliknutí“. */
    public function title(): string;

    public function description(): string;
}
