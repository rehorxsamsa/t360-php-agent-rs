<?php

declare(strict_types=1);

namespace App\Application\Article;

use App\Domain\Article\ArticleDetail;
use App\Domain\Article\ArticleRepository;
use App\Domain\Article\Slug;
use App\Domain\Time\Clock;

/** Veřejné čtení publikovaných článků (výpis se stránkováním a detail). */
final readonly class PublishedArticles
{
    public const int PAGE_SIZE = 10;

    public function __construct(
        private ArticleRepository $articles,
        private Clock $clock,
    ) {}

    /** @return ArticlePage|null null, když stránka neexistuje (mimo rozsah); prázdný výpis je platná strana 1 */
    public function page(int $page): ?ArticlePage
    {
        $now = $this->clock->now();
        $total = $this->articles->countPublished($now);
        $totalPages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));

        if ($page < 1 || $page > $totalPages) {
            return null;
        }

        $articles = $this->articles->latestPublished($now, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return new ArticlePage($articles, $page, $totalPages, $total);
    }

    /** Neplatný slug vrací null bez dotazu do databáze. */
    public function findBySlug(string $slug): ?ArticleDetail
    {
        if (!Slug::isValid($slug)) {
            return null;
        }

        return $this->articles->findPublishedBySlug($slug, $this->clock->now());
    }
}
