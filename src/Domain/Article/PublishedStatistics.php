<?php

declare(strict_types=1);

namespace App\Domain\Article;

/**
 * Souhrnné statistiky veřejně čitelných článků (jen publikované s `published_at <= now`).
 * Koncepty, archiv ani naplánované články se do žádného čísla nepočítají.
 */
final readonly class PublishedStatistics
{
    /**
     * @param list<NamedCount> $categories rubriky s alespoň jedním publikovaným článkem, podle počtu sestupně, při shodě podle názvu
     * @param list<NamedCount> $tags nejčastější štítky publikovaných článků, podle počtu sestupně, při shodě podle názvu
     */
    public function __construct(
        public int $publishedCount,
        public int $publishedLast30Days,
        public ?\DateTimeImmutable $latestPublishedAt,
        public array $categories,
        public array $tags,
    ) {}
}
