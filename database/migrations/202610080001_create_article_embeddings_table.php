<?php

declare(strict_types=1);

use App\Infrastructure\Migration\Migration;

/**
 * Vektory článků pro sémantické vyhledávání (RAG, AI příklad 08).
 *
 * `VECTOR(768)` = dimenze modelu embeddinggemma (a falešného klienta fake-hash-768).
 * Jiný model s jinou dimenzí vyžaduje novou migraci. Vektorový index (HNSW, kosinová
 * vzdálenost) použije optimalizátor jen pro `ORDER BY VEC_DISTANCE_COSINE(...) LIMIT n`.
 */
return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE article_embeddings (
                article_id BIGINT UNSIGNED NOT NULL,
                model VARCHAR(100) NOT NULL,
                source_hash CHAR(64) NOT NULL,
                embedding VECTOR(768) NOT NULL,
                indexed_at DATETIME(6) NOT NULL,
                PRIMARY KEY (article_id),
                VECTOR INDEX idx_article_embeddings_embedding (embedding) M=8 DISTANCE=cosine,
                CONSTRAINT fk_article_embeddings_article_id FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS article_embeddings');
    }
};
