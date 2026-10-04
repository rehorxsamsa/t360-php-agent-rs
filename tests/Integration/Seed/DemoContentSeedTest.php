<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seed;

use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Seed\Seed;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Ukázková data (plán 004, AC 22; plán 007, AC 19 a ADR-0007): kontrakt počtů, stavů, časů a idempotence. */
final class DemoContentSeedTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    private function seed(): Seed
    {
        $seed = require __DIR__ . '/../../../database/seeds/demo_content.php';
        self::assertInstanceOf(Seed::class, $seed);

        return $seed;
    }

    /**
     * Výsledek seedu bez závislosti na pořadí klíčů.
     *
     * @param array<string, int> $result
     * @return array<string, int>
     */
    private static function sorted(array $result): array
    {
        ksort($result);

        return $result;
    }

    public function test_first_run_inserts_contract_counts_and_returns_them(): void
    {
        $result = $this->seed()->run($this->pdo);

        self::assertSame(self::sorted(['rubriky' => 3, 'štítky' => 5, 'články' => 16, 'časy' => 0]), self::sorted($result));
        self::assertSame(3, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM categories'));
        self::assertSame(5, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM tags'));
        self::assertSame(16, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM articles'));
    }

    public function test_second_run_inserts_nothing_and_creates_no_duplicates(): void
    {
        $seed = $this->seed();
        $seed->run($this->pdo);
        $linksBefore = TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM article_tags');

        $second = $seed->run($this->pdo);

        self::assertSame(self::sorted(['rubriky' => 0, 'štítky' => 0, 'články' => 0, 'časy' => 0]), self::sorted($second));
        self::assertSame(3, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM categories'));
        self::assertSame(5, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM tags'));
        self::assertSame(16, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM articles'));
        self::assertSame($linksBefore, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM article_tags'));
        self::assertSame(0, TestDatabase::count(
            $this->pdo,
            'SELECT COUNT(*) FROM (SELECT article_id, tag_id FROM article_tags GROUP BY article_id, tag_id HAVING COUNT(*) > 1) d',
        ));
    }

    public function test_second_run_does_not_overwrite_edited_rows(): void
    {
        $seed = $this->seed();
        $seed->run($this->pdo);
        $this->pdo->exec("UPDATE articles SET title = 'Upraveno ručně' WHERE slug = 'prvni-clanek'");

        $seed->run($this->pdo);

        self::assertSame(
            ['Upraveno ručně'],
            TestDatabase::column($this->pdo, "SELECT title FROM articles WHERE slug = 'prvni-clanek'"),
        );
    }

    public function test_run_adds_only_missing_rows(): void
    {
        $seed = $this->seed();
        $seed->run($this->pdo);
        $this->pdo->exec("DELETE FROM articles WHERE slug = 'druhy-koncept'");

        $result = $seed->run($this->pdo);

        self::assertSame(self::sorted(['rubriky' => 0, 'štítky' => 0, 'články' => 1, 'časy' => 0]), self::sorted($result));
    }

    public function test_article_statuses_match_contract(): void
    {
        $this->seed()->run($this->pdo);

        self::assertSame(
            [['status' => 'archived', 'n' => '1'], ['status' => 'draft', 'n' => '2'], ['status' => 'published', 'n' => '13']],
            TestDatabase::rows($this->pdo, 'SELECT status, COUNT(*) AS n FROM articles GROUP BY status ORDER BY CAST(status AS CHAR)'),
        );
    }

    public function test_fixed_slugs_and_dates_match_contract(): void
    {
        $this->seed()->run($this->pdo);

        $rows = TestDatabase::rows(
            $this->pdo,
            "SELECT slug, status, DATE_FORMAT(published_at, '%Y-%m-%d %H:%i') AS at FROM articles
             WHERE slug IN ('ukazka-markdownu', 'sablony-a-escapovani', 'prvni-clanek', 'rozepsany-koncept',
                            'druhy-koncept', 'archivni-clanek', 'planovany-clanek') ORDER BY slug",
        );
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row['slug']] = $row;
        }

        self::assertCount(7, $bySlug);
        self::assertSame('2026-09-12 08:00', $bySlug['ukazka-markdownu']['at']);
        self::assertSame('2026-09-11 08:00', $bySlug['sablony-a-escapovani']['at']);
        self::assertSame('2026-09-01 08:00', $bySlug['prvni-clanek']['at']);
        self::assertSame('2026-08-15', substr($bySlug['archivni-clanek']['at'], 0, 10));
        self::assertSame('archived', $bySlug['archivni-clanek']['status']);
        self::assertSame('2099-01-01 08:00', $bySlug['planovany-clanek']['at']);
        self::assertSame('published', $bySlug['planovany-clanek']['status']);
        foreach (['rozepsany-koncept', 'druhy-koncept'] as $draft) {
            self::assertSame('draft', $bySlug[$draft]['status']);
            self::assertSame('', $bySlug[$draft]['at'], 'koncept má published_at NULL');
        }
    }

    public function test_published_dates_run_one_per_day_from_first_to_twelfth_september(): void
    {
        $this->seed()->run($this->pdo);

        $days = TestDatabase::column(
            $this->pdo,
            "SELECT DATE_FORMAT(published_at, '%Y-%m-%d') FROM articles
             WHERE status = 'published' AND published_at < '2099-01-01' ORDER BY published_at",
        );

        self::assertSame(
            array_map(static fn(int $d): string => sprintf('2026-09-%02d', $d), range(1, 12)),
            $days,
        );
    }

    public function test_demo_article_has_category_and_two_tags_and_attack_examples(): void
    {
        $this->seed()->run($this->pdo);

        self::assertSame(
            ['Technologie'],
            TestDatabase::column($this->pdo, "SELECT c.name FROM articles a JOIN categories c ON c.id = a.category_id WHERE a.slug = 'ukazka-markdownu'"),
        );
        self::assertSame(
            ['Bezpečnost', 'PHP'],
            TestDatabase::column(
                $this->pdo,
                "SELECT t.name FROM article_tags links JOIN tags t ON t.id = links.tag_id JOIN articles a ON a.id = links.article_id
                 WHERE a.slug = 'ukazka-markdownu' ORDER BY t.name",
            ),
        );
        $body = TestDatabase::column($this->pdo, "SELECT body FROM articles WHERE slug = 'ukazka-markdownu'")[0];
        self::assertStringContainsString('<script>alert("xss")</script>', $body);
        self::assertStringContainsString('[nebezpečný odkaz](javascript:alert(1))', $body);
    }

    public function test_escaping_demo_article_has_contract_title(): void
    {
        $this->seed()->run($this->pdo);

        self::assertSame(
            ['Šablony & escapování: <script> se nespustí'],
            TestDatabase::column($this->pdo, "SELECT title FROM articles WHERE slug = 'sablony-a-escapovani'"),
        );
    }

    public function test_seed_contains_czech_diacritics_in_collation_order(): void
    {
        $this->seed()->run($this->pdo);

        self::assertSame(
            ['Technologie', 'Věda a výzkum', 'Zprávy'],
            TestDatabase::column($this->pdo, 'SELECT name FROM categories ORDER BY name'),
        );
        self::assertSame(
            ['Bezpečnost', 'Docker', 'PHP', 'Přístupnost', 'Umělá inteligence'],
            TestDatabase::column($this->pdo, 'SELECT name FROM tags ORDER BY name'),
        );
    }

    // ---------------------------------------------------------------- plán 007, AC 19: časy seedovaných článků

    /** Očekávaný čas vytvoření a úpravy: published_at do konce září 2026, jinak 2026-09-01 08:00:00. */
    private const string FALLBACK_TIME = '2026-09-01 08:00:00';

    private const string LAST_SEED_DAY = '2026-09-30 23:59:59';

    /** @param array<string, int> $result */
    private static function value(array $result, string $key): int
    {
        self::assertArrayHasKey($key, $result);

        return $result[$key];
    }

    /** @return list<array<string, string>> */
    private function articleTimes(): array
    {
        return TestDatabase::rows(
            $this->pdo,
            "SELECT slug,
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created,
                    DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated,
                    COALESCE(DATE_FORMAT(published_at, '%Y-%m-%d %H:%i:%s'), '') AS published
             FROM articles ORDER BY slug",
        );
    }

    /** @param array<string, string> $row */
    private static function expectedTime(array $row): string
    {
        return $row['published'] !== '' && $row['published'] <= self::LAST_SEED_DAY ? $row['published'] : self::FALLBACK_TIME;
    }

    public function test_seeded_articles_have_created_and_updated_at_from_publication_or_fallback(): void
    {
        $this->seed()->run($this->pdo);

        $rows = $this->articleTimes();
        self::assertCount(16, $rows);
        foreach ($rows as $row) {
            $expected = self::expectedTime($row);
            self::assertSame($expected, $row['created'], 'created_at u ' . $row['slug']);
            self::assertSame($expected, $row['updated'], 'updated_at u ' . $row['slug']);
        }

        $bySlug = array_column($rows, null, 'slug');
        self::assertSame('2026-09-12 08:00:00', $bySlug['ukazka-markdownu']['updated']);
        self::assertSame(self::FALLBACK_TIME, $bySlug['planovany-clanek']['updated'], 'publikace 2099 → náhradní čas');
        self::assertSame(self::FALLBACK_TIME, $bySlug['rozepsany-koncept']['updated'], 'koncept bez publikace → náhradní čas');
    }

    public function test_untouched_seeded_article_with_wrong_times_is_reconciled(): void
    {
        $seed = $this->seed();
        $seed->run($this->pdo);
        $this->pdo->exec(
            "UPDATE articles SET created_at = '2026-10-04 08:00:00', updated_at = '2026-10-04 09:00:00'
             WHERE slug IN ('prvni-clanek', 'rozepsany-koncept')",
        );

        $result = $seed->run($this->pdo);

        self::assertSame(2, self::value($result, 'časy'));
        self::assertSame(0, self::value($result, 'články'));
        $bySlug = array_column($this->articleTimes(), null, 'slug');
        self::assertSame('2026-09-01 08:00:00', $bySlug['prvni-clanek']['created']);
        self::assertSame('2026-09-01 08:00:00', $bySlug['prvni-clanek']['updated']);
        self::assertSame(self::FALLBACK_TIME, $bySlug['rozepsany-koncept']['created']);
        self::assertSame(self::FALLBACK_TIME, $bySlug['rozepsany-koncept']['updated']);
    }

    public function test_article_edited_in_administration_keeps_its_times(): void
    {
        $seed = $this->seed();
        $seed->run($this->pdo);
        $this->pdo->exec("INSERT INTO users (email, display_name, password_hash) VALUES ('admin@example.cz', 'Administrátor', 'h')");
        $userId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            "UPDATE articles SET updated_by = :user, updated_at = '2026-10-04 09:00:00' WHERE slug = 'ukazka-markdownu'",
        );
        $statement->execute(['user' => $userId]);

        $result = $seed->run($this->pdo);

        self::assertSame(0, self::value($result, 'časy'));
        $bySlug = array_column($this->articleTimes(), null, 'slug');
        self::assertSame('2026-10-04 09:00:00', $bySlug['ukazka-markdownu']['updated']);
        self::assertSame('2026-09-12 08:00:00', $bySlug['ukazka-markdownu']['created']);
    }

    public function test_second_run_after_reconciliation_reports_nothing(): void
    {
        $seed = $this->seed();
        $seed->run($this->pdo);
        $this->pdo->exec("UPDATE articles SET updated_at = '2026-10-04 09:00:00' WHERE slug = 'prvni-clanek'");
        self::assertSame(1, self::value($seed->run($this->pdo), 'časy'));

        $third = $seed->run($this->pdo);

        self::assertSame(0, self::value($third, 'časy'));
        self::assertSame(0, self::value($third, 'články'));
    }
}
