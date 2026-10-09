<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Article\ArticleStatus;
use App\Domain\Article\ArticleSummary;
use App\Domain\Article\NamedCount;
use PHPUnit\Framework\TestCase;

/**
 * Plán 008, AC 23: dvojník `InMemoryArticleRepository::searchPublished` má stejnou sémantiku
 * jako PdoArticleRepository (testy nástrojů příkladu 07 na něm stojí).
 * Plán 011, §3: `publishedStatistics()` dvojníka má sémantiku AC 2 (testy nástroje `statistiky` na něm stojí).
 */
final class InMemoryArticleRepositoryTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('Europe/Prague'));
    }

    /**
     * @param list<ArticleSummary> $summaries
     *
     * @return list<string>
     */
    private static function slugs(array $summaries): array
    {
        return array_map(static fn(ArticleSummary $summary): string => $summary->slug, $summaries);
    }

    public function test_search_returns_only_public_articles_case_insensitively(): void
    {
        $repository = InMemoryArticleRepository::newsroomContract();

        self::assertSame(['docker-pro-vyvojare'], self::slugs($repository->searchPublished('dOcKeR', $this->now, 5)));
    }

    public function test_search_looks_into_title_excerpt_and_body_newest_first(): void
    {
        $repository = new InMemoryArticleRepository()
            ->addArticle('v-titulku', 'Hledané slovo v titulku', 'x', publishedAt: '2026-09-01 08:00:00')
            ->addArticle('v-perexu', 'Titulek', 'x', publishedAt: '2026-09-03 08:00:00', excerpt: 'Hledané v perexu')
            ->addArticle('v-textu', 'Titulek', 'Text s HLEDANÉ', publishedAt: '2026-09-02 08:00:00')
            ->addArticle('stejny-cas', 'Titulek hledané', 'x', publishedAt: '2026-09-03 08:00:00');

        self::assertSame(
            ['stejny-cas', 'v-perexu', 'v-textu', 'v-titulku'],
            self::slugs($repository->searchPublished('  hledané ', $this->now, 5)),
        );
        self::assertSame(['stejny-cas', 'v-perexu'], self::slugs($repository->searchPublished('hledané', $this->now, 2)));
    }

    public function test_wildcards_are_literal_and_empty_query_returns_nothing(): void
    {
        $repository = new InMemoryArticleRepository()
            ->addArticle('sleva', 'Sleva 50% na vše', 'x')
            ->addArticle('podtrzitko', 'Název snake_case', 'x');

        self::assertSame(['sleva'], self::slugs($repository->searchPublished('%', $this->now, 5)));
        self::assertSame(['podtrzitko'], self::slugs($repository->searchPublished('_', $this->now, 5)));
        self::assertSame([], $repository->searchPublished('   ', $this->now, 5));
    }

    /**
     * @param list<NamedCount> $counts
     *
     * @return array<string, int>
     */
    private static function counts(array $counts): array
    {
        $result = [];
        foreach ($counts as $count) {
            $result[$count->name] = $count->articles;
        }

        return $result;
    }

    public function test_statistics_count_only_public_articles_like_pdo_repository(): void
    {
        $repository = new InMemoryArticleRepository()
            ->addArticle('a1', 'A1', 'x', publishedAt: '2026-09-01 08:00:00', categoryName: 'Čeština', tagNames: ['Jazyk', 'Tři'])
            ->addArticle('a2', 'A2', 'x', publishedAt: '2026-09-20 08:00:00', categoryName: 'Čeština', tagNames: ['Tři', 'Jazyk'])
            ->addArticle('a3', 'A3', 'x', publishedAt: '2026-09-04 12:00:01', categoryName: 'Cestování', tagNames: ['Tři', 'Jeden'])
            ->addArticle('a4', 'A4', 'x', publishedAt: '2026-10-04 11:00:00', categoryName: 'Cestování')
            ->addArticle('koncept', 'K', 'x', status: ArticleStatus::Draft, publishedAt: null, categoryName: 'Zprávy', tagNames: ['Jen koncept'])
            ->addArticle('archiv', 'Ar', 'x', status: ArticleStatus::Archived, publishedAt: '2026-09-15 08:00:00', categoryName: 'Zprávy')
            ->addArticle('plan', 'P', 'x', publishedAt: '2099-01-01 08:00:00', categoryName: 'Zprávy', tagNames: ['Jen koncept']);

        $statistics = $repository->publishedStatistics($this->now, 2);

        self::assertSame(4, $statistics->publishedCount);
        // (now − 30 dní, now] = (2026-09-04 12:00, 2026-10-04 12:00]: a2, a3 (o sekundu později), a4.
        self::assertSame(3, $statistics->publishedLast30Days);
        self::assertSame('2026-10-04 11:00', $statistics->latestPublishedAt?->format('Y-m-d H:i'));
        self::assertSame(['Cestování' => 2, 'Čeština' => 2], self::counts($statistics->categories));
        self::assertSame(['Cestování', 'Čeština'], array_map(static fn(NamedCount $c): string => $c->name, $statistics->categories));
        self::assertSame(['Tři' => 3, 'Jazyk' => 2], self::counts($statistics->tags));
        self::assertSame(1, $repository->statisticsCalls);
        self::assertSame(2, $repository->lastTagLimit);
        self::assertSame(1, $repository->totalCalls());
    }

    public function test_statistics_of_empty_repository(): void
    {
        $statistics = new InMemoryArticleRepository()->publishedStatistics($this->now, 10);

        self::assertSame(0, $statistics->publishedCount);
        self::assertSame(0, $statistics->publishedLast30Days);
        self::assertNull($statistics->latestPublishedAt);
        self::assertSame([], $statistics->categories);
        self::assertSame([], $statistics->tags);
    }
}
