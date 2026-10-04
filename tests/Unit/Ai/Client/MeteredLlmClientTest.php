<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Client;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiProvider;
use App\Ai\Client\MeteredLlmClient;
use App\Ai\LlmCallFailed;
use App\Ai\LlmErrorType;
use App\Domain\Ai\AiCallStatus;
use App\Domain\Ai\TokenUsage;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\ScriptedLlmClient;
use PHPUnit\Framework\TestCase;

/** Plán 006, AC 9–12: cena, log ai_calls a denní limit tokenů v dekorátoru (čas 2026-10-03 12:00). */
final class MeteredLlmClientTest extends TestCase
{
    private ScriptedLlmClient $inner;
    private InMemoryAiCallRepository $calls;

    protected function setUp(): void
    {
        $this->inner = new ScriptedLlmClient();
        $this->calls = new InMemoryAiCallRepository();
    }

    private function client(int $dailyTokenLimit = 200000): MeteredLlmClient
    {
        return new MeteredLlmClient(
            $this->inner,
            AiFixtures::catalog(),
            $this->calls,
            FixedClock::at('2026-10-03 12:00:00'),
            AiProvider::Fake,
            $dailyTokenLimit,
        );
    }

    // ---------------------------------------------------------------- AC 9

    public function test_successful_call_gets_cost_and_is_logged(): void
    {
        $this->inner->push(AiFixtures::response('Perex.', input: 1000, output: 500, costUsd: null, requestId: 'req_9'));

        $response = $this->client()->complete(AiFixtures::request(exampleId: '01', userId: 7));

        self::assertSame(0.007, $response->costUsd);
        self::assertSame('Perex.', $response->text);
        self::assertCount(1, $this->inner->requests);
        self::assertCount(1, $this->calls->calls);

        $call = $this->calls->calls[0];
        self::assertSame('2026-10-03 12:00:00', $call->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Prague', $call->createdAt->getTimezone()->getName());
        self::assertSame(7, $call->userId);
        self::assertSame('01', $call->exampleId);
        self::assertSame('fake', $call->provider);
        self::assertSame('claude-sonnet-5-5', $call->model);
        self::assertSame([1000, 500, 0, 0], [$call->usage->input, $call->usage->output, $call->usage->cacheWrite, $call->usage->cacheRead]);
        self::assertSame(0.007, $call->costUsd);
        self::assertGreaterThanOrEqual(0, $call->durationMs);
        self::assertSame(1, $call->attempts);
        self::assertSame(AiCallStatus::Ok, $call->status);
        self::assertNull($call->errorType);
        self::assertSame('end_turn', $call->stopReason);
        self::assertSame('req_9', $call->requestId);
    }

    public function test_console_call_is_logged_without_user(): void
    {
        $this->inner->push(AiFixtures::response('A'));

        $this->client()->complete(AiFixtures::request(userId: null));

        self::assertNull($this->calls->calls[0]->userId);
    }

    // ---------------------------------------------------------------- AC 10

    public function test_failed_call_is_rethrown_and_logged_as_error(): void
    {
        $this->inner->push(AiFixtures::llmCallFailed(LlmErrorType::RateLimited, 429, 1));

        try {
            $this->client()->complete(AiFixtures::request(exampleId: '02', userId: 7));
            self::fail('Očekávána výjimka LlmCallFailed.');
        } catch (LlmCallFailed $exception) {
            self::assertSame(LlmErrorType::RateLimited, $exception->type);
        }

        self::assertCount(1, $this->calls->calls);
        $call = $this->calls->calls[0];
        self::assertSame(AiCallStatus::Error, $call->status);
        self::assertSame('rate_limited', $call->errorType);
        self::assertSame(0, $call->usage->total());
        self::assertSame(0.0, $call->costUsd);
        self::assertSame(1, $call->attempts);
        self::assertSame('02', $call->exampleId);
        self::assertSame(7, $call->userId);
        self::assertSame('2026-10-03 12:00:00', $call->createdAt->format('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------- AC 11: denní limit

    private function seedUsage(): void
    {
        // Včerejší záznam se nepočítá, dnešní od půlnoci ano (100 + 200 + 150 + 150 = 600 tokenů).
        $this->calls->add(InMemoryAiCallRepository::call('2026-10-02 23:59:59', new TokenUsage(500, 0, 0, 0), 0.001));
        $this->calls->add(InMemoryAiCallRepository::call('2026-10-03 00:00:00', new TokenUsage(100, 200, 150, 150), 0.001));
    }

    public function test_call_within_daily_limit_proceeds(): void
    {
        $this->seedUsage();
        $this->inner->push(AiFixtures::response('A'));

        $this->client(1000)->complete(AiFixtures::request(maxTokens: 400));

        self::assertCount(1, $this->inner->requests);
        self::assertCount(3, $this->calls->calls);
    }

    public function test_call_over_daily_limit_is_refused_before_calling_model(): void
    {
        $this->seedUsage();
        $this->inner->push(AiFixtures::response('A'));

        try {
            $this->client(1000)->complete(AiFixtures::request(maxTokens: 401));
            self::fail('Očekávána výjimka AiBudgetExceeded.');
        } catch (AiBudgetExceeded $exception) {
            self::assertSame(
                'Denní limit AI tokenů (1 000) by byl překročen: dnes použito 600, požadavek si rezervuje až 401.'
                . ' Zkuste to zítra nebo zvyšte AI_DENNI_LIMIT_TOKENU.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->inner->requests);
        self::assertCount(2, $this->calls->calls);
    }

    // ---------------------------------------------------------------- AC 12

    public function test_model_outside_catalog_is_not_called_nor_logged(): void
    {
        $this->inner->push(AiFixtures::response('A'));

        try {
            $this->client()->complete(AiFixtures::request(model: 'x'));
            self::fail('Očekávána výjimka LlmCallFailed.');
        } catch (LlmCallFailed $exception) {
            self::assertSame(LlmErrorType::Configuration, $exception->type);
            self::assertSame('Model „x“ není v ceníku config/ai-models.php.', $exception->getMessage());
        }

        self::assertSame([], $this->inner->requests);
        self::assertSame([], $this->calls->calls);
    }
}
