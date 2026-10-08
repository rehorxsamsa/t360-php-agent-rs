<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Ai\Embedding;
use App\Domain\Article\ArticleEmbeddingRepository;
use App\Domain\Article\IndexableArticle;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoArticleEmbeddingRepository;
use App\Tests\Integration\StatementCounter;
use App\Tests\Integration\TestDatabase;
use App\Tests\Unit\Support\EmbeddingFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Plán 009, AC 19–22: vektory článků nad redakce_test (MariaDB VECTOR, kosinová vzdálenost). Data podle kontraktu
 * plánu 009 (publikované, koncept, archiv, naplánovaný do budoucna), čas 2026-10-08 12:00 Europe/Prague.
 */
final class PdoArticleEmbeddingRepositoryTest extends TestCase
{
    private const string MODEL = 'fake-hash-768';

    private \PDO $pdo;
    private PdoArticleEmbeddingRepository $repository;
    private \DateTimeImmutable $now;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();
        $this->pdo->exec("INSERT INTO categories (name, slug) VALUES ('Věda', 'veda')");
        $this->categoryId = (int) $this->pdo->lastInsertId();

        $this->repository = new PdoArticleEmbeddingRepository($this->pdo);
        $this->now = new \DateTimeImmutable('2026-10-08 12:00:00', new \DateTimeZone('Europe/Prague'));
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    private function insertArticle(
        string $slug,
        string $status = 'published',
        ?string $publishedAt = '2026-09-20 08:00:00',
        string $title = 'Titulek',
        string $excerpt = '',
        string $body = 'Text článku.',
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO articles (category_id, title, slug, excerpt, body, status, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([$this->categoryId, $title, $slug, $excerpt, $body, $status, $publishedAt]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Kontrakt plánu 009.
     *
     * @return array<string, int> id podle slugu
     */
    private function insertContract(): array
    {
        return [
            'nova-studie-o-spanku' => $this->insertArticle(
                'nova-studie-o-spanku',
                title: 'Nová studie: spánek ovlivňuje paměť víc, než se čekalo',
                excerpt: 'Vědci popsali, jak spánek ovlivňuje paměť.',
                body: "Spánek ovlivňuje paměť víc, než se čekalo.\n\nKdo spí málo, pamatuje si hůř.",
            ),
            'docker-pro-vyvojare' => $this->insertArticle('docker-pro-vyvojare', publishedAt: '2026-09-15 08:00:00', title: 'Docker pro vývojáře', body: 'Docker sjednocuje prostředí.'),
            'druhy-koncept' => $this->insertArticle('druhy-koncept', 'draft', null, 'Druhý koncept', body: 'spánek ovlivňuje paměť'),
            'archivni-clanek' => $this->insertArticle('archivni-clanek', 'archived', '2026-01-10 08:00:00', 'Archivní článek', body: 'spánek ovlivňuje paměť'),
            'planovany-clanek' => $this->insertArticle('planovany-clanek', 'published', '2099-01-01 08:00:00', 'Plánovaný článek', body: 'spánek ovlivňuje paměť'),
        ];
    }

    /** Uloží vektor přímo SQL (i pro nepublikované články, které `pending` nevrací). */
    private function insertVector(int $articleId, Embedding $embedding, string $model = self::MODEL, string $hash = ''): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO article_embeddings (article_id, model, source_hash, embedding, indexed_at) VALUES (?, ?, ?, VEC_FromText(?), ?)',
        );
        $statement->execute([
            $articleId,
            $model,
            $hash !== '' ? $hash : str_repeat('0', 64),
            json_encode($embedding->values, JSON_THROW_ON_ERROR),
            '2026-10-01 08:00:00.000000',
        ]);
    }

    /** @return array<int, IndexableArticle> podle id */
    private function pendingById(int $limit = 100): array
    {
        $result = [];
        foreach ($this->repository->pending(self::MODEL, $limit) as $article) {
            $result[$article->id] = $article;
        }

        return $result;
    }

    private function saveAllPending(): void
    {
        foreach ($this->repository->pending(self::MODEL, 100) as $index => $article) {
            $this->repository->save($article, self::MODEL, EmbeddingFixtures::unit($index + 1), $this->now);
        }
    }

    private function embeddingCount(): int
    {
        return TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM article_embeddings');
    }

    public function test_implements_domain_interface(): void
    {
        self::assertInstanceOf(ArticleEmbeddingRepository::class, $this->repository);
    }

    // ---------------------------------------------------------------- AC 19: pending + save

    public function test_pending_returns_only_published_articles_ordered_by_id_with_hash(): void
    {
        $ids = $this->insertContract();

        $pending = $this->repository->pending(self::MODEL, 100);

        self::assertSame(
            [$ids['nova-studie-o-spanku'], $ids['docker-pro-vyvojare'], $ids['planovany-clanek']],
            array_map(static fn(IndexableArticle $article): int => $article->id, $pending),
        );
        self::assertSame('Nová studie: spánek ovlivňuje paměť víc, než se čekalo', $pending[0]->title);
        self::assertSame('Vědci popsali, jak spánek ovlivňuje paměť.', $pending[0]->excerpt);
        self::assertSame("Spánek ovlivňuje paměť víc, než se čekalo.\n\nKdo spí málo, pamatuje si hůř.", $pending[0]->body);
        foreach ($pending as $article) {
            self::assertMatchesRegularExpression('~^[0-9a-f]{64}$~', $article->sourceHash);
        }
        self::assertNotSame($pending[0]->sourceHash, $pending[1]->sourceHash);
    }

    public function test_pending_respects_limit(): void
    {
        $ids = $this->insertContract();

        $pending = $this->repository->pending(self::MODEL, 2);

        self::assertSame([$ids['nova-studie-o-spanku'], $ids['docker-pro-vyvojare']], array_map(static fn(IndexableArticle $a): int => $a->id, $pending));
    }

    public function test_saved_articles_are_no_longer_pending_until_content_changes(): void
    {
        $ids = $this->insertContract();

        $this->saveAllPending();

        self::assertSame([], $this->repository->pending(self::MODEL, 100));
        self::assertSame(3, $this->embeddingCount());

        $this->pdo->exec("UPDATE articles SET body = 'Docker sjednocuje prostředí i nasazení.' WHERE id = " . $ids['docker-pro-vyvojare']);
        self::assertSame([$ids['docker-pro-vyvojare']], array_keys($this->pendingById()));

        $this->pdo->exec("UPDATE articles SET excerpt = 'Nový perex.' WHERE id = " . $ids['nova-studie-o-spanku']);
        $this->pdo->exec("UPDATE articles SET title = 'Plánovaný článek 2' WHERE id = " . $ids['planovany-clanek']);
        self::assertSame(
            [$ids['nova-studie-o-spanku'], $ids['docker-pro-vyvojare'], $ids['planovany-clanek']],
            array_keys($this->pendingById()),
        );
    }

    public function test_vector_of_other_model_is_pending_for_current_model(): void
    {
        $ids = $this->insertContract();
        $this->saveAllPending();
        $this->pdo->exec("UPDATE article_embeddings SET model = 'embeddinggemma' WHERE article_id = " . $ids['docker-pro-vyvojare']);

        self::assertSame([$ids['docker-pro-vyvojare']], array_keys($this->pendingById()));
    }

    public function test_hash_does_not_depend_on_unrelated_columns(): void
    {
        $ids = $this->insertContract();
        $this->saveAllPending();

        $this->pdo->exec("UPDATE articles SET slug = 'docker-novy', published_at = '2026-09-16 08:00:00' WHERE id = " . $ids['docker-pro-vyvojare']);

        self::assertSame([], $this->repository->pending(self::MODEL, 100));
    }

    public function test_hash_distinguishes_field_boundaries(): void
    {
        $first = $this->insertArticle('a', title: 'ab', excerpt: 'c', body: 'd');
        $second = $this->insertArticle('b', title: 'a', excerpt: 'bc', body: 'd');

        $pending = $this->pendingById();

        self::assertNotSame($pending[$first]->sourceHash, $pending[$second]->sourceHash);
    }

    public function test_save_twice_overwrites_single_row(): void
    {
        $id = $this->insertArticle('clanek');
        $article = $this->pendingById()[$id];

        $this->repository->save($article, self::MODEL, EmbeddingFixtures::unit(1), $this->now);
        $this->repository->save($article, 'embeddinggemma', EmbeddingFixtures::unit(2), $this->now->modify('+1 hour'));

        self::assertSame(1, $this->embeddingCount());
        self::assertSame(
            [['model' => 'embeddinggemma', 'indexed_at' => '2026-10-08 13:00:00.000000']],
            TestDatabase::rows($this->pdo, 'SELECT model, indexed_at FROM article_embeddings'),
        );
    }

    public function test_save_rejects_vector_of_wrong_dimension_without_query(): void
    {
        $id = $this->insertArticle('clanek');
        $article = $this->pendingById()[$id];

        $statements = StatementCounter::statements($this->pdo, function () use ($article): void {
            try {
                $this->repository->save($article, self::MODEL, EmbeddingFixtures::unit(1, 1024), $this->now);
                self::fail('Očekávána výjimka InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        });

        self::assertSame(0, $statements);
        self::assertSame(0, $this->embeddingCount());
    }

    public function test_each_method_uses_single_statement(): void
    {
        $ids = $this->insertContract();
        $this->insertVector($ids['druhy-koncept'], EmbeddingFixtures::unit(5));
        $article = null;

        self::assertSame(1, StatementCounter::statements($this->pdo, function () use (&$article): void {
            $article = $this->repository->pending(self::MODEL, 100)[0];
        }));
        self::assertInstanceOf(IndexableArticle::class, $article);
        self::assertSame(1, StatementCounter::statements($this->pdo, fn() => $this->repository->save($article, self::MODEL, EmbeddingFixtures::unit(1), $this->now)));
        self::assertSame(1, StatementCounter::statements($this->pdo, fn() => $this->repository->removeStale(self::MODEL)));
        self::assertSame(1, StatementCounter::statements($this->pdo, fn() => $this->repository->status(self::MODEL)));
        self::assertSame(1, StatementCounter::statements($this->pdo, fn() => $this->repository->nearestPublished(EmbeddingFixtures::unit(1), self::MODEL, $this->now, 3)));
    }

    // ---------------------------------------------------------------- AC 20: removeStale + status

    public function test_remove_stale_deletes_vectors_of_unpublished_and_other_model(): void
    {
        $ids = $this->insertContract();
        $this->saveAllPending();
        $this->insertVector($ids['druhy-koncept'], EmbeddingFixtures::unit(5));
        $this->insertVector($ids['archivni-clanek'], EmbeddingFixtures::unit(6));
        $this->pdo->exec("UPDATE article_embeddings SET model = 'embeddinggemma' WHERE article_id = " . $ids['docker-pro-vyvojare']);

        $removed = $this->repository->removeStale(self::MODEL);

        self::assertSame(3, $removed);
        self::assertSame(
            [(string) $ids['nova-studie-o-spanku'], (string) $ids['planovany-clanek']],
            TestDatabase::column($this->pdo, 'SELECT article_id FROM article_embeddings ORDER BY article_id'),
        );
        self::assertSame(0, $this->repository->removeStale(self::MODEL));
    }

    public function test_status_counts_published_and_up_to_date_vectors(): void
    {
        $ids = $this->insertContract();

        $empty = $this->repository->status(self::MODEL);
        self::assertSame(3, $empty->published);
        self::assertSame(0, $empty->upToDate);

        $this->saveAllPending();
        $this->insertVector($ids['druhy-koncept'], EmbeddingFixtures::unit(5));
        self::assertSame(3, $this->repository->status(self::MODEL)->upToDate);

        $this->pdo->exec("UPDATE articles SET body = 'Změna.' WHERE id = " . $ids['docker-pro-vyvojare']);
        $this->pdo->exec("UPDATE article_embeddings SET model = 'embeddinggemma' WHERE article_id = " . $ids['planovany-clanek']);
        $status = $this->repository->status(self::MODEL);
        self::assertSame(3, $status->published);
        self::assertSame(1, $status->upToDate);
        self::assertSame(2, $status->pending());
    }

    // ---------------------------------------------------------------- AC 21: uložený vektor

    public function test_saved_vector_and_time_are_stored_exactly(): void
    {
        $id = $this->insertArticle('clanek');
        $values = [];
        for ($i = 0; $i < 768; $i++) {
            $values[] = round(sin($i + 1) / 10, 9);
        }
        $indexedAt = new \DateTimeImmutable('2026-10-08 12:34:56.123456', new \DateTimeZone('Europe/Prague'));

        $article = $this->pendingById()[$id];

        $this->repository->save($article, self::MODEL, new Embedding($values), $indexedAt);

        $row = TestDatabase::rows($this->pdo, 'SELECT VEC_ToText(embedding) AS v, indexed_at, model, source_hash FROM article_embeddings')[0];
        $stored = json_decode($row['v'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        self::assertCount(768, $stored);
        foreach ($values as $i => $value) {
            $component = $stored[$i] ?? null;
            self::assertTrue(is_int($component) || is_float($component), 'složka ' . $i . ' není číslo');
            self::assertEqualsWithDelta($value, (float) $component, 1e-6, 'složka ' . $i);
        }
        self::assertSame('2026-10-08 12:34:56.123456', $row['indexed_at']);
        self::assertSame(self::MODEL, $row['model']);
        self::assertSame($article->sourceHash, $row['source_hash']);
    }

    // ---------------------------------------------------------------- AC 22: nearestPublished

    public function test_nearest_published_filters_orders_and_limits(): void
    {
        $ids = $this->insertContract();
        $tie = $this->insertArticle('shoda', publishedAt: '2026-09-01 08:00:00', title: 'Shoda');
        $orthogonal = $this->insertArticle('kolmy', publishedAt: '2026-09-02 08:00:00', title: 'Kolmý');
        $otherModel = $this->insertArticle('jiny-model', publishedAt: '2026-09-03 08:00:00', title: 'Jiný model');
        $diagonal = new Embedding(array_replace(array_fill(0, 768, 0.0), [0 => M_SQRT1_2, 1 => M_SQRT1_2]));

        $this->insertVector($ids['nova-studie-o-spanku'], EmbeddingFixtures::unit(0));
        $this->insertVector($ids['docker-pro-vyvojare'], $diagonal);
        $this->insertVector($tie, EmbeddingFixtures::unit(0));
        $this->insertVector($orthogonal, EmbeddingFixtures::unit(1));
        $this->insertVector($otherModel, EmbeddingFixtures::unit(0), 'embeddinggemma');
        foreach (['druhy-koncept', 'archivni-clanek', 'planovany-clanek'] as $hidden) {
            $this->insertVector($ids[$hidden], EmbeddingFixtures::unit(0));
        }

        $nearest = $this->repository->nearestPublished(EmbeddingFixtures::unit(0), self::MODEL, $this->now, 3);
        $all = $this->repository->nearestPublished(EmbeddingFixtures::unit(0), self::MODEL, $this->now, 10);

        self::assertSame(['nova-studie-o-spanku', 'shoda', 'docker-pro-vyvojare'], array_map(static fn($a): string => $a->slug, $nearest));
        self::assertSame(['nova-studie-o-spanku', 'shoda', 'docker-pro-vyvojare', 'kolmy'], array_map(static fn($a): string => $a->slug, $all));
        self::assertEqualsWithDelta(0.0, $all[0]->distance, 1e-6);
        self::assertEqualsWithDelta(0.0, $all[1]->distance, 1e-6);
        self::assertEqualsWithDelta(1 - M_SQRT1_2, $all[2]->distance, 1e-5);
        self::assertEqualsWithDelta(1.0, $all[3]->distance, 1e-6);
    }

    public function test_nearest_published_carries_current_article_data(): void
    {
        $ids = $this->insertContract();
        $this->insertVector($ids['nova-studie-o-spanku'], EmbeddingFixtures::unit(0));
        $this->pdo->exec("UPDATE articles SET title = 'Aktuální titulek' WHERE id = " . $ids['nova-studie-o-spanku']);

        $article = $this->repository->nearestPublished(EmbeddingFixtures::unit(0), self::MODEL, $this->now, 3)[0] ?? null;

        self::assertNotNull($article);
        self::assertSame('nova-studie-o-spanku', $article->slug);
        self::assertSame('Aktuální titulek', $article->title);
        self::assertSame('Vědci popsali, jak spánek ovlivňuje paměť.', $article->excerpt);
        self::assertSame("Spánek ovlivňuje paměť víc, než se čekalo.\n\nKdo spí málo, pamatuje si hůř.", $article->body);
        self::assertSame('Věda', $article->categoryName);
        self::assertSame('2026-09-20 08:00:00', $article->publishedAt->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Prague', $article->publishedAt->getTimezone()->getName());
        self::assertSame('/clanek/nova-studie-o-spanku', $article->url());
    }

    public function test_scheduled_article_appears_after_its_time(): void
    {
        $ids = $this->insertContract();
        $this->insertVector($ids['planovany-clanek'], EmbeddingFixtures::unit(0));

        self::assertSame([], $this->repository->nearestPublished(EmbeddingFixtures::unit(0), self::MODEL, $this->now, 3));
        $later = new \DateTimeImmutable('2099-01-02 00:00:00', new \DateTimeZone('Europe/Prague'));
        self::assertSame(
            ['planovany-clanek'],
            array_map(static fn($a): string => $a->slug, $this->repository->nearestPublished(EmbeddingFixtures::unit(0), self::MODEL, $later, 3)),
        );
    }

    public function test_empty_index_returns_no_articles(): void
    {
        $this->insertContract();

        self::assertSame([], $this->repository->nearestPublished(EmbeddingFixtures::unit(0), self::MODEL, $this->now, 3));
    }
}
