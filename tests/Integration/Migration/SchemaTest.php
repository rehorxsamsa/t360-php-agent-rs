<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Ověřuje skutečné migrace z database/migrations/ (schéma dle ADR-0004). */
final class SchemaTest extends TestCase
{
    private \PDO $pdo;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->migrator = new Migrator(
            new PdoMigrationRepository($this->pdo),
            $this->pdo,
            __DIR__ . '/../../../database/migrations',
        );
        $this->migrator->migrate();
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    private function insertCategory(string $slug = 'rubrika'): int
    {
        $statement = $this->pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
        $statement->execute(['Rubrika', $slug]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertUser(): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (email, display_name, password_hash) VALUES (?, ?, ?)');
        $statement->execute(['a@example.test', 'Admin', 'hash']);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertArticle(int $categoryId, string $slug = 'clanek', string $status = 'draft', ?int $userId = null): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO articles (category_id, title, slug, body, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([$categoryId, 'Titulek', $slug, 'Text', $status, $userId, $userId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function scalar(string $sql): mixed
    {
        return TestDatabase::statement($this->pdo, $sql)->fetchColumn();
    }

    private function errorCodeOf(callable $action): ?int
    {
        try {
            $action();
        } catch (\PDOException $exception) {
            $code = $exception->errorInfo[1] ?? 0;

            return is_int($code) ? $code : 0;
        }

        return null;
    }

    public function test_all_expected_tables_exist_as_innodb_with_czech_collation(): void
    {
        $rows = TestDatabase::rows(
            $this->pdo,
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        );
        $byName = array_column($rows, null, 'TABLE_NAME');

        foreach (['users', 'categories', 'tags', 'articles', 'article_tags', 'audit_log', 'ai_calls', 'article_embeddings', 'ai_rate_limit_hits', 'migrations'] as $table) {
            self::assertArrayHasKey($table, $byName, $table);
            self::assertSame('InnoDB', $byName[$table]['ENGINE'], $table);
            self::assertSame('utf8mb4_czech_ci', $byName[$table]['TABLE_COLLATION'], $table);
        }
    }

    public function test_deleting_category_with_articles_is_restricted(): void
    {
        $categoryId = $this->insertCategory();
        $this->insertArticle($categoryId);

        $code = $this->errorCodeOf(fn() => $this->pdo->exec('DELETE FROM categories WHERE id = ' . $categoryId));

        self::assertSame(1451, $code);
    }

    public function test_deleting_article_cascades_to_article_tags(): void
    {
        $articleId = $this->insertArticle($this->insertCategory());
        $this->pdo->exec("INSERT INTO tags (name, slug) VALUES ('Štítek', 'stitek')");
        $tagId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO article_tags (article_id, tag_id) VALUES ($articleId, $tagId)");

        $this->pdo->exec('DELETE FROM articles WHERE id = ' . $articleId);

        self::assertSame(0, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM article_tags'));
        self::assertSame(1, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM tags'));
    }

    public function test_deleting_user_sets_author_and_audit_references_to_null(): void
    {
        $userId = $this->insertUser();
        $articleId = $this->insertArticle($this->insertCategory(), 'clanek', 'draft', $userId);
        $this->pdo->exec("INSERT INTO audit_log (user_id, action) VALUES ($userId, 'article.deleted')");

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $userId);

        self::assertNull($this->scalar("SELECT created_by FROM articles WHERE id = $articleId"));
        self::assertNull($this->scalar("SELECT updated_by FROM articles WHERE id = $articleId"));
        self::assertNull($this->scalar('SELECT user_id FROM audit_log'));
        self::assertSame(1, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM audit_log'));
    }

    public function test_duplicate_article_slug_is_rejected(): void
    {
        $categoryId = $this->insertCategory();
        $this->insertArticle($categoryId, 'stejny');

        $code = $this->errorCodeOf(fn() => $this->insertArticle($categoryId, 'stejny'));

        self::assertSame(1062, $code);
    }

    public function test_invalid_article_status_is_rejected(): void
    {
        $code = $this->errorCodeOf(fn() => $this->insertArticle($this->insertCategory(), 'clanek', 'smazano'));

        self::assertNotNull($code);
    }

    public function test_rollback_of_all_migrations_leaves_only_empty_migrations_table_and_migrate_works_again(): void
    {
        $this->migrator->rollback(100);

        $tables = TestDatabase::column(
            $this->pdo,
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        );
        self::assertSame(['migrations'], $tables);
        self::assertSame(0, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM migrations'));

        self::assertCount(9, $this->migrator->migrate());
    }

    // ---------------------------------------------------------------- plán 006, AC 20: ai_calls

    public function test_ai_calls_table_has_expected_columns(): void
    {
        $rows = TestDatabase::rows(
            $this->pdo,
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_calls' ORDER BY ORDINAL_POSITION",
        );
        $columns = array_column($rows, null, 'COLUMN_NAME');

        self::assertSame(
            [
                'id', 'user_id', 'example_id', 'provider', 'model',
                'input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens',
                'cost_usd', 'duration_ms', 'attempts', 'status', 'error_type', 'stop_reason', 'request_id', 'created_at',
            ],
            array_keys($columns),
        );
        $expected = [
            'id' => ['bigint(20) unsigned', 'NO'],
            'user_id' => ['bigint(20) unsigned', 'YES'],
            'example_id' => ['varchar(20)', 'NO'],
            'provider' => ['varchar(20)', 'NO'],
            'model' => ['varchar(100)', 'NO'],
            'input_tokens' => ['int(10) unsigned', 'NO'],
            'output_tokens' => ['int(10) unsigned', 'NO'],
            'cache_creation_input_tokens' => ['int(10) unsigned', 'NO'],
            'cache_read_input_tokens' => ['int(10) unsigned', 'NO'],
            'cost_usd' => ['decimal(12,6)', 'NO'],
            'duration_ms' => ['int(10) unsigned', 'NO'],
            'attempts' => ['tinyint(3) unsigned', 'NO'],
            'status' => ["enum('ok','error')", 'NO'],
            'error_type' => ['varchar(50)', 'YES'],
            'stop_reason' => ['varchar(30)', 'YES'],
            'request_id' => ['varchar(100)', 'YES'],
            'created_at' => ['datetime(6)', 'NO'],
        ];
        foreach ($expected as $name => [$type, $nullable]) {
            self::assertSame($type, $columns[$name]['COLUMN_TYPE'], $name);
            self::assertSame($nullable, $columns[$name]['IS_NULLABLE'], $name);
        }
        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'duration_ms'] as $name) {
            self::assertSame('0', $columns[$name]['COLUMN_DEFAULT'], $name);
        }
        self::assertSame('1', $columns['attempts']['COLUMN_DEFAULT']);
        self::assertStringContainsStringIgnoringCase('current_timestamp(6)', $columns['created_at']['COLUMN_DEFAULT']);
    }

    public function test_ai_calls_has_created_at_index_and_user_foreign_key_set_null(): void
    {
        self::assertSame(
            ['created_at'],
            TestDatabase::column(
                $this->pdo,
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_calls' AND INDEX_NAME = 'idx_ai_calls_created_at'
                 ORDER BY SEQ_IN_INDEX",
            ),
        );

        $constraints = TestDatabase::rows(
            $this->pdo,
            "SELECT REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_calls' AND CONSTRAINT_NAME = 'fk_ai_calls_user_id'",
        );
        self::assertSame([['REFERENCED_TABLE_NAME' => 'users', 'DELETE_RULE' => 'SET NULL']], $constraints);
    }

    public function test_deleting_user_keeps_ai_call_with_null_user(): void
    {
        $userId = $this->insertUser();
        $this->pdo->exec("INSERT INTO ai_calls (user_id, example_id, provider, model, status) VALUES ($userId, '01', 'fake', 'claude-sonnet-5-5', 'ok')");

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $userId);

        self::assertSame(1, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM ai_calls'));
        self::assertNull($this->scalar('SELECT user_id FROM ai_calls'));
    }

    public function test_invalid_ai_call_status_is_rejected(): void
    {
        $code = $this->errorCodeOf(fn() => $this->pdo->exec(
            "INSERT INTO ai_calls (example_id, provider, model, status) VALUES ('01', 'fake', 'm', 'pending')",
        ));

        self::assertNotNull($code);
    }

    /** Plán 013, AC 22 (záměrná regrese plánu 009): poslední migrace je nově ai_rate_limit_hits, ostatní tabulky zůstávají. */
    public function test_rolling_back_last_migration_drops_only_ai_rate_limit_hits(): void
    {
        $this->migrator->rollback(1);

        $tables = TestDatabase::column(
            $this->pdo,
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        );
        self::assertNotContains('ai_rate_limit_hits', $tables);
        self::assertContains('article_embeddings', $tables);
        self::assertContains('ai_calls', $tables);
        self::assertContains('users', $tables);
        self::assertCount(1, $this->migrator->migrate());
        self::assertContains('ai_rate_limit_hits', TestDatabase::column(
            $this->pdo,
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        ));
    }

    /** Plán 013 (záměrná regrese plánu 009): dvě poslední migrace jsou ai_rate_limit_hits a article_embeddings. */
    public function test_rolling_back_two_migrations_drops_ai_rate_limit_hits_and_article_embeddings(): void
    {
        $this->migrator->rollback(2);

        $tables = TestDatabase::column(
            $this->pdo,
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        );
        self::assertNotContains('ai_rate_limit_hits', $tables);
        self::assertNotContains('article_embeddings', $tables);
        self::assertContains('ai_calls', $tables);
        self::assertCount(2, $this->migrator->migrate());
    }

    public function test_rolling_back_three_migrations_also_drops_ai_calls(): void
    {
        $this->migrator->rollback(3);

        $tables = TestDatabase::column(
            $this->pdo,
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        );
        self::assertNotContains('ai_calls', $tables);
        self::assertContains('audit_log', $tables);
        self::assertCount(3, $this->migrator->migrate());
    }

    // ---------------------------------------------------------------- plán 009, AC 18: article_embeddings

    private function vectorLiteral(int $hotIndex = 0): string
    {
        $values = array_fill(0, 768, 0.0);
        $values[$hotIndex] = 1.0;

        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    private function insertEmbedding(int $articleId, string $model = 'fake-hash-768'): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO article_embeddings (article_id, model, source_hash, embedding, indexed_at) VALUES (?, ?, ?, VEC_FromText(?), ?)',
        );
        $statement->execute([$articleId, $model, str_repeat('a', 64), $this->vectorLiteral(), '2026-10-08 12:00:00.000000']);
    }

    public function test_article_embeddings_table_has_expected_columns(): void
    {
        $rows = TestDatabase::rows(
            $this->pdo,
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'article_embeddings' ORDER BY ORDINAL_POSITION",
        );
        $columns = array_column($rows, null, 'COLUMN_NAME');

        self::assertSame(['article_id', 'model', 'source_hash', 'embedding', 'indexed_at'], array_keys($columns));
        $expected = [
            'article_id' => 'bigint(20) unsigned',
            'model' => 'varchar(100)',
            'source_hash' => 'char(64)',
            'indexed_at' => 'datetime(6)',
        ];
        foreach ($expected as $name => $type) {
            self::assertSame($type, $columns[$name]['COLUMN_TYPE'], $name);
        }
        self::assertStringContainsStringIgnoringCase('vector(768)', $columns['embedding']['COLUMN_TYPE']);
        foreach (array_keys($columns) as $name) {
            self::assertSame('NO', $columns[$name]['IS_NULLABLE'], $name);
        }
        self::assertContains($columns['indexed_at']['COLUMN_DEFAULT'], ['', 'NULL'], 'indexed_at nemá DEFAULT (ADR-0007).');
        self::assertSame(
            ['article_id'],
            TestDatabase::column(
                $this->pdo,
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'article_embeddings' AND INDEX_NAME = 'PRIMARY'
                 ORDER BY SEQ_IN_INDEX",
            ),
        );
    }

    public function test_article_embeddings_has_vector_index_and_cascading_foreign_key(): void
    {
        self::assertSame(
            ['embedding'],
            TestDatabase::column(
                $this->pdo,
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'article_embeddings'
                   AND INDEX_NAME = 'idx_article_embeddings_embedding'",
            ),
        );

        $constraints = TestDatabase::rows(
            $this->pdo,
            "SELECT REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'article_embeddings'
               AND CONSTRAINT_NAME = 'fk_article_embeddings_article_id'",
        );
        self::assertSame([['REFERENCED_TABLE_NAME' => 'articles', 'DELETE_RULE' => 'CASCADE']], $constraints);
    }

    public function test_deleting_article_deletes_its_embedding(): void
    {
        $categoryId = $this->insertCategory();
        $kept = $this->insertArticle($categoryId, 'zustava', 'published');
        $deleted = $this->insertArticle($categoryId, 'mazany', 'published');
        $this->insertEmbedding($kept);
        $this->insertEmbedding($deleted);

        $this->pdo->exec('DELETE FROM articles WHERE id = ' . $deleted);

        self::assertSame(
            [(string) $kept],
            TestDatabase::column($this->pdo, 'SELECT article_id FROM article_embeddings'),
        );
    }

    public function test_embedding_of_wrong_dimension_or_unknown_article_is_rejected(): void
    {
        $articleId = $this->insertArticle($this->insertCategory(), 'clanek', 'published');

        $wrongDimension = $this->errorCodeOf(function () use ($articleId): void {
            $statement = $this->pdo->prepare(
                'INSERT INTO article_embeddings (article_id, model, source_hash, embedding, indexed_at) VALUES (?, ?, ?, VEC_FromText(?), ?)',
            );
            $statement->execute([$articleId, 'm', str_repeat('a', 64), '[1,0,0]', '2026-10-08 12:00:00']);
        });
        $unknownArticle = $this->errorCodeOf(fn() => $this->insertEmbedding($articleId + 1000));

        self::assertNotNull($wrongDimension, 'VECTOR(768) nesmí přijmout 3 složky.');
        self::assertSame(1452, $unknownArticle);
    }

    // ---------------------------------------------------------------- plán 013, AC 22: ai_rate_limit_hits

    public function test_ai_rate_limit_hits_table_has_expected_columns(): void
    {
        $rows = TestDatabase::rows(
            $this->pdo,
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_rate_limit_hits' ORDER BY ORDINAL_POSITION",
        );
        $columns = array_column($rows, null, 'COLUMN_NAME');

        self::assertSame(['id', 'user_id', 'bucket', 'created_at'], array_keys($columns));
        $expected = [
            'id' => ['bigint(20) unsigned', 'NO'],
            'user_id' => ['bigint(20) unsigned', 'NO'],
            'bucket' => ['varchar(20)', 'NO'],
            'created_at' => ['datetime(6)', 'NO'],
        ];
        foreach ($expected as $name => [$type, $nullable]) {
            self::assertSame($type, $columns[$name]['COLUMN_TYPE'], $name);
            self::assertSame($nullable, $columns[$name]['IS_NULLABLE'], $name);
        }
        self::assertStringContainsString('auto_increment', $columns['id']['EXTRA']);
        // Bez DEFAULT: čas vždy dodává Clock aplikace (ADR-0007). Chybějící výchozí hodnota je v information_schema
        // NULL (TestDatabase::rows z ní udělá ''); DEFAULT NULL by MariaDB hlásila jako text 'NULL'.
        self::assertSame('', $columns['created_at']['COLUMN_DEFAULT'], 'created_at nesmí mít DEFAULT.');
        self::assertSame(
            ['id'],
            TestDatabase::column(
                $this->pdo,
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_rate_limit_hits' AND INDEX_NAME = 'PRIMARY'",
            ),
        );
    }

    public function test_ai_rate_limit_hits_has_window_index_and_cascading_user_foreign_key(): void
    {
        self::assertSame(
            ['user_id', 'bucket', 'created_at'],
            TestDatabase::column(
                $this->pdo,
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_rate_limit_hits'
                   AND INDEX_NAME = 'idx_ai_rate_limit_hits_user_bucket_created'
                 ORDER BY SEQ_IN_INDEX",
            ),
        );

        $constraints = TestDatabase::rows(
            $this->pdo,
            "SELECT REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_rate_limit_hits'
               AND CONSTRAINT_NAME = 'fk_ai_rate_limit_hits_user_id'",
        );
        self::assertSame([['REFERENCED_TABLE_NAME' => 'users', 'DELETE_RULE' => 'CASCADE']], $constraints);
    }

    public function test_ai_rate_limit_hit_without_created_at_or_with_unknown_user_is_rejected(): void
    {
        $userId = $this->insertUser();

        $withoutTime = $this->errorCodeOf(fn() => $this->pdo->exec(
            "INSERT INTO ai_rate_limit_hits (user_id, bucket) VALUES ($userId, 'ai')",
        ));
        $unknownUser = $this->errorCodeOf(fn() => $this->pdo->exec(
            "INSERT INTO ai_rate_limit_hits (user_id, bucket, created_at) VALUES (999999, 'ai', '2026-10-09 12:00:00')",
        ));

        self::assertNotNull($withoutTime, 'Bez created_at (strict mode) se řádek nevloží.');
        self::assertSame(1452, $unknownUser, 'FK na users.');
    }

    public function test_deleting_user_deletes_his_rate_limit_hits(): void
    {
        $userId = $this->insertUser();
        $this->pdo->exec("INSERT INTO ai_rate_limit_hits (user_id, bucket, created_at) VALUES ($userId, 'ai', '2026-10-09 12:00:00.000001')");

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $userId);

        self::assertSame(0, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM ai_rate_limit_hits'));
    }
}
