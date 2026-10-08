<?php

declare(strict_types=1);

namespace App\Domain\Article;

use App\Domain\Ai\Embedding;

interface ArticleEmbeddingRepository
{
    /** Dimenze sloupce `article_embeddings.embedding`; jiný model vyžaduje novou migraci. */
    public const int DIMENSIONS = 768;

    /**
     * Články se stavem `published` (i naplánované), které nemají aktuální vektor daného modelu
     * (chybí, jiný model nebo se změnil obsah), seřazené podle `id`.
     *
     * @return list<IndexableArticle>
     */
    public function pending(string $model, int $limit): array;

    /**
     * Uloží (upsert) vektor článku.
     *
     * @throws \InvalidArgumentException vektor nemá {@see self::DIMENSIONS} složek (bez dotazu do DB)
     */
    public function save(IndexableArticle $article, string $model, Embedding $embedding, \DateTimeImmutable $indexedAt): void;

    /** Smaže vektory nepublikovaných článků a vektory jiného modelu; vrací počet smazaných. */
    public function removeStale(string $model): int;

    public function status(string $model): EmbeddingIndexStatus;

    /**
     * Nejbližší veřejně čitelné články (`published`, `published_at <= $now`) daného modelu,
     * vzestupně podle kosinové vzdálenosti (shoda → `id`), nejvýše `$limit`.
     *
     * @return list<SimilarArticle>
     */
    public function nearestPublished(Embedding $query, string $model, \DateTimeImmutable $now, int $limit): array;
}
