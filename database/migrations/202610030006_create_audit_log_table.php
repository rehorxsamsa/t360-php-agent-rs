<?php

declare(strict_types=1);

use App\Infrastructure\Migration\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                action VARCHAR(50) NOT NULL,
                entity_type VARCHAR(50) NULL,
                entity_id BIGINT UNSIGNED NULL,
                summary VARCHAR(255) NOT NULL DEFAULT '',
                ip_address VARCHAR(45) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                INDEX idx_audit_log_user_id (user_id),
                INDEX idx_audit_log_created_at (created_at),
                INDEX idx_audit_log_action_created_at (action, created_at),
                CONSTRAINT fk_audit_log_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS audit_log');
    }
};
