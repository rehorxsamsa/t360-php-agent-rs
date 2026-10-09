<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Název (rubriky nebo štítku) a počet jeho publikovaných článků. */
final readonly class NamedCount
{
    public function __construct(
        public string $name,
        public int $articles,
    ) {}
}
