<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Publikovaný článek pro detail; `body` je Markdown (nevykreslený). */
final readonly class ArticleDetail
{
    /** @param list<string> $tagNames názvy štítků seřazené česky */
    public function __construct(
        public string $title,
        public string $slug,
        public string $excerpt,
        public \DateTimeImmutable $publishedAt,
        public string $categoryName,
        public string $body,
        public array $tagNames,
    ) {}
}
