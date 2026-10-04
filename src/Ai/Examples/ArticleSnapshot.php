<?php

declare(strict_types=1);

namespace App\Ai\Examples;

/** Obsah článku, nad kterým běží AI příklad (z databáze, nebo ukázkový z `DemoArticles`). */
final readonly class ArticleSnapshot
{
    public function __construct(
        public string $title,
        public string $slug,
        public string $excerpt,
        public string $body,
    ) {}
}
