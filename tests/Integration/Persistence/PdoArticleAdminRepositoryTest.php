<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Article\ArticleAdminRepository;
use App\Domain\Article\ArticleData;
use App\Domain\Article\ArticleStatus;
use App\Domain\Article\SlugAlreadyTaken;
use App\Domain\User\Role;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoArticleAdminRepository;
use App\Infrastructure\Persistence\PdoUserRepository;
use App\Infrastructure\Seed\Seed;
use App\Tests\Integration\StatementCounter;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Administrace článků nad redakce_test se seedem z M4 (plán 005, AC 30–34). */
final class PdoArticleAdminRepositoryTest extends TestCase
{
    private const int SEEDED_ARTICLES = 16;

    private \PDO $pdo;
    private PdoArticleAdminRepository $repository;
    private \DateTimeImmutable $now;
    private int $adminId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();

        $seed = require __DIR__ . '/../../../database/seeds/demo_content.php';
        self::assertInstanceOf(Seed::class, $seed);
        $seed->run($this->pdo);

        $this->adminId = new PdoUserRepository($this->pdo)->add('admin@example.cz', 'Administrátor', 'hash', Role::Admin);
        $this->repository = new PdoArticleAdminRepository($this->pdo);
        $this->now = new \DateTimeImmutable('2026-10-03 12:00:00', new \DateTimeZone('Europe/Prague'));
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    // ---------------------------------------------------------------- pomocníci

    /** ID rubriky nebo štítku podle slugu ze seedu (slug je konstanta testu, ne vstup). */
    private function idOf(string $table, string $slug): int
    {
        return TestDatabase::count($this->pdo, sprintf("SELECT id FROM %s WHERE slug = '%s'", $table, $slug));
    }

    /** @param list<string> $tagSlugs */
    private function data(
        string $title = 'Nový článek',
        string $slug = 'novy-clanek',
        array $tagSlugs = ['php', 'docker'],
        ArticleStatus $status = ArticleStatus::Draft,
        ?string $publishedAt = null,
        string $categorySlug = 'technologie',
    ): ArticleData {
        $tagIds = array_map(fn(string $tag): int => $this->idOf('tags', $tag), $tagSlugs);
        sort($tagIds);

        return new ArticleData(
            title: $title,
            slug: $slug,
            excerpt: 'Perex.',
            body: "Ahoj **světe**",
            categoryId: $this->idOf('categories', $categorySlug),
            tagIds: $tagIds,
            status: $status,
            publishedAt: $publishedAt === null ? null : new \DateTimeImmutable($publishedAt, new \DateTimeZone('Europe/Prague')),
        );
    }

    /** @return array<string, string> */
    private function row(int $id): array
    {
        $rows = TestDatabase::rows($this->pdo, sprintf(
            'SELECT title, slug, status, published_at, created_by, updated_by, created_at, updated_at FROM articles WHERE id = %d',
            $id,
        ));
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @return list<string> */
    private function tagIdsOf(int $articleId): array
    {
        return TestDatabase::column($this->pdo, sprintf('SELECT tag_id FROM article_tags WHERE article_id = %d ORDER BY tag_id', $articleId));
    }

    private function articleCount(): int
    {
        return TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM articles');
    }

    private function linkCount(): int
    {
        return TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM article_tags');
    }

    // ---------------------------------------------------------------- AC 30: vytvoření a čtení pro formulář

    public function test_implements_domain_interface(): void
    {
        self::assertInstanceOf(ArticleAdminRepository::class, $this->repository);
    }

    public function test_create_writes_authors_and_times_from_php_clock(): void
    {
        $id = $this->repository->create($this->data(), $this->adminId, $this->now);

        $row = $this->row($id);
        self::assertSame('Nový článek', $row['title']);
        self::assertSame('novy-clanek', $row['slug']);
        self::assertSame('draft', $row['status']);
        self::assertSame('', $row['published_at']);
        self::assertSame((string) $this->adminId, $row['created_by']);
        self::assertSame((string) $this->adminId, $row['updated_by']);
        self::assertSame('2026-10-03 12:00:00.000000', $row['created_at']);
        self::assertSame('2026-10-03 12:00:00.000000', $row['updated_at']);
    }

    public function test_create_stores_tag_links(): void
    {
        $id = $this->repository->create($this->data(), $this->adminId, $this->now);

        $expected = [(string) $this->idOf('tags', 'docker'), (string) $this->idOf('tags', 'php')];
        sort($expected);
        self::assertSame($expected, $this->tagIdsOf($id));
    }

    public function test_find_for_editing_returns_all_fields(): void
    {
        $data = $this->data(status: ArticleStatus::Published, publishedAt: '2026-11-01 09:30:00', tagSlugs: ['php', 'bezpecnost', 'docker']);
        $id = $this->repository->create($data, $this->adminId, $this->now);

        $article = $this->repository->findForEditing($id);

        self::assertNotNull($article);
        self::assertSame($id, $article->id);
        self::assertSame('Nový článek', $article->title);
        self::assertSame('novy-clanek', $article->slug);
        self::assertSame('Perex.', $article->excerpt);
        self::assertSame("Ahoj **světe**", $article->body);
        self::assertSame($this->idOf('categories', 'technologie'), $article->categoryId);
        self::assertSame($data->tagIds, $article->tagIds);
        $sorted = $article->tagIds;
        sort($sorted);
        self::assertSame($sorted, $article->tagIds);
        self::assertSame(ArticleStatus::Published, $article->status);
        self::assertNotNull($article->publishedAt);
        self::assertSame('2026-11-01 09:30:00', $article->publishedAt->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Prague', $article->publishedAt->getTimezone()->getName());
        self::assertSame('2026-10-03 12:00:00', $article->updatedAt->format('Y-m-d H:i:s'));
        self::assertSame('Administrátor', $article->updatedByName);
    }

    public function test_find_for_editing_returns_seeded_draft_without_editor(): void
    {
        $id = TestDatabase::count($this->pdo, "SELECT id FROM articles WHERE slug = 'rozepsany-koncept'");

        $article = $this->repository->findForEditing($id);

        self::assertNotNull($article);
        self::assertSame(ArticleStatus::Draft, $article->status);
        self::assertNull($article->updatedByName);
    }

    public function test_find_for_editing_unknown_id_is_null(): void
    {
        self::assertNull($this->repository->findForEditing(999999));
    }

    // ---------------------------------------------------------------- AC 31: úprava a smazání

    public function test_update_changes_editor_time_and_tags_but_keeps_creation(): void
    {
        $editorId = new PdoUserRepository($this->pdo)->add('editor@example.cz', 'Editorka', 'hash', Role::Admin);
        $id = $this->repository->create($this->data(), $this->adminId, $this->now);

        $this->repository->update(
            $id,
            $this->data(title: 'Jiný titulek', slug: 'jiny-titulek', tagSlugs: ['bezpecnost']),
            $editorId,
            $this->now->modify('+1 hour'),
        );

        $row = $this->row($id);
        self::assertSame('Jiný titulek', $row['title']);
        self::assertSame('jiny-titulek', $row['slug']);
        self::assertSame('2026-10-03 13:00:00.000000', $row['updated_at']);
        self::assertSame((string) $editorId, $row['updated_by']);
        self::assertSame((string) $this->adminId, $row['created_by']);
        self::assertSame('2026-10-03 12:00:00.000000', $row['created_at']);
        self::assertSame([(string) $this->idOf('tags', 'bezpecnost')], $this->tagIdsOf($id));
        self::assertSame('Editorka', $this->repository->findForEditing($id)?->updatedByName);
    }

    public function test_update_with_no_tags_removes_all_links(): void
    {
        $id = $this->repository->create($this->data(), $this->adminId, $this->now);

        $this->repository->update($id, $this->data(tagSlugs: []), $this->adminId, $this->now);

        self::assertSame([], $this->tagIdsOf($id));
    }

    public function test_delete_removes_article_and_its_links_only(): void
    {
        $id = $this->repository->create($this->data(), $this->adminId, $this->now);
        $linksBefore = $this->linkCount();

        $this->repository->delete($id);

        self::assertNull($this->repository->findForEditing($id));
        self::assertSame([], $this->tagIdsOf($id));
        self::assertSame(self::SEEDED_ARTICLES, $this->articleCount());
        self::assertSame($linksBefore - 2, $this->linkCount());
    }

    // ---------------------------------------------------------------- AC 32: seznam

    public function test_count_all_includes_every_status(): void
    {
        self::assertSame(self::SEEDED_ARTICLES, $this->repository->countAll());

        $this->repository->create($this->data(), $this->adminId, $this->now);

        self::assertSame(self::SEEDED_ARTICLES + 1, $this->repository->countAll());
    }

    public function test_list_returns_all_articles_ordered_by_update_with_names(): void
    {
        $id = $this->repository->create($this->data(), $this->adminId, $this->now);

        $articles = $this->repository->list(20, 0);

        self::assertCount(self::SEEDED_ARTICLES + 1, $articles);
        $statuses = [];
        foreach ($articles as $index => $article) {
            self::assertNotSame('', $article->categoryName);
            $statuses[$article->status->value] = true;
            if ($index > 0) {
                $previous = $articles[$index - 1];
                self::assertTrue(
                    $previous->updatedAt > $article->updatedAt
                    || ($previous->updatedAt == $article->updatedAt && $previous->id > $article->id),
                    'Řazení musí být updated_at DESC, id DESC.',
                );
            }
            if ($article->id === $id) {
                self::assertSame('Administrátor', $article->updatedByName);
                self::assertSame('Technologie', $article->categoryName);
                self::assertSame('2026-10-03 12:00:00', $article->updatedAt->format('Y-m-d H:i:s'));
            } else {
                self::assertNull($article->updatedByName, 'Seedované články nemají editora.');
            }
        }
        self::assertSame(['archived', 'draft', 'published'], self::sortedKeys($statuses));
    }

    public function test_list_puts_later_updated_article_first(): void
    {
        $older = $this->repository->create($this->data(slug: 'starsi'), $this->adminId, new \DateTimeImmutable('2098-01-01 08:00:00', new \DateTimeZone('Europe/Prague')));
        $newer = $this->repository->create($this->data(slug: 'novejsi'), $this->adminId, new \DateTimeImmutable('2098-01-01 09:00:00', new \DateTimeZone('Europe/Prague')));

        $articles = $this->repository->list(2, 0);

        self::assertSame([$newer, $older], array_map(static fn($article): int => $article->id, $articles));
        self::assertSame(ArticleStatus::Draft, $articles[0]->status);
        self::assertSame('novejsi', $articles[0]->slug);
        self::assertNull($articles[0]->publishedAt);
    }

    public function test_list_respects_limit_and_offset(): void
    {
        self::assertCount(5, $this->repository->list(5, 0));
        self::assertCount(1, $this->repository->list(5, 15));
        self::assertSame([], $this->repository->list(5, 100));
    }

    // ---------------------------------------------------------------- AC 33: slugy

    public function test_taken_slugs_matches_base_and_suffixes_but_not_longer_words(): void
    {
        foreach (['novy-clanek', 'novy-clanek-2', 'novy-clanek-x', 'novy-clanekx'] as $slug) {
            $this->repository->create($this->data(slug: $slug), $this->adminId, $this->now);
        }

        $taken = $this->repository->takenSlugs('novy-clanek', null);

        self::assertContains('novy-clanek', $taken);
        self::assertContains('novy-clanek-2', $taken);
        self::assertNotContains('novy-clanekx', $taken);
        self::assertNotContains('prvni-clanek', $taken);
    }

    public function test_taken_slugs_skips_excepted_article(): void
    {
        $own = $this->repository->create($this->data(slug: 'novy-clanek'), $this->adminId, $this->now);
        $this->repository->create($this->data(slug: 'novy-clanek-2'), $this->adminId, $this->now);

        $taken = $this->repository->takenSlugs('novy-clanek', $own);

        self::assertNotContains('novy-clanek', $taken);
        self::assertContains('novy-clanek-2', $taken);
    }

    public function test_taken_slugs_of_unused_base_is_empty(): void
    {
        self::assertSame([], $this->repository->takenSlugs('neexistujici-zaklad', null));
    }

    public function test_create_with_existing_slug_throws_and_stores_nothing(): void
    {
        $articles = $this->articleCount();
        $links = $this->linkCount();

        try {
            $this->repository->create($this->data(slug: 'prvni-clanek'), $this->adminId, $this->now);
            self::fail('Očekávána výjimka SlugAlreadyTaken.');
        } catch (SlugAlreadyTaken) {
        }

        self::assertSame($articles, $this->articleCount());
        self::assertSame($links, $this->linkCount());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_update_to_existing_slug_throws_and_keeps_article(): void
    {
        $id = $this->repository->create($this->data(), $this->adminId, $this->now);

        try {
            $this->repository->update($id, $this->data(title: 'Změna', slug: 'prvni-clanek', tagSlugs: []), $this->adminId, $this->now);
            self::fail('Očekávána výjimka SlugAlreadyTaken.');
        } catch (SlugAlreadyTaken) {
        }

        self::assertSame('novy-clanek', $this->row($id)['slug']);
        self::assertSame('Nový článek', $this->row($id)['title']);
        self::assertCount(2, $this->tagIdsOf($id));
    }

    // ---------------------------------------------------------------- AC 34: bez N+1

    public function test_list_uses_single_select(): void
    {
        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->list(20, 0)));
    }

    public function test_count_all_uses_single_select(): void
    {
        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->countAll()));
    }

    public function test_find_for_editing_uses_at_most_two_selects(): void
    {
        $id = $this->repository->create($this->data(tagSlugs: ['php', 'docker', 'bezpecnost']), $this->adminId, $this->now);

        $selects = StatementCounter::selects($this->pdo, fn() => $this->repository->findForEditing($id));

        self::assertGreaterThanOrEqual(1, $selects);
        self::assertLessThanOrEqual(2, $selects);
    }

    public function test_taken_slugs_uses_single_select(): void
    {
        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->takenSlugs('novy-clanek', null)));
    }

    public function test_create_with_three_tags_uses_two_inserts(): void
    {
        $counts = StatementCounter::measure(
            $this->pdo,
            ['Com_insert'],
            fn() => $this->repository->create($this->data(tagSlugs: ['php', 'docker', 'bezpecnost']), $this->adminId, $this->now),
        );

        self::assertSame(2, $counts['Com_insert']);
    }

    public function test_update_statement_count_does_not_depend_on_tag_count(): void
    {
        $id = $this->repository->create($this->data(), $this->adminId, $this->now);

        foreach ([['php', 'docker', 'bezpecnost'], ['php'], []] as $tags) {
            $counts = StatementCounter::measure(
                $this->pdo,
                ['Com_update', 'Com_delete', 'Com_insert'],
                fn() => $this->repository->update($id, $this->data(tagSlugs: $tags), $this->adminId, $this->now),
            );

            self::assertSame(1, $counts['Com_update'], 'UPDATE pro ' . count($tags) . ' štítků');
            self::assertSame(1, $counts['Com_delete'], 'DELETE pro ' . count($tags) . ' štítků');
            self::assertLessThanOrEqual(1, $counts['Com_insert'], 'INSERT pro ' . count($tags) . ' štítků');
        }
    }

    /**
     * @param array<string, true> $keys
     * @return list<string>
     */
    private static function sortedKeys(array $keys): array
    {
        $result = array_keys($keys);
        sort($result);

        return $result;
    }
}
