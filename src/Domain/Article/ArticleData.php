<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Ověřený zapisovaný stav článku (výstup validace formuláře, vstup repozitáře). */
final readonly class ArticleData
{
    /** @param list<int> $tagIds ID štítků seřazená vzestupně, bez duplicit */
    public function __construct(
        public string $title,
        public string $slug,
        public string $excerpt,
        public string $body,
        public int $categoryId,
        public array $tagIds,
        public ArticleStatus $status,
        public ?\DateTimeImmutable $publishedAt,
    ) {}

    public function withSlug(string $slug): self
    {
        return new self(
            $this->title,
            $slug,
            $this->excerpt,
            $this->body,
            $this->categoryId,
            $this->tagIds,
            $this->status,
            $this->publishedAt,
        );
    }
}
