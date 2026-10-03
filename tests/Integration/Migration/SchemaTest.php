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

        foreach (['users', 'categories', 'tags', 'articles', 'article_tags', 'audit_log', 'migrations'] as $table) {
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

        self::assertCount(6, $this->migrator->migrate());
    }
}
