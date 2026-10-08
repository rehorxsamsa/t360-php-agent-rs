<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ai;

use App\Ai\Embedding\EmbeddingResult;
use App\Domain\Ai\Embedding;
use App\Domain\Article\EmbeddingIndexStatus;
use App\Domain\Article\SimilarArticle;
use App\Tests\Unit\Support\EmbeddingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 009, §2: hodnotové objekty embeddingů (Embedding, EmbeddingResult, EmbeddingIndexStatus, SimilarArticle). */
final class EmbeddingTest extends TestCase
{
    public function test_embedding_keeps_values_and_reports_dimensions(): void
    {
        $embedding = new Embedding([0.5, -0.25, 1.0]);

        self::assertSame([0.5, -0.25, 1.0], $embedding->values);
        self::assertSame(3, $embedding->dimensions());
        self::assertSame(768, EmbeddingFixtures::unit()->dimensions());
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [[]];
        yield 'NAN' => [[0.1, NAN]];
        yield 'INF' => [[INF, 0.2]];
        yield '-INF' => [[-INF]];
        yield 'string' => [[0.1, '0.2']];
    }

    /** @param array<mixed> $values */
    #[DataProvider('invalidValues')]
    public function test_embedding_rejects_empty_or_non_finite_values(array $values): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore argument.type (záměrně neplatný vstup) */
        new Embedding($values);
    }

    public function test_embedding_result_first_returns_first_vector(): void
    {
        $first = EmbeddingFixtures::unit(0);
        $result = EmbeddingFixtures::result([$first, EmbeddingFixtures::unit(1)], tokens: 9, durationMs: 4);

        self::assertSame($first, $result->first());
        self::assertSame(9, $result->tokens);
        self::assertSame(4, $result->durationMs);
        self::assertCount(2, $result->vectors);
    }

    public function test_empty_embedding_result_first_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);

        new EmbeddingResult(vectors: [], tokens: 0, durationMs: 0)->first();
    }

    public function test_index_status_counts_pending_articles(): void
    {
        self::assertSame(1, new EmbeddingIndexStatus(published: 3, upToDate: 2)->pending());
        self::assertSame(0, new EmbeddingIndexStatus(published: 2, upToDate: 2)->pending());
        self::assertSame(3, new EmbeddingIndexStatus(published: 3, upToDate: 0)->pending());
    }

    public function test_similar_article_url_points_to_public_detail(): void
    {
        $article = new SimilarArticle(
            slug: 'nova-studie-o-spanku',
            title: 'T',
            excerpt: '',
            body: 'B',
            categoryName: 'Věda',
            publishedAt: new \DateTimeImmutable('2026-09-20 08:00:00', new \DateTimeZone('Europe/Prague')),
            distance: 0.25,
        );

        self::assertSame('/clanek/nova-studie-o-spanku', $article->url());
        self::assertSame(0.25, $article->distance);
    }
}
