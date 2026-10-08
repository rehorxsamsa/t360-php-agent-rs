<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Ai\Embedding;
use App\Domain\Article\ArticleEmbeddingRepository;
use App\Domain\Article\ArticleStatus;
use App\Domain\Article\EmbeddingIndexStatus;
use App\Domain\Article\IndexableArticle;
use App\Domain\Article\SimilarArticle;

final readonly class PdoArticleEmbeddingRepository implements ArticleEmbeddingRepository
{
    /** Stejná podmínka „veřejně čitelný“ jako v {@see PdoArticleRepository}. */
    private const string PUBLISHED_CONDITION = 'a.status = :status AND a.published_at IS NOT NULL AND a.published_at <= :now';

    /**
     * Hash obsahu článku počítá jen SQL. Oddělovač přes CHAR(), ne řetězcový literál
     * se zpětným lomítkem (funguje i v režimu NO_BACKSLASH_ESCAPES).
     */
    private const string SOURCE_HASH = 'SHA2(CONCAT_WS(CHAR(31 USING utf8mb4), a.title, a.excerpt, a.body), 256)';

    /**
     * Kolik kandidátů vrátí vektorový index. WHERE nad vektorovým indexem se aplikuje až na
     * vrácené řádky, proto se publikovanost filtruje ve vnějším dotazu a kandidátů je víc než limit.
     */
    private const int CANDIDATES = 20;

    public function __construct(private \PDO $pdo) {}

    public function pending(string $model, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.title, a.excerpt, a.body, ' . self::SOURCE_HASH . ' AS source_hash'
            . ' FROM articles a'
            . ' LEFT JOIN article_embeddings e ON e.article_id = a.id'
            . ' WHERE a.status = :status'
            . ' AND (e.article_id IS NULL OR e.model <> :model OR e.source_hash <> ' . self::SOURCE_HASH . ')'
            . ' ORDER BY a.id LIMIT :limit',
        );
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('model', $model);
        $statement->bindValue('limit', max(0, $limit), \PDO::PARAM_INT);
        $statement->execute();

        $articles = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)
                || !is_numeric($row['id'] ?? null)
                || !is_string($row['title'] ?? null)
                || !is_string($row['excerpt'] ?? null)
                || !is_string($row['body'] ?? null)
                || !is_string($row['source_hash'] ?? null)
            ) {
                throw new \UnexpectedValueException('Řádek tabulky articles má neočekávaný tvar.');
            }
            $articles[] = new IndexableArticle(
                (int) $row['id'],
                $row['title'],
                $row['excerpt'],
                $row['body'],
                $row['source_hash'],
            );
        }

        return $articles;
    }

    public function save(IndexableArticle $article, string $model, Embedding $embedding, \DateTimeImmutable $indexedAt): void
    {
        if ($embedding->dimensions() !== self::DIMENSIONS) {
            throw new \InvalidArgumentException(sprintf(
                'Embedding má %d dimenzí, tabulka article_embeddings čeká %d.',
                $embedding->dimensions(),
                self::DIMENSIONS,
            ));
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO article_embeddings (article_id, model, source_hash, embedding, indexed_at)'
            . ' VALUES (:article_id, :model, :source_hash, VEC_FromText(:embedding), :indexed_at)'
            . ' ON DUPLICATE KEY UPDATE model = VALUES(model), source_hash = VALUES(source_hash),'
            . ' embedding = VALUES(embedding), indexed_at = VALUES(indexed_at)',
        );
        $statement->bindValue('article_id', $article->id, \PDO::PARAM_INT);
        $statement->bindValue('model', $model);
        $statement->bindValue('source_hash', $article->sourceHash);
        $statement->bindValue('embedding', $this->toJson($embedding));
        $statement->bindValue('indexed_at', $this->formatTime($indexedAt));
        $statement->execute();
    }

    public function removeStale(string $model): int
    {
        $statement = $this->pdo->prepare(
            'DELETE e FROM article_embeddings e JOIN articles a ON a.id = e.article_id'
            . ' WHERE a.status <> :status OR e.model <> :model',
        );
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('model', $model);
        $statement->execute();

        return $statement->rowCount();
    }

    public function status(string $model): EmbeddingIndexStatus
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(a.id) AS published, COUNT(e.article_id) AS up_to_date FROM articles a'
            . ' LEFT JOIN article_embeddings e ON e.article_id = a.id AND e.model = :model'
            . ' AND e.source_hash = ' . self::SOURCE_HASH
            . ' WHERE a.status = :status',
        );
        $statement->bindValue('model', $model);
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->execute();

        $row = $statement->fetch();
        if (!is_array($row) || !is_numeric($row['published'] ?? null) || !is_numeric($row['up_to_date'] ?? null)) {
            throw new \UnexpectedValueException('Stav indexu má neočekávaný tvar.');
        }

        return new EmbeddingIndexStatus((int) $row['published'], (int) $row['up_to_date']);
    }

    public function nearestPublished(Embedding $query, string $model, \DateTimeImmutable $now, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        // Vnitřní dotaz = jen vektorový index (ORDER BY vzdálenost LIMIT); publikovanost až ve vnějším.
        $statement = $this->pdo->prepare(
            'SELECT a.slug, a.title, a.excerpt, a.body, a.published_at, c.name AS category_name, n.distance'
            . ' FROM (SELECT article_id, VEC_DISTANCE_COSINE(embedding, VEC_FromText(:query)) AS distance'
            . ' FROM article_embeddings WHERE model = :model ORDER BY distance LIMIT ' . self::CANDIDATES . ') n'
            . ' JOIN articles a ON a.id = n.article_id'
            . ' JOIN categories c ON c.id = a.category_id'
            . ' WHERE ' . self::PUBLISHED_CONDITION
            . ' ORDER BY n.distance, a.id LIMIT :limit',
        );
        $statement->bindValue('query', $this->toJson($query));
        $statement->bindValue('model', $model);
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('now', $this->formatTime($now));
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        $articles = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)
                || !is_string($row['slug'] ?? null)
                || !is_string($row['title'] ?? null)
                || !is_string($row['excerpt'] ?? null)
                || !is_string($row['body'] ?? null)
                || !is_string($row['published_at'] ?? null)
                || !is_string($row['category_name'] ?? null)
                || !is_numeric($row['distance'] ?? null)
            ) {
                throw new \UnexpectedValueException('Řádek vyhledávání má neočekávaný tvar.');
            }
            $articles[] = new SimilarArticle(
                $row['slug'],
                $row['title'],
                $row['excerpt'],
                $row['body'],
                $row['category_name'],
                new \DateTimeImmutable($row['published_at']),
                (float) $row['distance'],
            );
        }

        return $articles;
    }

    private function toJson(Embedding $embedding): string
    {
        return json_encode($embedding->values, JSON_THROW_ON_ERROR);
    }

    /** Čas v zóně PHP (Praha, ADR-0007), jak se ukládají i `published_at`. */
    private function formatTime(\DateTimeImmutable $time): string
    {
        return $time
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s.u');
    }
}
