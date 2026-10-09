<?php

declare(strict_types=1);

use App\Infrastructure\Migration\Migration;

/**
 * Záznamy požadavků na AI trasy pro rate limit (plán 013, ADR-0013): posuvné okno.
 *
 * `created_at` je záměrně bez DEFAULT, čas vždy dodává Clock aplikace (ADR-0007).
 * Index (user_id, bucket, created_at) obsluhuje dotaz okna COUNT/MIN pro uživatele a kbelík.
 */
return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE ai_rate_limit_hits (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                bucket VARCHAR(20) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_ai_rate_limit_hits_user_bucket_created (user_id, bucket, created_at),
                CONSTRAINT fk_ai_rate_limit_hits_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ai_rate_limit_hits');
    }
};
