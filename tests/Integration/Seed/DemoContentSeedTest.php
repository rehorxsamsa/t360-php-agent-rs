<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seed;

use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Seed\Seed;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Ukázková data (plán 004, AC 22): kontrakt počtů, stavů a idempotence. */
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

    public function test_first_run_inserts_contract_counts_and_returns_them(): void
    {
        $result = $this->seed()->run($this->pdo);

        self::assertSame(['rubriky' => 3, 'štítky' => 5, 'články' => 16], $result);
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

        self::assertSame(['rubriky' => 0, 'štítky' => 0, 'články' => 0], $second);
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

        self::assertSame(['rubriky' => 0, 'štítky' => 0, 'články' => 1], $result);
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
}
