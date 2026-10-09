<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Article\ArticleDetail;
use App\Domain\Article\ArticleRepository;
use App\Domain\Article\PublishedStatistics;

/**
 * Plán 011, AC 6: repozitář, který při každém volání vyhodí výjimku infrastruktury s citlivými detaily
 * (SQLSTATE a jméno DB účtu). Test ověří, že se detail nedostane do výsledku nástroje.
 */
final class ThrowingArticleRepository implements ArticleRepository
{
    public const string MESSAGE = 'SQLSTATE[HY000] [1045] Access denied for user redakce_app';

    public int $calls = 0;

    public function latestPublished(\DateTimeImmutable $now, int $limit, int $offset): array
    {
        throw $this->failure();
    }

    public function countPublished(\DateTimeImmutable $now): int
    {
        throw $this->failure();
    }

    public function findPublishedBySlug(string $slug, \DateTimeImmutable $now): ?ArticleDetail
    {
        throw $this->failure();
    }

    public function searchPublished(string $query, \DateTimeImmutable $now, int $limit): array
    {
        throw $this->failure();
    }

    public function publishedStatistics(\DateTimeImmutable $now, int $tagLimit): PublishedStatistics
    {
        throw $this->failure();
    }

    private function failure(): \RuntimeException
    {
        ++$this->calls;

        return new \RuntimeException(self::MESSAGE);
    }
}
