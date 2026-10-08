<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Publikovaný článek nalezený sémanticky; `distance` je kosinová vzdálenost (0 = shoda, 1 = kolmé). */
final readonly class SimilarArticle
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $excerpt,
        public string $body,
        public string $categoryName,
        public \DateTimeImmutable $publishedAt,
        public float $distance,
    ) {}

    public function url(): string
    {
        return '/clanek/' . $this->slug;
    }
}
