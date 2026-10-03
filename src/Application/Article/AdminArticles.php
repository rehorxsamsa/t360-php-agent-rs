<?php

declare(strict_types=1);

namespace App\Application\Article;

use App\Domain\Article\AdminArticleSummary;
use App\Domain\Article\ArticleAdminRepository;
use App\Domain\Article\EditableArticle;
use App\Domain\Category\Category;
use App\Domain\Category\CategoryRepository;
use App\Domain\Tag\Tag;
use App\Domain\Tag\TagRepository;

/** Čtení pro administraci článků (seznam se stránkováním, článek k úpravě, číselníky). */
final readonly class AdminArticles
{
    public const int PAGE_SIZE = 20;

    public function __construct(
        private ArticleAdminRepository $articles,
        private CategoryRepository $categories,
        private TagRepository $tags,
    ) {}

    /** @return ArticlePage<AdminArticleSummary>|null null, když stránka neexistuje; prázdný seznam je platná strana 1 */
    public function page(int $page): ?ArticlePage
    {
        $total = $this->articles->countAll();
        $totalPages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));

        if ($page < 1 || $page > $totalPages) {
            return null;
        }

        $articles = $this->articles->list(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return new ArticlePage($articles, $page, $totalPages, $total);
    }

    public function find(int $id): ?EditableArticle
    {
        return $this->articles->findForEditing($id);
    }

    /** @return list<Category> */
    public function categories(): array
    {
        return $this->categories->all();
    }

    /** @return list<Tag> */
    public function tags(): array
    {
        return $this->tags->all();
    }
}
