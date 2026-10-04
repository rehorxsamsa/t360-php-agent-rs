<?php

declare(strict_types=1);

use App\Infrastructure\Migration\Migration;

/** Log volání AI: jen metadata (tokeny, cena, stav), žádný obsah promptů ani odpovědí. */
return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE ai_calls (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                example_id VARCHAR(20) NOT NULL,
                provider VARCHAR(20) NOT NULL,
                model VARCHAR(100) NOT NULL,
                input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                cache_creation_input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                cache_read_input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
                status ENUM('ok','error') NOT NULL,
                error_type VARCHAR(50) NULL,
                stop_reason VARCHAR(30) NULL,
                request_id VARCHAR(100) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                INDEX idx_ai_calls_created_at (created_at),
                CONSTRAINT fk_ai_calls_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ai_calls');
    }
};
