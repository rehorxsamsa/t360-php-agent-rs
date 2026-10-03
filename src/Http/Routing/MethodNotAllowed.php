<?php

declare(strict_types=1);

namespace App\Http\Routing;

final class MethodNotAllowed extends \RuntimeException
{
    /**
     * @param list<string> $allowedMethods metody, které cesta podporuje (seřazené)
     */
    public function __construct(public readonly array $allowedMethods)
    {
        parent::__construct('Metoda není povolena.');
    }
}
