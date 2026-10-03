<?php

declare(strict_types=1);

use App\Infrastructure\Migration\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE articles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                category_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(200) NOT NULL,
                slug VARCHAR(220) NOT NULL,
                excerpt VARCHAR(500) NOT NULL DEFAULT '',
                body MEDIUMTEXT NOT NULL,
                status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
                published_at DATETIME(6) NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                CONSTRAINT uq_articles_slug UNIQUE (slug),
                INDEX idx_articles_status_published_at (status, published_at),
                INDEX idx_articles_category_status_published_at (category_id, status, published_at),
                INDEX idx_articles_created_by (created_by),
                INDEX idx_articles_updated_by (updated_by),
                CONSTRAINT fk_articles_category_id FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
                CONSTRAINT fk_articles_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_articles_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS articles');
    }
};
