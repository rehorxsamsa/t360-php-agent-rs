<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Article;

use App\Application\Article\AdminArticles;
use App\Domain\Article\AdminArticleSummary;
use App\Domain\Article\ArticleStatus;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryCategoryRepository;
use App\Tests\Unit\Support\InMemoryTagRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\TestCase;

/** Plán 005, §2 (AC 19 na úrovni Application): čtení pro administraci po 20 článcích. */
final class AdminArticlesTest extends TestCase
{
    private InMemoryArticleAdminRepository $articles;
    private AdminArticles $service;

    protected function setUp(): void
    {
        $this->articles = new InMemoryArticleAdminRepository();
        $container = TestContainer::withoutSession(
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
            adminArticles: $this->articles,
            categories: new InMemoryCategoryRepository(),
            tags: new InMemoryTagRepository(),
        );
        $this->service = $container->get(AdminArticles::class);
    }

    private function withSummaries(int $count): void
    {
        $summaries = [];
        for ($id = $count; $id >= 1; --$id) {
            $summaries[] = new AdminArticleSummary(
                id: $id,
                title: sprintf('Článek %d', $id),
                slug: sprintf('clanek-%d', $id),
                status: ArticleStatus::Draft,
                categoryName: 'Technologie',
                publishedAt: null,
                updatedAt: new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('Europe/Prague')),
                updatedByName: null,
            );
        }
        $this->articles->summaries = $summaries;
    }

    public function test_page_size_is_twenty(): void
    {
        self::assertSame(20, AdminArticles::PAGE_SIZE);
    }

    public function test_first_page_of_twenty_one_articles(): void
    {
        $this->withSummaries(21);

        $page = $this->service->page(1);

        self::assertNotNull($page);
        self::assertCount(20, $page->articles);
        self::assertSame(1, $page->page);
        self::assertSame(2, $page->totalPages);
        self::assertSame(21, $page->total);
        self::assertTrue($page->hasNext());
        self::assertFalse($page->hasPrevious());
        self::assertSame([['limit' => 20, 'offset' => 0]], $this->articles->listCalls);
    }

    public function test_second_page_has_remaining_article(): void
    {
        $this->withSummaries(21);

        $page = $this->service->page(2);

        self::assertNotNull($page);
        self::assertCount(1, $page->articles);
        self::assertSame([['limit' => 20, 'offset' => 20]], $this->articles->listCalls);
    }

    public function test_out_of_range_pages_are_null(): void
    {
        $this->withSummaries(21);

        self::assertNull($this->service->page(3));
        self::assertNull($this->service->page(0));
    }

    public function test_empty_list_is_valid_first_page(): void
    {
        $page = $this->service->page(1);

        self::assertNotNull($page);
        self::assertSame([], $page->articles);
        self::assertSame(1, $page->totalPages);
        self::assertNull($this->service->page(2));
    }

    public function test_find_returns_article_or_null(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5));

        self::assertSame(5, $this->service->find(5)?->id);
        self::assertNull($this->service->find(404));
    }

    public function test_categories_and_tags_come_from_repositories(): void
    {
        self::assertSame(['Technologie', 'Věda a výzkum', 'Zprávy'], array_map(static fn($c): string => $c->name, $this->service->categories()));
        self::assertSame(['Bezpečnost', 'Docker', 'PHP'], array_map(static fn($t): string => $t->name, $this->service->tags()));
    }
}
