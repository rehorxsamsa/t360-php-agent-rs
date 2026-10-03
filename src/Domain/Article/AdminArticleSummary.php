<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Řádek seznamu článků v administraci (všechny stavy). */
final readonly class AdminArticleSummary
{
    public function __construct(
        public int $id,
        public string $title,
        public string $slug,
        public ArticleStatus $status,
        public string $categoryName,
        public ?\DateTimeImmutable $publishedAt,
        public \DateTimeImmutable $updatedAt,
        public ?string $updatedByName,
    ) {}

    /** Publikovaný článek s datem v budoucnosti – veřejnost ho zatím nevidí. */
    public function isScheduled(\DateTimeImmutable $now): bool
    {
        return $this->status === ArticleStatus::Published
            && $this->publishedAt !== null
            && $this->publishedAt > $now;
    }
}
