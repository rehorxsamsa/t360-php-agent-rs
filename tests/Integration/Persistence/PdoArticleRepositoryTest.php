<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Article\ArticleRepository;
use App\Domain\Article\NamedCount;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoArticleRepository;
use App\Infrastructure\Seed\Seed;
use App\Tests\Integration\StatementCounter;
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

    /** Plán 008, AC 23: přímý zápis do testovací DB (připraví data, která seed nemá). */
    private function updateArticle(string $slug, string $column, string $value): void
    {
        if (!in_array($column, ['title', 'excerpt', 'body', 'published_at'], true)) {
            throw new \InvalidArgumentException('Nepovolený sloupec ' . $column);
        }
        $statement = $this->pdo->prepare('UPDATE articles SET ' . $column . ' = :value WHERE slug = :slug');
        $statement->execute(['value' => $value, 'slug' => $slug]);
        self::assertSame(1, $statement->rowCount(), $slug);
    }

    /** Koncept, archivní a naplánovaný článek obsahují „Docker“ (titulek, perex i text). */
    private function hiddenArticlesMentionDocker(): void
    {
        $this->updateArticle('druhy-koncept', 'body', 'Koncept článku o tom, jak redaktoři využijí jazykové modely a Docker.');
        $this->updateArticle('archivni-clanek', 'title', 'Archivní zpráva o Dockeru');
        $this->updateArticle('planovany-clanek', 'excerpt', 'Naplánovaný článek o Dockeru.');
        $this->updateArticle('rozepsany-koncept', 'body', 'docker docker docker');
    }

    /**
     * @param list<\App\Domain\Article\ArticleSummary> $summaries
     *
     * @return list<string>
     */
    private static function slugs(array $summaries): array
    {
        return array_map(static fn($summary): string => $summary->slug, $summaries);
    }

    public function test_search_returns_only_published_articles_up_to_now(): void
    {
        $this->hiddenArticlesMentionDocker();

        $found = $this->repository->searchPublished('docker', $this->now, 5);

        self::assertSame(['docker-pro-vyvojare'], self::slugs($found));
        self::assertSame('Docker pro vývojáře: proč na něm záleží', $found[0]->title);
        self::assertSame('Technologie', $found[0]->categoryName);
        self::assertSame('2026-09-02 08:00', $found[0]->publishedAt->format('Y-m-d H:i'));
        self::assertSame('Europe/Prague', $found[0]->publishedAt->getTimezone()->getName());
        self::assertNotSame('', $found[0]->excerpt);
    }

    public function test_search_finds_scheduled_article_once_its_time_has_come(): void
    {
        $this->hiddenArticlesMentionDocker();
        $later = new \DateTimeImmutable('2099-01-02 00:00:00', new \DateTimeZone('Europe/Prague'));

        self::assertSame(
            ['planovany-clanek', 'docker-pro-vyvojare'],
            self::slugs($this->repository->searchPublished('docker', $later, 5)),
        );
    }

    public function test_search_ignores_letter_case_and_trims_query(): void
    {
        self::assertSame(['docker-pro-vyvojare'], self::slugs($this->repository->searchPublished('  DOCKER ', $this->now, 5)));
    }

    public function test_search_looks_into_title_excerpt_and_body(): void
    {
        self::assertSame(['docker-pro-vyvojare'], self::slugs($this->repository->searchPublished('pro vývojáře', $this->now, 5)), 'titulek');
        self::assertSame(['docker-pro-vyvojare'], self::slugs($this->repository->searchPublished('denní postup', $this->now, 5)), 'perex');
        self::assertSame(['docker-pro-vyvojare'], self::slugs($this->repository->searchPublished('naklonujete repozitář', $this->now, 5)), 'text');
    }

    public function test_search_orders_newest_first_and_respects_limit(): void
    {
        $expected = self::slugs($this->repository->latestPublished($this->now, 3, 0));

        // Písmeno „a“ je v každém publikovaném článku, takže výsledek musí být shodný s výpisem nejnovějších.
        self::assertSame($expected, self::slugs($this->repository->searchPublished('a', $this->now, 3)));
        self::assertCount(1, $this->repository->searchPublished('a', $this->now, 1));
    }

    public function test_search_with_same_publication_time_puts_higher_id_first(): void
    {
        $this->updateArticle('nova-studie-o-spanku', 'published_at', '2026-09-02 08:00:00');
        $this->updateArticle('nova-studie-o-spanku', 'body', 'Spánek a Docker nesouvisí.');
        $ids = [];
        foreach (TestDatabase::rows($this->pdo, "SELECT slug, id FROM articles WHERE slug IN ('docker-pro-vyvojare', 'nova-studie-o-spanku')") as $row) {
            $ids[(string) $row['slug']] = (int) $row['id'];
        }
        arsort($ids);

        self::assertSame(array_keys($ids), self::slugs($this->repository->searchPublished('docker', $this->now, 5)));
    }

    public function test_like_wildcards_and_escape_character_are_searched_literally(): void
    {
        $this->updateArticle('prvni-clanek', 'excerpt', 'Kód x%y v perexu.');
        $this->updateArticle('nova-studie-o-spanku', 'excerpt', 'Kód x_y v perexu.');
        $this->updateArticle('pristupnost-webu-v-praxi', 'excerpt', 'Kód z!k v perexu.');

        self::assertSame(['prvni-clanek'], self::slugs($this->repository->searchPublished('x%y', $this->now, 5)));
        self::assertSame(['nova-studie-o-spanku'], self::slugs($this->repository->searchPublished('x_y', $this->now, 5)));
        self::assertSame(['pristupnost-webu-v-praxi'], self::slugs($this->repository->searchPublished('z!k', $this->now, 5)));
        self::assertSame([], $this->repository->searchPublished('x\\y', $this->now, 5));
    }

    public function test_search_uses_single_select(): void
    {
        self::assertSame(1, $this->countSelects(fn() => $this->repository->searchPublished('docker', $this->now, 5)));
    }

    public function test_empty_query_returns_nothing_without_query(): void
    {
        self::assertSame(0, $this->countSelects(function (): void {
            self::assertSame([], $this->repository->searchPublished('', $this->now, 5));
            self::assertSame([], $this->repository->searchPublished("  \n ", $this->now, 5));
        }));
    }

    // ---------------------------------------------------------------- plán 011, AC 2: statistiky publikovaných

    /** Smaže obsah seedu (články, štítky, rubriky), schéma zůstává. */
    private function clearContent(): void
    {
        foreach (['article_tags', 'articles', 'tags', 'categories'] as $table) {
            $this->pdo->exec('DELETE FROM ' . $table);
        }
    }

    private function insertCategory(string $name, string $slug): int
    {
        $this->pdo->prepare('INSERT INTO categories (name, slug) VALUES (:name, :slug)')->execute(['name' => $name, 'slug' => $slug]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertTag(string $name, string $slug): int
    {
        $this->pdo->prepare('INSERT INTO tags (name, slug) VALUES (:name, :slug)')->execute(['name' => $name, 'slug' => $slug]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<int> $tagIds */
    private function insertArticle(string $slug, int $categoryId, string $status, ?string $publishedAt, array $tagIds = []): void
    {
        $this->pdo->prepare(
            'INSERT INTO articles (category_id, title, slug, excerpt, body, status, published_at)'
            . ' VALUES (:category, :title, :slug, :excerpt, :body, :status, :published_at)',
        )->execute([
            'category' => $categoryId,
            'title' => 'Článek ' . $slug,
            'slug' => $slug,
            'excerpt' => 'Perex.',
            'body' => 'Text.',
            'status' => $status,
            'published_at' => $publishedAt,
        ]);
        $articleId = (int) $this->pdo->lastInsertId();
        foreach ($tagIds as $tagId) {
            $this->pdo->prepare('INSERT INTO article_tags (article_id, tag_id) VALUES (:article, :tag)')
                ->execute(['article' => $articleId, 'tag' => $tagId]);
        }
    }

    /**
     * Rubriky „Čeština“ a „Cestování“ po 2 publikovaných, „Zprávy“ jen koncept, archivní a naplánovaný (o sekundu
     * po „teď“); štítky se 3, 2 a 1 publikovaným článkem a štítek jen u konceptu. „Teď“ = 2026-10-03 12:00,
     * okno 30 dní = (2026-09-03 12:00, 2026-10-03 12:00].
     */
    private function statisticsFixture(): void
    {
        $this->clearContent();
        $czech = $this->insertCategory('Čeština', 'cestina');
        $travel = $this->insertCategory('Cestování', 'cestovani');
        $news = $this->insertCategory('Zprávy', 'zpravy');
        $three = $this->insertTag('Tři', 'tri');
        $two = $this->insertTag('Dva', 'dva');
        $one = $this->insertTag('Jeden', 'jeden');
        $draftOnly = $this->insertTag('Jen koncept', 'jen-koncept');

        $this->insertArticle('cestina-stara', $czech, 'published', '2026-09-01 08:00:00', [$three, $two]);
        $this->insertArticle('cestina-tesne-v-okne', $czech, 'published', '2026-09-03 12:00:01', [$three, $one]);
        $this->insertArticle('cestovani-na-hrane-okna', $travel, 'published', '2026-09-03 12:00:00', [$three]);
        $this->insertArticle('cestovani-prave-ted', $travel, 'published', '2026-10-03 12:00:00', [$two]);
        $this->insertArticle('zpravy-koncept', $news, 'draft', null, [$draftOnly, $three]);
        $this->insertArticle('zpravy-archiv', $news, 'archived', '2026-09-20 08:00:00', [$three, $two]);
        $this->insertArticle('zpravy-planovany', $news, 'published', '2026-10-03 12:00:01', [$three, $draftOnly]);
    }

    /**
     * @param list<NamedCount> $counts
     *
     * @return list<array{string, int}>
     */
    private static function pairs(array $counts): array
    {
        return array_map(static fn(NamedCount $count): array => [$count->name, $count->articles], $counts);
    }

    public function test_published_statistics_count_only_public_articles(): void
    {
        $this->statisticsFixture();

        $statistics = $this->repository->publishedStatistics($this->now, 2);

        self::assertSame(4, $statistics->publishedCount);
        self::assertSame(2, $statistics->publishedLast30Days, 'Jen cestina-tesne-v-okne a cestovani-prave-ted.');
        self::assertNotNull($statistics->latestPublishedAt);
        self::assertSame('2026-10-03 12:00:00', $statistics->latestPublishedAt->format('Y-m-d H:i:s'));
        self::assertSame([['Cestování', 2], ['Čeština', 2]], self::pairs($statistics->categories), 'Shoda počtu → česká kolace (C před Č), Zprávy chybí.');
        self::assertSame([['Tři', 3], ['Dva', 2]], self::pairs($statistics->tags));
    }

    public function test_published_statistics_tag_limit_and_draft_only_tag(): void
    {
        $this->statisticsFixture();

        $statistics = $this->repository->publishedStatistics($this->now, 10);

        self::assertSame([['Tři', 3], ['Dva', 2], ['Jeden', 1]], self::pairs($statistics->tags), 'Štítek jen u konceptu chybí.');
        self::assertSame([], $this->repository->publishedStatistics($this->now, 0)->tags);
    }

    public function test_published_statistics_respect_now_argument(): void
    {
        $this->statisticsFixture();

        $later = $this->repository->publishedStatistics(new \DateTimeImmutable('2026-10-03 12:00:01', new \DateTimeZone('Europe/Prague')), 10);

        self::assertSame(5, $later->publishedCount, 'Naplánovaný článek se započítá od svého času (čas z argumentu, ne NOW()).');
        self::assertSame('2026-10-03 12:00:01', $later->latestPublishedAt?->format('Y-m-d H:i:s'));
        self::assertContains(['Zprávy', 1], self::pairs($later->categories));
    }

    public function test_published_statistics_use_at_most_three_statements(): void
    {
        $this->statisticsFixture();

        self::assertLessThanOrEqual(3, StatementCounter::statements($this->pdo, fn() => $this->repository->publishedStatistics($this->now, 10)));
    }

    public function test_published_statistics_of_empty_database(): void
    {
        $this->clearContent();

        $statistics = $this->repository->publishedStatistics($this->now, 10);

        self::assertSame(0, $statistics->publishedCount);
        self::assertSame(0, $statistics->publishedLast30Days);
        self::assertNull($statistics->latestPublishedAt);
        self::assertSame([], $statistics->categories);
        self::assertSame([], $statistics->tags);
    }
}
