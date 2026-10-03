<?php

declare(strict_types=1);

namespace App\Application\Article;

use App\Domain\Article\ArticleSummary;

/** Jedna stránka výpisu publikovaných článků. */
final readonly class ArticlePage
{
    /** @param list<ArticleSummary> $articles */
    public function __construct(
        public array $articles,
        public int $page,
        public int $totalPages,
        public int $total,
    ) {}

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages;
    }
}
