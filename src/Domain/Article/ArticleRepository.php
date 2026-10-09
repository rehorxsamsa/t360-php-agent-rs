<?php

declare(strict_types=1);

namespace App\Domain\Article;

/**
 * Čtení článků pro veřejnost. Všechny metody vracejí jen články, které jsou
 * publikované a mají `published_at <= $now`; koncepty, archiv a naplánované články ne.
 */
interface ArticleRepository
{
    /** @return list<ArticleSummary> nejnovější první */
    public function latestPublished(\DateTimeImmutable $now, int $limit, int $offset): array;

    public function countPublished(\DateTimeImmutable $now): int;

    public function findPublishedBySlug(string $slug, \DateTimeImmutable $now): ?ArticleDetail;

    /**
     * Hledání podřetězce v titulku, perexu a textu (bez ohledu na velikost písmen; `%` a `_`
     * se hledají doslova). Prázdný dotaz (po `trim`) vrací `[]`.
     *
     * @return list<ArticleSummary> nejnovější první, nejvýše `$limit`
     */
    public function searchPublished(string $query, \DateTimeImmutable $now, int $limit): array;

    /**
     * Statistiky publikovaných článků: celkový počet, počet za posledních 30 dní
     * (`published_at` v intervalu (`$now − 30 dní`, `$now`]), datum nejnovějšího článku,
     * rubriky s alespoň jedním publikovaným článkem a nejvýše `$tagLimit` nejčastějších štítků.
     * Rubriky i štítky jsou seřazené podle počtu sestupně, při shodě podle názvu (česká kolace).
     */
    public function publishedStatistics(\DateTimeImmutable $now, int $tagLimit): PublishedStatistics;
}
