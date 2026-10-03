<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Category\Category;
use App\Domain\Category\CategoryRepository;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoCategoryRepository;
use App\Infrastructure\Seed\Seed;
use App\Tests\Integration\StatementCounter;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Rubriky ze seedu (plán 005, AC 34–35). */
final class PdoCategoryRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoCategoryRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();

        $seed = require __DIR__ . '/../../../database/seeds/demo_content.php';
        self::assertInstanceOf(Seed::class, $seed);
        $seed->run($this->pdo);

        $this->repository = new PdoCategoryRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    public function test_implements_domain_interface(): void
    {
        self::assertInstanceOf(CategoryRepository::class, $this->repository);
    }

    public function test_all_returns_seeded_categories_in_czech_order(): void
    {
        $categories = $this->repository->all();

        self::assertSame(['Technologie', 'Věda a výzkum', 'Zprávy'], array_map(static fn(Category $c): string => $c->name, $categories));
        self::assertSame(['technologie', 'veda-a-vyzkum', 'zpravy'], array_map(static fn(Category $c): string => $c->slug, $categories));
        $expectedId = TestDatabase::count($this->pdo, "SELECT id FROM categories WHERE slug = 'technologie'");
        self::assertSame($expectedId, $categories[0]->id);
    }

    public function test_all_uses_single_select(): void
    {
        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->all()));
    }
}
