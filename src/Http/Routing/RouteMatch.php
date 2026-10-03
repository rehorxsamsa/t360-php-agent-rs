<?php

declare(strict_types=1);

namespace App\Http\Routing;

final readonly class RouteMatch
{
    /**
     * @param array{class-string, string} $handler třída controlleru a název metody
     * @param array<string, string> $parameters hodnoty z {zástupných} částí cesty
     */
    public function __construct(
        public array $handler,
        public array $parameters,
    ) {}
}
