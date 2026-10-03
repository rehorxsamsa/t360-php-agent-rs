<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Article\ArticleDetail;
use App\Domain\Article\ArticleRepository;
use App\Domain\Article\ArticleSummary;

/**
 * Repozitář článků v paměti. Vrací souhrny v pořadí, v jakém byly nastaveny (už jako "publikované"),
 * a zaznamenává argumenty a počet volání.
 */
final class InMemoryArticleRepository implements ArticleRepository
{
    /** @var list<ArticleSummary> */
    public array $summaries = [];
    /** @var array<string, ArticleDetail> detaily publikovaných článků podle slugu */
    public array $details = [];

    public int $latestCalls = 0;
    public int $countCalls = 0;
    public int $findCalls = 0;
    public ?int $lastLimit = null;
    public ?int $lastOffset = null;
    public ?\DateTimeImmutable $lastNow = null;
    public ?string $lastSlug = null;

    public function latestPublished(\DateTimeImmutable $now, int $limit, int $offset): array
    {
        ++$this->latestCalls;
        $this->lastNow = $now;
        $this->lastLimit = $limit;
        $this->lastOffset = $offset;

        return array_slice($this->summaries, $offset, $limit);
    }

    public function countPublished(\DateTimeImmutable $now): int
    {
        ++$this->countCalls;
        $this->lastNow = $now;

        return count($this->summaries);
    }

    public function findPublishedBySlug(string $slug, \DateTimeImmutable $now): ?ArticleDetail
    {
        ++$this->findCalls;
        $this->lastSlug = $slug;
        $this->lastNow = $now;

        return $this->details[$slug] ?? null;
    }

    /** Naplní $count souhrnů, od nejnovějšího (2026-09-<count>) po nejstarší (2026-09-01). */
    public static function withPublishedCount(int $count): self
    {
        $repository = new self();
        for ($day = $count; $day >= 1; --$day) {
            $repository->summaries[] = new ArticleSummary(
                title: sprintf('Článek %d', $day),
                slug: sprintf('clanek-%d', $day),
                excerpt: sprintf('Perex článku %d.', $day),
                publishedAt: new \DateTimeImmutable(sprintf('2026-09-%02d 08:00:00', $day), new \DateTimeZone('Europe/Prague')),
                categoryName: 'Technologie',
            );
        }

        return $repository;
    }
}
