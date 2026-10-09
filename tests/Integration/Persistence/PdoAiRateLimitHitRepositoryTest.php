<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Ai\AiRateLimitHitRepository;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoAiRateLimitHitRepository;
use App\Tests\Integration\StatementCounter;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Plán 013, AC 23: záznamy rate limitu AI nad redakce_test – okno `created_at > since` po uživateli a kbelíku,
 * nejstarší čas s mikrosekundami, prázdné okno, CASCADE po smazání uživatele. Čas t0 = 2026-10-09 12:00:00.250000
 * (Europe/Prague, ADR-0007: sloupec plněný aplikací v zóně PHP).
 */
final class PdoAiRateLimitHitRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoAiRateLimitHitRepository $repository;
    private int $firstUser;
    private int $secondUser;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();
        $this->repository = new PdoAiRateLimitHitRepository($this->pdo);
        $this->firstUser = $this->insertUser('prvni@example.test');
        $this->secondUser = $this->insertUser('druhy@example.test');
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    private function insertUser(string $email): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (email, display_name, password_hash) VALUES (?, ?, ?)');
        $statement->execute([$email, 'Admin', 'h']);

        return (int) $this->pdo->lastInsertId();
    }

    private static function t0(int $plusSeconds = 0): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-09 12:00:00.250000', new \DateTimeZone('Europe/Prague'))
            ->modify(sprintf('%+d seconds', $plusSeconds));
    }

    /** Kontrakt AC 23: 3× (1, ai) v t0, t0+10, t0+20; 1× (1, ai_heavy); 1× (2, ai). */
    private function addContractHits(): void
    {
        $this->repository->add($this->firstUser, 'ai', self::t0());
        $this->repository->add($this->firstUser, 'ai', self::t0(10));
        $this->repository->add($this->firstUser, 'ai', self::t0(20));
        $this->repository->add($this->firstUser, 'ai_heavy', self::t0(10));
        $this->repository->add($this->secondUser, 'ai', self::t0(10));
    }

    public function test_implements_domain_interface(): void
    {
        self::assertInstanceOf(AiRateLimitHitRepository::class, $this->repository);
    }

    public function test_add_stores_row_with_microseconds_in_php_time_zone(): void
    {
        $this->repository->add($this->firstUser, 'ai', self::t0());

        self::assertSame(
            [['user_id' => (string) $this->firstUser, 'bucket' => 'ai', 'created_at' => '2026-10-09 12:00:00.250000']],
            array_map(
                static fn(array $row): array => array_map(static fn(mixed $v): string => (string) $v, $row),
                TestDatabase::rows($this->pdo, 'SELECT user_id, bucket, created_at FROM ai_rate_limit_hits'),
            ),
        );
    }

    public function test_add_converts_other_time_zone_to_php_time_zone(): void
    {
        $this->repository->add($this->firstUser, 'ai', self::t0()->setTimezone(new \DateTimeZone('UTC')));

        self::assertSame(
            ['2026-10-09 12:00:00.250000'],
            TestDatabase::column($this->pdo, 'SELECT created_at FROM ai_rate_limit_hits'),
        );
    }

    public function test_window_counts_only_user_bucket_and_newer_rows(): void
    {
        $this->addContractHits();

        $window = $this->repository->windowSince($this->firstUser, 'ai', self::t0(5));

        self::assertSame(2, $window->count);
        self::assertNotNull($window->oldest);
        self::assertSame('2026-10-09 12:00:10.250000', $window->oldest->format('Y-m-d H:i:s.u'));
        self::assertSame(self::t0(10)->format('U.u'), $window->oldest->format('U.u'), 'Stejný okamžik jako zapsaný.');
    }

    public function test_window_boundary_is_exclusive(): void
    {
        $this->addContractHits();

        $window = $this->repository->windowSince($this->firstUser, 'ai', self::t0(10));

        self::assertSame(1, $window->count, 'Záznam přesně v čase since se nepočítá (created_at > since).');
        self::assertSame('2026-10-09 12:00:20.250000', $window->oldest?->format('Y-m-d H:i:s.u'));
    }

    public function test_window_before_all_rows_counts_all_of_bucket(): void
    {
        $this->addContractHits();

        $window = $this->repository->windowSince($this->firstUser, 'ai', self::t0(-60));

        self::assertSame(3, $window->count);
        self::assertSame('2026-10-09 12:00:00.250000', $window->oldest?->format('Y-m-d H:i:s.u'));
        self::assertSame(1, $this->repository->windowSince($this->firstUser, 'ai_heavy', self::t0(-60))->count);
        self::assertSame(1, $this->repository->windowSince($this->secondUser, 'ai', self::t0(-60))->count);
    }

    public function test_empty_window_has_zero_count_and_no_oldest(): void
    {
        $this->addContractHits();

        foreach ([
            [$this->firstUser, 'ai', self::t0(20)],
            [$this->secondUser, 'ai_heavy', self::t0(-60)],
            [999999, 'ai', self::t0(-60)],
        ] as [$user, $bucket, $since]) {
            $window = $this->repository->windowSince($user, $bucket, $since);
            self::assertSame(0, $window->count);
            self::assertNull($window->oldest);
        }
    }

    public function test_window_since_in_other_time_zone_means_same_instant(): void
    {
        $this->addContractHits();

        $window = $this->repository->windowSince($this->firstUser, 'ai', self::t0(5)->setTimezone(new \DateTimeZone('UTC')));

        self::assertSame(2, $window->count);
    }

    public function test_each_method_uses_one_statement(): void
    {
        $insert = StatementCounter::measure(
            $this->pdo,
            ['Com_insert', 'Com_select'],
            fn() => $this->repository->add($this->firstUser, 'ai', self::t0()),
        );

        self::assertSame(['Com_insert' => 1, 'Com_select' => 0], $insert);
        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->windowSince($this->firstUser, 'ai', self::t0(-60))));
    }

    public function test_deleting_user_cascades_to_his_hits(): void
    {
        $this->addContractHits();

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->firstUser);

        self::assertSame(0, $this->repository->windowSince($this->firstUser, 'ai', self::t0(-60))->count);
        self::assertSame(0, $this->repository->windowSince($this->firstUser, 'ai_heavy', self::t0(-60))->count);
        self::assertSame(1, $this->repository->windowSince($this->secondUser, 'ai', self::t0(-60))->count);
        self::assertSame(1, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM ai_rate_limit_hits'));
    }
}
