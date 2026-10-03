<?php

declare(strict_types=1);

namespace App\Domain\Health;

interface DatabaseHealth
{
    /** Vrací true, když je databáze dosažitelná a odpovídá na dotazy. */
    public function isReachable(): bool;
}
