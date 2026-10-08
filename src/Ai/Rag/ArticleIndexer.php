<?php

declare(strict_types=1);

namespace App\Ai\Rag;

use App\Ai\Embedding\EmbeddingClient;
use App\Ai\Embedding\EmbeddingDocument;
use App\Ai\Embedding\EmbeddingFailed;
use App\Domain\Article\ArticleEmbeddingRepository;
use App\Domain\Article\EmbeddingIndexStatus;
use App\Domain\Article\IndexableArticle;
use App\Domain\Time\Clock;

/**
 * Udržuje index vektorů publikovaných článků (ADR-0009). Jeden běh `update()`:
 * 1) smaže vektory nepublikovaných článků a jiného modelu, 2) vybere články bez aktuálního vektoru
 * (chybí nebo se změnil obsah, rozhoduje hash počítaný v SQL), 3) po dávkách je pošle klientovi embeddingů
 * a uloží vektory. Dávka se ukládá až po kontrole dimenzí, takže špatný model nezanechá napůl zapsaná data;
 * chyba v pozdější dávce nechá dřívější dávky uložené (další běh naváže).
 */
final readonly class ArticleIndexer
{
    /** Z článku se do vektoru bere nejvýše tolik znaků (perex + text); dál by to model stejně oříznul. */
    public const int DOCUMENT_CHAR_LIMIT = 4000;

    /**
     * @param int $batchSize kolik dokumentů se pošle klientovi najednou (méně než 1 se bere jako 1)
     * @param int $maxPerRun kolik čekajících článků se zpracuje v jednom běhu (méně než 1 se bere jako 1)
     */
    public function __construct(
        private ArticleEmbeddingRepository $repository,
        private EmbeddingClient $client,
        private Clock $clock,
        private int $batchSize = 16,
        private int $maxPerRun = 500,
    ) {}

    /** @throws EmbeddingFailed */
    public function update(): IndexReport
    {
        $startedAt = hrtime(true);
        $model = $this->client->model();

        $removed = $this->repository->removeStale($model);
        $indexed = 0;
        $tokens = 0;

        foreach (array_chunk($this->repository->pending($model, max(1, $this->maxPerRun)), max(1, $this->batchSize)) as $batch) {
            $result = $this->client->embedDocuments(array_map($this->document(...), $batch));
            if (count($result->vectors) !== count($batch)) {
                throw new EmbeddingFailed('Služba embeddingů vrátila jiný počet vektorů, než kolik dostala dokumentů.');
            }

            foreach ($result->vectors as $vector) {
                if ($vector->dimensions() !== ArticleEmbeddingRepository::DIMENSIONS) {
                    throw EmbeddingFailed::dimensionMismatch($vector->dimensions(), ArticleEmbeddingRepository::DIMENSIONS);
                }
            }

            $now = $this->clock->now();
            foreach ($batch as $position => $article) {
                $this->repository->save($article, $model, $result->vectors[$position], $now);
            }

            $indexed += count($batch);
            $tokens += $result->tokens;
        }

        return new IndexReport(
            $indexed,
            $removed,
            $this->repository->status($model)->pending(),
            $tokens,
            intdiv(hrtime(true) - $startedAt, 1_000_000),
            $model,
            $this->client->provider(),
        );
    }

    public function status(): EmbeddingIndexStatus
    {
        return $this->repository->status($this->client->model());
    }

    public function provider(): string
    {
        return $this->client->provider();
    }

    public function model(): string
    {
        return $this->client->model();
    }

    private function document(IndexableArticle $article): EmbeddingDocument
    {
        return new EmbeddingDocument(
            $article->title,
            mb_substr($article->excerpt . "\n\n" . $article->body, 0, self::DOCUMENT_CHAR_LIMIT),
        );
    }
}
