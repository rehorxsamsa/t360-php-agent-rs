<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Článek ve výpisu (bez textu a štítků). */
final readonly class ArticleSummary
{
    public function __construct(
        public string $title,
        public string $slug,
        public string $excerpt,
        public \DateTimeImmutable $publishedAt,
        public string $categoryName,
    ) {}
}
