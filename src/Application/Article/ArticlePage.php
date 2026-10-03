<?php

declare(strict_types=1);

namespace App\Application\Article;

/**
 * Jedna stránka výpisu článků (veřejný výpis i seznam v administraci).
 *
 * @template T of object
 */
final readonly class ArticlePage
{
    /** @param list<T> $articles */
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
