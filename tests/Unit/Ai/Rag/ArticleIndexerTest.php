<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Rag;

use App\Ai\Embedding\EmbeddingFailed;
use App\Ai\Embedding\FakeEmbeddingClient;
use App\Ai\Rag\ArticleIndexer;
use App\Ai\Rag\IndexReport;
use App\Domain\Article\ArticleStatus;
use App\Tests\Unit\Support\EmbeddingFixtures;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryArticleEmbeddingRepository;
use App\Tests\Unit\Support\ScriptedEmbeddingClient;
use PHPUnit\Framework\TestCase;

/** Plán 009, AC 7–9: přírůstková indexace publikovaných článků (InMemory repozitář, falešný klient). */
final class ArticleIndexerTest extends TestCase
{
    private InMemoryArticleEmbeddingRepository $repository;
    private ScriptedEmbeddingClient $client;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->repository = InMemoryArticleEmbeddingRepository::contract();
        $this->client = ScriptedEmbeddingClient::delegatingTo(new FakeEmbeddingClient());
        $this->clock = FixedClock::at('2026-10-08 12:00:00');
    }

    private function indexer(int $batchSize = 2, int $maxPerRun = 500): ArticleIndexer
    {
        return new ArticleIndexer($this->repository, $this->client, $this->clock, batchSize: $batchSize, maxPerRun: $maxPerRun);
    }

    // ---------------------------------------------------------------- AC 7: první běh

    public function test_first_run_indexes_all_published_articles_in_batches(): void
    {
        $report = $this->indexer(batchSize: 2)->update();

        self::assertInstanceOf(IndexReport::class, $report);
        self::assertSame(3, $report->indexed);
        self::assertSame(0, $report->removed);
        self::assertSame(0, $report->remaining);
        self::assertSame('fake-hash-768', $report->model);
        self::assertSame('fake', $report->provider);
        self::assertGreaterThan(0, $report->tokens);
        self::assertGreaterThanOrEqual(0, $report->durationMs);
        self::assertSame([2, 1], array_map('count', $this->client->documentBatches));
        self::assertSame([], $this->client->queries);
    }

    public function test_saved_records_have_clock_time_and_hash_from_pending(): void
    {
        $expected = [];
        foreach (InMemoryArticleEmbeddingRepository::contract()->pending('fake-hash-768', 100) as $article) {
            $expected[$article->id] = $article->sourceHash;
        }

        $this->indexer()->update();

        self::assertCount(3, $this->repository->saved);
        foreach ($this->repository->saved as $record) {
            self::assertSame('fake-hash-768', $record['model']);
            self::assertEquals($this->clock->now(), $record['indexedAt']);
            self::assertSame($expected[$record['articleId']] ?? null, $record['sourceHash']);
            self::assertSame(768, $record['embedding']->dimensions());
        }
        self::assertSame(
            ['docker-pro-vyvojare', 'nova-studie-o-spanku', 'planovany-clanek'],
            $this->repository->indexedSlugs(),
            'Koncept ani archiv se neindexují, naplánovaný ano.',
        );
    }

    public function test_document_is_title_and_excerpt_with_body(): void
    {
        $this->indexer(batchSize: 16)->update();

        $documents = $this->client->documentBatches[0];
        self::assertCount(3, $documents);
        $study = $documents[0];
        self::assertSame('Nová studie: spánek ovlivňuje paměť víc, než se čekalo', $study->title);
        self::assertSame(
            "Vědci popsali, jak spánek ovlivňuje paměť.\n\nSpánek ovlivňuje paměť víc, než se čekalo.\n\nKdo spí málo, pamatuje si hůř a dělá víc chyb.",
            $study->text,
        );
    }

    public function test_document_text_is_truncated_to_4000_characters(): void
    {
        $this->repository = new InMemoryArticleEmbeddingRepository();
        $this->repository->addArticle('dlouhy', 'Dlouhý článek', str_repeat('Žluťoučký kůň úpěl ďábelské ódy. ', 300), excerpt: 'Perex.');

        $this->indexer()->update();

        $document = $this->client->documentBatches[0][0];
        self::assertSame(4000, ArticleIndexer::DOCUMENT_CHAR_LIMIT);
        self::assertLessThanOrEqual(4000, mb_strlen($document->text));
        self::assertGreaterThan(3900, mb_strlen($document->text));
        self::assertStringStartsWith("Perex.\n\nŽluťoučký kůň", $document->text);
        self::assertTrue(mb_check_encoding($document->text, 'UTF-8'));
    }

    // ---------------------------------------------------------------- AC 8: přírůstkově

    public function test_second_run_indexes_nothing_and_does_not_call_client(): void
    {
        $this->indexer()->update();
        $calls = $this->client->calls();

        $report = $this->indexer()->update();

        self::assertSame(0, $report->indexed);
        self::assertSame(0, $report->removed);
        self::assertSame(0, $report->remaining);
        self::assertSame($calls, $this->client->calls(), 'Bez čekajících článků se klient nevolá.');
    }

    public function test_changed_text_reindexes_only_that_article(): void
    {
        $this->indexer()->update();
        $this->repository->updateArticle('docker-pro-vyvojare', body: 'Docker sjednocuje prostředí i nasazení.');

        $report = $this->indexer()->update();

        self::assertSame(1, $report->indexed);
        $last = end($this->client->documentBatches);
        self::assertNotFalse($last);
        self::assertCount(1, $last);
        self::assertSame('Docker pro vývojáře', $last[0]->title);
    }

    public function test_unpublished_article_vector_is_removed(): void
    {
        $this->indexer()->update();
        $this->repository->updateArticle('docker-pro-vyvojare', status: ArticleStatus::Draft);

        $report = $this->indexer()->update();

        self::assertSame(1, $report->removed);
        self::assertSame(0, $report->indexed);
        $status = $this->indexer()->status();
        self::assertSame(2, $status->published);
        self::assertSame(2, $status->upToDate);
        self::assertNotContains('docker-pro-vyvojare', $this->repository->indexedSlugs());
    }

    public function test_vector_of_other_model_is_removed_and_article_reindexed(): void
    {
        $this->indexer()->update();
        $this->repository->setVector('docker-pro-vyvojare', EmbeddingFixtures::unit(3), model: 'embeddinggemma');

        $report = $this->indexer()->update();

        self::assertSame(1, $report->removed);
        self::assertSame(1, $report->indexed);
        self::assertSame('fake-hash-768', $this->repository->vectors[$this->repository->idOf('docker-pro-vyvojare')]['model']);
    }

    public function test_max_per_run_limits_indexed_articles_and_reports_remaining(): void
    {
        $report = $this->indexer(batchSize: 16, maxPerRun: 2)->update();

        self::assertSame(2, $report->indexed);
        self::assertSame(1, $report->remaining);
        self::assertSame(1, $this->indexer()->status()->pending());
    }

    public function test_status_model_and_provider_come_from_repository_and_client(): void
    {
        $indexer = $this->indexer();

        self::assertSame('fake-hash-768', $indexer->model());
        self::assertSame('fake', $indexer->provider());
        self::assertSame(3, $indexer->status()->published);
        self::assertSame(0, $indexer->status()->upToDate);
    }

    public function test_default_batch_and_run_limits(): void
    {
        $constructor = new \ReflectionMethod(ArticleIndexer::class, '__construct');
        $defaults = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        self::assertSame(['batchSize' => 16, 'maxPerRun' => 500], $defaults);
    }

    // ---------------------------------------------------------------- AC 9: chyby

    public function test_failure_in_second_batch_propagates_and_keeps_first_batch(): void
    {
        $failure = EmbeddingFixtures::failed();
        $this->client = new ScriptedEmbeddingClient();
        $this->client->push(
            EmbeddingFixtures::result([EmbeddingFixtures::unit(1), EmbeddingFixtures::unit(2)], tokens: 10),
            $failure,
        );

        try {
            $this->indexer(batchSize: 2)->update();
            self::fail('Očekávána výjimka EmbeddingFailed.');
        } catch (EmbeddingFailed $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertCount(2, $this->repository->saved);
        self::assertSame(['docker-pro-vyvojare', 'nova-studie-o-spanku'], $this->repository->indexedSlugs());
    }

    public function test_vector_with_other_dimension_fails_and_saves_nothing_from_batch(): void
    {
        $this->client = new ScriptedEmbeddingClient();
        $this->client->push(EmbeddingFixtures::result([
            EmbeddingFixtures::unit(1, 1024),
            EmbeddingFixtures::unit(2, 1024),
        ]));

        try {
            $this->indexer(batchSize: 2)->update();
            self::fail('Očekávána výjimka EmbeddingFailed.');
        } catch (EmbeddingFailed $exception) {
            self::assertSame(
                'Model embeddingů vrací 1024 dimenzí, tabulka article_embeddings čeká 768 – jiný model vyžaduje novou migraci.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->repository->saved);
        self::assertSame([], $this->repository->vectors);
    }
}
