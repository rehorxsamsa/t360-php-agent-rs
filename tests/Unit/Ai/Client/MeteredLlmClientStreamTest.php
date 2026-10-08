<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Client;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiProvider;
use App\Ai\Client\MeteredLlmClient;
use App\Ai\LlmCallFailed;
use App\Ai\LlmClient;
use App\Ai\LlmErrorType;
use App\Domain\Ai\AiCallStatus;
use App\Domain\Ai\TokenUsage;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\ScriptedLlmClient;
use App\Tests\Unit\Support\ScriptedStreamingLlmClient;
use PHPUnit\Framework\TestCase;

/** Plán 008, AC 12: limit, cena a záznam do ai_calls i pro `stream()` (čas 2026-10-04 12:00). */
final class MeteredLlmClientStreamTest extends TestCase
{
    private ScriptedStreamingLlmClient $inner;
    private InMemoryAiCallRepository $calls;

    /** @var list<string> */
    private array $deltas = [];

    protected function setUp(): void
    {
        $this->inner = new ScriptedStreamingLlmClient();
        $this->calls = new InMemoryAiCallRepository();
        $this->deltas = [];
    }

    private function client(int $dailyTokenLimit = 200000, ?LlmClient $inner = null): MeteredLlmClient
    {
        return new MeteredLlmClient(
            $inner ?? $this->inner,
            AiFixtures::catalog(),
            $this->calls,
            FixedClock::at('2026-10-04 12:00:00'),
            AiProvider::Fake,
            $dailyTokenLimit,
        );
    }

    private function collector(?int $abortAfter = null): \Closure
    {
        return function (string $delta) use ($abortAfter): bool {
            $this->deltas[] = $delta;

            return $abortAfter === null || count($this->deltas) < $abortAfter;
        };
    }

    public function test_stream_passes_deltas_unchanged_prices_and_logs_call(): void
    {
        $this->inner->push(['Ahoj', ' světe', '!'], AiFixtures::response('Ahoj světe!', input: 1000, output: 500, costUsd: null, requestId: 'req_s'));

        $response = $this->client()->stream(AiFixtures::request(exampleId: '06', userId: 7, maxTokens: 1000), $this->collector());

        self::assertSame(['Ahoj', ' světe', '!'], $this->deltas);
        self::assertSame('Ahoj světe!', $response->text);
        self::assertSame(0.007, $response->costUsd);
        self::assertCount(1, $this->inner->requests);
        self::assertCount(1, $this->calls->calls);

        $call = $this->calls->calls[0];
        self::assertSame('2026-10-04 12:00:00', $call->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(7, $call->userId);
        self::assertSame('06', $call->exampleId);
        self::assertSame('fake', $call->provider);
        self::assertSame('claude-sonnet-5-5', $call->model);
        self::assertSame([1000, 500, 0, 0], [$call->usage->input, $call->usage->output, $call->usage->cacheWrite, $call->usage->cacheRead]);
        self::assertSame(0.007, $call->costUsd);
        self::assertSame(AiCallStatus::Ok, $call->status);
        self::assertNull($call->errorType);
        self::assertSame('end_turn', $call->stopReason);
        self::assertSame('req_s', $call->requestId);
    }

    public function test_aborted_stream_is_logged_ok_with_aborted_stop_reason_and_estimated_cost(): void
    {
        // Skriptovaný klient vrátí při přerušení odpověď se stopReason 'aborted' a usage odhadem (100 / 20).
        $this->inner->push(['Ahoj', ' světe', '!'], AiFixtures::response('Ahoj světe!', input: 100, output: 20, costUsd: null));

        $response = $this->client()->stream(AiFixtures::request(exampleId: '06', userId: 7), $this->collector(abortAfter: 1));

        self::assertSame(['Ahoj'], $this->deltas);
        self::assertSame('aborted', $response->stopReason);
        self::assertCount(1, $this->calls->calls);
        $call = $this->calls->calls[0];
        self::assertSame(AiCallStatus::Ok, $call->status);
        self::assertSame('aborted', $call->stopReason);
        self::assertSame([100, 20], [$call->usage->input, $call->usage->output]);
        // Sonnet 5.5: 2 USD / MTok vstup, 10 USD / MTok výstup.
        self::assertEqualsWithDelta(0.0004, $call->costUsd, 1e-9);
        self::assertEqualsWithDelta(0.0004, $response->costUsd ?? -1.0, 1e-9);
    }

    public function test_stream_over_daily_limit_is_refused_before_calling_inner_client(): void
    {
        $this->calls->add(InMemoryAiCallRepository::call('2026-10-04 08:00:00', new TokenUsage(500, 100), 0.001));
        $this->inner->pushText('Ahoj');

        try {
            $this->client(1000)->stream(AiFixtures::request(maxTokens: 401), $this->collector());
            self::fail('Očekávána výjimka AiBudgetExceeded.');
        } catch (AiBudgetExceeded $exception) {
            self::assertStringContainsString('Denní limit AI tokenů (1 000) by byl překročen', $exception->getMessage());
        }

        self::assertSame([], $this->inner->requests);
        self::assertSame([], $this->deltas);
        self::assertCount(1, $this->calls->calls, 'Odmítnuté volání se nezapisuje.');
    }

    public function test_failure_in_middle_of_stream_is_logged_as_error_and_rethrown(): void
    {
        $this->inner->push(['Ahoj'], AiFixtures::llmCallFailed(LlmErrorType::Overloaded, 200, 1, 'req_err'));

        try {
            $this->client()->stream(AiFixtures::request(exampleId: '06', userId: 7), $this->collector());
            self::fail('Očekávána výjimka LlmCallFailed.');
        } catch (LlmCallFailed $exception) {
            self::assertSame(LlmErrorType::Overloaded, $exception->type);
        }

        self::assertSame(['Ahoj'], $this->deltas);
        self::assertCount(1, $this->calls->calls);
        $call = $this->calls->calls[0];
        self::assertSame(AiCallStatus::Error, $call->status);
        self::assertSame('overloaded', $call->errorType);
        self::assertSame(0, $call->usage->total());
        self::assertSame(0.0, $call->costUsd);
        self::assertSame('06', $call->exampleId);
    }

    public function test_inner_client_without_streaming_is_logic_error(): void
    {
        $plain = new ScriptedLlmClient();
        $plain->pushText('Ahoj');

        $this->expectException(\LogicException::class);

        try {
            $this->client(inner: $plain)->stream(AiFixtures::request(), $this->collector());
        } finally {
            self::assertSame([], $plain->requests);
            self::assertSame([], $this->calls->calls);
        }
    }
}
