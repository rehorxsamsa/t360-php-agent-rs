<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Článek načtený pro formulář úprav (všechny stavy). */
final readonly class EditableArticle
{
    /** @param list<int> $tagIds ID štítků seřazená vzestupně */
    public function __construct(
        public int $id,
        public string $title,
        public string $slug,
        public string $excerpt,
        public string $body,
        public int $categoryId,
        public array $tagIds,
        public ArticleStatus $status,
        public ?\DateTimeImmutable $publishedAt,
        public \DateTimeImmutable $updatedAt,
        public ?string $updatedByName,
    ) {}

    /** Je článek právě teď veřejně čitelný (stejné pravidlo jako veřejný ArticleRepository)? */
    public function isPubliclyVisible(\DateTimeImmutable $now): bool
    {
        return $this->status === ArticleStatus::Published
            && $this->publishedAt !== null
            && $this->publishedAt <= $now;
    }
}
