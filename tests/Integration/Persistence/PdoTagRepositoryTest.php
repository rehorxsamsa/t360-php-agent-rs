<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Tag\Tag;
use App\Domain\Tag\TagRepository;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoTagRepository;
use App\Infrastructure\Seed\Seed;
use App\Tests\Integration\StatementCounter;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Štítky ze seedu (plán 005, AC 34–35). */
final class PdoTagRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoTagRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();

        $seed = require __DIR__ . '/../../../database/seeds/demo_content.php';
        self::assertInstanceOf(Seed::class, $seed);
        $seed->run($this->pdo);

        $this->repository = new PdoTagRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    public function test_implements_domain_interface(): void
    {
        self::assertInstanceOf(TagRepository::class, $this->repository);
    }

    public function test_all_returns_seeded_tags_in_czech_order(): void
    {
        $tags = $this->repository->all();

        self::assertSame(
            ['Bezpečnost', 'Docker', 'PHP', 'Přístupnost', 'Umělá inteligence'],
            array_map(static fn(Tag $t): string => $t->name, $tags),
        );
        self::assertSame('bezpecnost', $tags[0]->slug);
        self::assertSame(TestDatabase::count($this->pdo, "SELECT id FROM tags WHERE slug = 'bezpecnost'"), $tags[0]->id);
    }

    public function test_all_uses_single_select(): void
    {
        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->all()));
    }
}
