<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Article;

use App\Application\Article\PublishedArticles;
use App\Domain\Article\ArticleDetail;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryArticleRepository;
use PHPUnit\Framework\TestCase;

final class PublishedArticlesTest extends TestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = FixedClock::at('2026-10-03 12:00:00');
    }

    private function service(InMemoryArticleRepository $repository): PublishedArticles
    {
        return new PublishedArticles($repository, $this->clock);
    }

    public function test_first_page_has_ten_articles_and_next_link_only(): void
    {
        $repository = InMemoryArticleRepository::withPublishedCount(12);

        $page = $this->service($repository)->page(1);

        self::assertNotNull($page);
        self::assertCount(10, $page->articles);
        self::assertSame($repository->summaries[0], $page->articles[0]);
        self::assertSame(1, $page->page);
        self::assertSame(2, $page->totalPages);
        self::assertSame(12, $page->total);
        self::assertFalse($page->hasPrevious());
        self::assertTrue($page->hasNext());
        self::assertSame(10, $repository->lastLimit);
        self::assertSame(0, $repository->lastOffset);
    }

    public function test_repository_receives_time_from_clock(): void
    {
        $repository = InMemoryArticleRepository::withPublishedCount(12);

        $this->service($repository)->page(1);

        self::assertEquals($this->clock->now(), $repository->lastNow);
    }

    public function test_second_page_has_remaining_articles_and_previous_link_only(): void
    {
        $repository = InMemoryArticleRepository::withPublishedCount(12);

        $page = $this->service($repository)->page(2);

        self::assertNotNull($page);
        self::assertCount(2, $page->articles);
        self::assertSame(10, $repository->lastOffset);
        self::assertSame(2, $page->page);
        self::assertTrue($page->hasPrevious());
        self::assertFalse($page->hasNext());
    }

    public function test_page_beyond_last_returns_null(): void
    {
        self::assertNull($this->service(InMemoryArticleRepository::withPublishedCount(12))->page(3));
    }

    public function test_page_below_one_returns_null(): void
    {
        $service = $this->service(InMemoryArticleRepository::withPublishedCount(12));

        self::assertNull($service->page(0));
        self::assertNull($service->page(-1));
    }

    public function test_exactly_ten_articles_fit_on_one_page(): void
    {
        $service = $this->service(InMemoryArticleRepository::withPublishedCount(10));

        $page = $service->page(1);

        self::assertNotNull($page);
        self::assertSame(1, $page->totalPages);
        self::assertFalse($page->hasNext());
        self::assertNull($service->page(2));
    }

    public function test_empty_repository_has_single_empty_first_page(): void
    {
        $service = $this->service(new InMemoryArticleRepository());

        $page = $service->page(1);

        self::assertNotNull($page);
        self::assertSame([], $page->articles);
        self::assertSame(1, $page->totalPages);
        self::assertSame(0, $page->total);
        self::assertFalse($page->hasPrevious());
        self::assertFalse($page->hasNext());
        self::assertNull($service->page(2));
    }

    public function test_page_size_constant_is_ten(): void
    {
        self::assertSame(10, PublishedArticles::PAGE_SIZE);
    }

    public function test_find_by_invalid_slug_returns_null_without_calling_repository(): void
    {
        $repository = new InMemoryArticleRepository();

        self::assertNull($this->service($repository)->findBySlug('Neplatny slug'));
        self::assertNull($this->service($repository)->findBySlug(''));
        self::assertSame(0, $repository->findCalls);
    }

    public function test_find_by_valid_slug_returns_repository_result_with_clock_time(): void
    {
        $repository = new InMemoryArticleRepository();
        $detail = new ArticleDetail(
            title: 'Titulek',
            slug: 'titulek',
            excerpt: 'Perex',
            publishedAt: new \DateTimeImmutable('2026-09-12 08:00:00', new \DateTimeZone('Europe/Prague')),
            categoryName: 'Technologie',
            body: 'Text',
            tagNames: ['PHP'],
        );
        $repository->details['titulek'] = $detail;

        $service = $this->service($repository);

        self::assertSame($detail, $service->findBySlug('titulek'));
        self::assertSame('titulek', $repository->lastSlug);
        self::assertEquals($this->clock->now(), $repository->lastNow);
        self::assertNull($service->findBySlug('neexistuje'));
    }
}
