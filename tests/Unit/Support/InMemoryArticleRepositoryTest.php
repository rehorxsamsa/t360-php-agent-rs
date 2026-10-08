<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Article\ArticleSummary;
use PHPUnit\Framework\TestCase;

/**
 * Plán 008, AC 23: dvojník `InMemoryArticleRepository::searchPublished` má stejnou sémantiku
 * jako PdoArticleRepository (testy nástrojů příkladu 07 na něm stojí).
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
}
