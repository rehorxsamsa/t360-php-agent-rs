<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Ai\AiCall;
use App\Domain\Ai\AiCallRepository;
use App\Domain\Ai\AiCallStatus;
use App\Domain\Ai\TokenUsage;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\PdoAiCallRepository;
use App\Tests\Integration\StatementCounter;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Plán 006, AC 21: log volání AI nad redakce_test (časy z objektu, cena na 6 míst, 1 dotaz na metodu). */
final class PdoAiCallRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoAiCallRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();
        $this->repository = new PdoAiCallRepository($this->pdo);
        $this->pdo->exec("INSERT INTO users (email, display_name, password_hash) VALUES ('admin@example.test', 'Admin', 'h')");
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    private static function at(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime, new \DateTimeZone('Europe/Prague'));
    }

    private function call(
        string $createdAt,
        TokenUsage $usage,
        float $cost,
        ?int $userId,
        AiCallStatus $status = AiCallStatus::Ok,
        ?string $errorType = null,
        string $exampleId = '01',
    ): AiCall {
        return new AiCall(
            createdAt: self::at($createdAt),
            userId: $userId,
            exampleId: $exampleId,
            provider: 'fake',
            model: 'claude-sonnet-5-5',
            usage: $usage,
            costUsd: $cost,
            durationMs: 1234,
            attempts: $status === AiCallStatus::Ok ? 1 : 3,
            status: $status,
            errorType: $errorType,
            stopReason: $status === AiCallStatus::Ok ? 'end_turn' : null,
            requestId: $status === AiCallStatus::Ok ? 'req_011CXyz' : null,
        );
    }

    private function addThreeCalls(): void
    {
        $this->repository->add($this->call('2026-10-02 23:59:59', new TokenUsage(5000, 1000, 0, 0), 0.02, $this->userId, exampleId: '05'));
        $this->repository->add($this->call(
            '2026-10-03 08:00:00.123456',
            new TokenUsage(100, 50, 2000, 3000),
            0.00315,
            null,
            AiCallStatus::Error,
            'rate_limited',
            '03',
        ));
        $this->repository->add($this->call('2026-10-03 11:00:00', new TokenUsage(1000, 500, 0, 0), 0.007, $this->userId));
    }

    public function test_implements_domain_interface(): void
    {
        self::assertInstanceOf(AiCallRepository::class, $this->repository);
    }

    public function test_add_stores_all_columns_with_exact_time_and_six_decimal_cost(): void
    {
        $this->addThreeCalls();

        $rows = TestDatabase::rows($this->pdo, 'SELECT * FROM ai_calls ORDER BY id');
        self::assertCount(3, $rows);

        self::assertSame('2026-10-02 23:59:59.000000', $rows[0]['created_at'], 'Čas z objektu (Europe/Prague), ne UTC z DB.');
        self::assertSame('2026-10-03 08:00:00.123456', $rows[1]['created_at']);
        self::assertSame('0.020000', $rows[0]['cost_usd']);
        self::assertSame('0.003150', $rows[1]['cost_usd']);
        self::assertSame('0.007000', $rows[2]['cost_usd']);

        $error = $rows[1];
        self::assertSame('', $error['user_id']);
        self::assertSame(1, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM ai_calls WHERE user_id IS NULL'));
        self::assertSame('03', $error['example_id']);
        self::assertSame('fake', $error['provider']);
        self::assertSame('claude-sonnet-5-5', $error['model']);
        self::assertSame(['100', '50', '2000', '3000'], [
            $error['input_tokens'],
            $error['output_tokens'],
            $error['cache_creation_input_tokens'],
            $error['cache_read_input_tokens'],
        ]);
        self::assertSame('1234', $error['duration_ms']);
        self::assertSame('3', $error['attempts']);
        self::assertSame('error', $error['status']);
        self::assertSame('rate_limited', $error['error_type']);
        self::assertSame(1, TestDatabase::count($this->pdo, 'SELECT COUNT(*) FROM ai_calls WHERE stop_reason IS NULL AND request_id IS NULL'));

        self::assertSame((string) $this->userId, $rows[2]['user_id']);
        self::assertSame('ok', $rows[2]['status']);
        self::assertSame('end_turn', $rows[2]['stop_reason']);
        self::assertSame('req_011CXyz', $rows[2]['request_id']);
    }

    public function test_usage_since_counts_only_calls_from_given_moment(): void
    {
        $this->addThreeCalls();

        $totals = $this->repository->usageSince(self::at('2026-10-03 00:00:00'));

        self::assertSame(2, $totals->calls);
        self::assertSame(100 + 50 + 2000 + 3000 + 1000 + 500, $totals->tokens);
        self::assertEqualsWithDelta(0.01015, $totals->costUsd, 1e-9);
    }

    public function test_usage_since_includes_call_exactly_at_midnight(): void
    {
        $this->repository->add($this->call('2026-10-03 00:00:00', new TokenUsage(10, 0), 0.0, null));
        $this->repository->add($this->call('2026-10-02 23:59:59.999999', new TokenUsage(99, 0), 0.0, null));

        $totals = $this->repository->usageSince(self::at('2026-10-03 00:00:00'));

        self::assertSame(1, $totals->calls);
        self::assertSame(10, $totals->tokens);
    }

    public function test_usage_since_without_calls_is_zero(): void
    {
        $totals = $this->repository->usageSince(self::at('2026-10-03 00:00:00'));

        self::assertSame(0, $totals->calls);
        self::assertSame(0, $totals->tokens);
        self::assertSame(0.0, $totals->costUsd);
    }

    public function test_recent_returns_newest_first_hydrated(): void
    {
        $this->addThreeCalls();

        $recent = $this->repository->recent(20);

        self::assertCount(3, $recent);
        self::assertSame(
            ['2026-10-03 11:00:00', '2026-10-03 08:00:00', '2026-10-02 23:59:59'],
            array_map(static fn(AiCall $call): string => $call->createdAt->format('Y-m-d H:i:s'), $recent),
        );

        $error = $recent[1];
        self::assertSame('Europe/Prague', $error->createdAt->getTimezone()->getName());
        self::assertSame('123456', $error->createdAt->format('u'));
        self::assertNull($error->userId);
        self::assertSame('03', $error->exampleId);
        self::assertSame([100, 50, 2000, 3000], [$error->usage->input, $error->usage->output, $error->usage->cacheWrite, $error->usage->cacheRead]);
        self::assertSame(0.00315, $error->costUsd);
        self::assertSame(1234, $error->durationMs);
        self::assertSame(3, $error->attempts);
        self::assertSame(AiCallStatus::Error, $error->status);
        self::assertSame('rate_limited', $error->errorType);
        self::assertNull($error->stopReason);
        self::assertNull($error->requestId);

        self::assertSame($this->userId, $recent[0]->userId);
        self::assertSame('req_011CXyz', $recent[0]->requestId);
        self::assertSame('end_turn', $recent[0]->stopReason);
    }

    public function test_recent_breaks_ties_by_id_desc_and_respects_limit(): void
    {
        $this->repository->add($this->call('2026-10-03 10:00:00', new TokenUsage(1, 0), 0.0, null, exampleId: '01'));
        $this->repository->add($this->call('2026-10-03 10:00:00', new TokenUsage(2, 0), 0.0, null, exampleId: '02'));
        $this->repository->add($this->call('2026-10-03 09:00:00', new TokenUsage(3, 0), 0.0, null, exampleId: '03'));

        $recent = $this->repository->recent(2);

        self::assertSame(['02', '01'], array_map(static fn(AiCall $call): string => $call->exampleId, $recent));
    }

    public function test_each_method_is_one_statement(): void
    {
        $this->addThreeCalls();

        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->usageSince(self::at('2026-10-03 00:00:00'))));
        self::assertSame(1, StatementCounter::selects($this->pdo, fn() => $this->repository->recent(20)));
        $insert = StatementCounter::measure(
            $this->pdo,
            ['Com_insert', 'Com_select'],
            fn() => $this->repository->add($this->call('2026-10-03 11:30:00', new TokenUsage(1, 1), 0.0, null)),
        );
        self::assertSame(['Com_insert' => 1, 'Com_select' => 0], $insert);
    }
}
