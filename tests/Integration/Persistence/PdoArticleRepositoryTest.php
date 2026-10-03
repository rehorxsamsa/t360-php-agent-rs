<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Article\ArticleRepository;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoArticleRepository;
use App\Infrastructure\Seed\Seed;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Čtení veřejných článků nad redakce_test s ukázkovými daty (plán 004, AC 19-21). */
final class PdoArticleRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoArticleRepository $repository;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();

        $seed = require __DIR__ . '/../../../database/seeds/demo_content.php';
        self::assertInstanceOf(Seed::class, $seed);
        $seed->run($this->pdo);

        $this->repository = new PdoArticleRepository($this->pdo);
        $this->now = new \DateTimeImmutable('2026-10-03 12:00:00', new \DateTimeZone('Europe/Prague'));
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    /** Počet provedených SELECTů mezi dvěma čteními čítače, očištěný o režii samotného měření. */
    private function countSelects(callable $work): int
    {
        $read = fn(): int => (int) TestDatabase::rows($this->pdo, "SHOW SESSION STATUS LIKE 'Com_select'")[0]['Value'];

        $read();
        $baselineStart = $read();
        $baselineEnd = $read();
        $overhead = $baselineEnd - $baselineStart;

        $before = $read();
        $work();
        $after = $read();

        return $after - $before - $overhead;
    }

    public function test_implements_domain_interface(): void
    {
        self::assertInstanceOf(ArticleRepository::class, $this->repository);
    }

    public function test_latest_published_returns_ten_newest_in_descending_order_with_category(): void
    {
        $articles = $this->repository->latestPublished($this->now, 10, 0);

        self::assertCount(10, $articles);
        self::assertSame('ukazka-markdownu', $articles[0]->slug);
        self::assertSame('sablony-a-escapovani', $articles[1]->slug);
        self::assertSame('Technologie', $articles[0]->categoryName);
        for ($i = 1; $i < count($articles); ++$i) {
            self::assertGreaterThan($articles[$i]->publishedAt, $articles[$i - 1]->publishedAt);
            self::assertNotSame('', $articles[$i]->categoryName);
        }
    }

    public function test_second_page_has_two_articles_ending_with_oldest(): void
    {
        $articles = $this->repository->latestPublished($this->now, 10, 10);

        self::assertCount(2, $articles);
        self::assertSame('prvni-clanek', $articles[1]->slug);
    }

    public function test_hidden_articles_never_appear_in_listing(): void
    {
        $slugs = array_map(
            static fn($article): string => $article->slug,
            $this->repository->latestPublished($this->now, 100, 0),
        );

        self::assertCount(12, $slugs);
        foreach (['rozepsany-koncept', 'druhy-koncept', 'archivni-clanek', 'planovany-clanek'] as $hidden) {
            self::assertNotContains($hidden, $slugs);
        }
    }

    public function test_count_published_respects_status_and_time(): void
    {
        self::assertSame(12, $this->repository->countPublished($this->now));
        self::assertSame(13, $this->repository->countPublished(new \DateTimeImmutable('2099-01-02 00:00:00', new \DateTimeZone('Europe/Prague'))));
    }

    public function test_article_scheduled_for_future_is_visible_after_its_time(): void
    {
        $later = new \DateTimeImmutable('2099-01-02 00:00:00', new \DateTimeZone('Europe/Prague'));

        self::assertNull($this->repository->findPublishedBySlug('planovany-clanek', $this->now));
        self::assertNotNull($this->repository->findPublishedBySlug('planovany-clanek', $later));
    }

    public function test_find_published_by_slug_returns_detail_with_tags_and_raw_markdown(): void
    {
        $detail = $this->repository->findPublishedBySlug('ukazka-markdownu', $this->now);

        self::assertNotNull($detail);
        self::assertSame('Ukázka Markdownu', $detail->title);
        self::assertSame('ukazka-markdownu', $detail->slug);
        self::assertNotSame('', $detail->excerpt);
        self::assertStringContainsString('## ', $detail->body);
        self::assertStringNotContainsString('<h2>', $detail->body);
        self::assertSame('2026-09-12 08:00', $detail->publishedAt->format('Y-m-d H:i'));
        self::assertSame('Europe/Prague', $detail->publishedAt->getTimezone()->getName());
        self::assertSame('Technologie', $detail->categoryName);
        self::assertSame(['Bezpečnost', 'PHP'], $detail->tagNames);
    }

    public function test_unpublished_or_unknown_slugs_return_null(): void
    {
        foreach (['rozepsany-koncept', 'druhy-koncept', 'archivni-clanek', 'planovany-clanek', 'neexistuje'] as $slug) {
            self::assertNull($this->repository->findPublishedBySlug($slug, $this->now), $slug);
        }
    }

    public function test_listing_uses_single_select(): void
    {
        self::assertSame(1, $this->countSelects(fn() => $this->repository->latestPublished($this->now, 10, 0)));
    }

    public function test_count_uses_single_select(): void
    {
        self::assertSame(1, $this->countSelects(fn() => $this->repository->countPublished($this->now)));
    }

    public function test_detail_uses_at_most_two_selects(): void
    {
        $selects = $this->countSelects(fn() => $this->repository->findPublishedBySlug('ukazka-markdownu', $this->now));

        self::assertGreaterThanOrEqual(1, $selects);
        self::assertLessThanOrEqual(2, $selects);
    }
}
