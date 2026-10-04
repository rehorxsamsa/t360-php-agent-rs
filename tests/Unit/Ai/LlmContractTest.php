<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\LlmCallFailed;
use App\Ai\LlmErrorType;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Domain\Ai\TokenUsage;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 006, §2: kontrakt LlmRequest / LlmResponse / LlmErrorType / LlmCallFailed. */
final class LlmContractTest extends TestCase
{
    public function test_request_keeps_values_and_defaults(): void
    {
        $request = new LlmRequest('claude-sonnet-5-5', 'S', [['role' => 'user', 'content' => 'U']], 400, '01');

        self::assertSame('claude-sonnet-5-5', $request->model);
        self::assertSame('S', $request->system);
        self::assertSame([['role' => 'user', 'content' => 'U']], $request->messages);
        self::assertSame(400, $request->maxTokens);
        self::assertSame('01', $request->exampleId);
        self::assertNull($request->userId);
        self::assertNull($request->effort);
        self::assertNull($request->jsonSchema);
        self::assertFalse($request->cacheSystem);
    }

    public function test_request_accepts_boundaries_and_conversation_ending_with_user(): void
    {
        $request = new LlmRequest(
            'claude-sonnet-5-5',
            'S',
            [['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b'], ['role' => 'user', 'content' => 'c']],
            16000,
            '02',
            effort: 'high',
        );

        self::assertSame(16000, $request->maxTokens);
        self::assertSame(1, new LlmRequest('m', 'S', [['role' => 'user', 'content' => 'U']], 1, '01', effort: 'medium')->maxTokens);
    }

    /** @return iterable<string, array{\Closure(): LlmRequest}> */
    public static function invalidRequests(): iterable
    {
        yield 'max tokens 0' => [static fn(): LlmRequest => new LlmRequest('m', 'S', [['role' => 'user', 'content' => 'U']], 0, '01')];
        yield 'max tokens 16001' => [static fn(): LlmRequest => new LlmRequest('m', 'S', [['role' => 'user', 'content' => 'U']], 16001, '01')];
        yield 'no messages' => [static fn(): LlmRequest => new LlmRequest('m', 'S', [], 400, '01')];
        yield 'last message assistant' => [static fn(): LlmRequest => new LlmRequest(
            'm',
            'S',
            [['role' => 'user', 'content' => 'U'], ['role' => 'assistant', 'content' => 'A']],
            400,
            '01',
        )];
        yield 'unknown effort' => [static fn(): LlmRequest => new LlmRequest('m', 'S', [['role' => 'user', 'content' => 'U']], 400, '01', effort: 'max')];
    }

    /** @param \Closure(): LlmRequest $create */
    #[DataProvider('invalidRequests')]
    public function test_invalid_request_is_rejected(\Closure $create): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $create();
    }

    public function test_response_with_cost_returns_copy(): void
    {
        $response = AiFixtures::response('A', costUsd: null, requestId: 'req_1', attempts: 2);

        $priced = $response->withCost(0.007);

        self::assertNull($response->costUsd);
        self::assertSame(0.007, $priced->costUsd);
        self::assertSame('A', $priced->text);
        self::assertSame('req_1', $priced->requestId);
        self::assertSame(2, $priced->attempts);
    }

    public function test_response_defaults(): void
    {
        $response = new LlmResponse('A', 'm', 'end_turn', new TokenUsage(1, 1), 'fake');

        self::assertNull($response->requestId);
        self::assertSame(1, $response->attempts);
        self::assertNull($response->costUsd);
    }

    public function test_error_type_values_are_snake_case_for_ai_calls_error_type(): void
    {
        $expected = [
            'Configuration' => 'configuration',
            'Authentication' => 'authentication',
            'Billing' => 'billing',
            'Permission' => 'permission',
            'InvalidRequest' => 'invalid_request',
            'RequestTooLarge' => 'request_too_large',
            'RateLimited' => 'rate_limited',
            'ServerError' => 'server_error',
            'Overloaded' => 'overloaded',
            'Timeout' => 'timeout',
            'Transport' => 'transport',
            'InvalidResponse' => 'invalid_response',
        ];

        $actual = [];
        foreach (LlmErrorType::cases() as $case) {
            $actual[$case->name] = $case->value;
        }

        self::assertSame($expected, $actual);
    }

    public function test_every_error_type_has_czech_user_message(): void
    {
        foreach (LlmErrorType::cases() as $case) {
            self::assertNotSame('', trim($case->userMessage()), $case->name);
        }
        self::assertSame(
            'AI odmítla API klíč (401). Zkontrolujte ANTHROPIC_API_KEY v .env.',
            LlmErrorType::Authentication->userMessage(),
        );
        self::assertSame(
            'AI_PROVIDER=anthropic vyžaduje ANTHROPIC_API_KEY v .env.',
            LlmErrorType::Configuration->userMessage(),
        );
    }

    public function test_call_failed_carries_metadata_and_user_message(): void
    {
        $exception = AiFixtures::llmCallFailed(LlmErrorType::RateLimited, 429, 1, 'req_7');

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertInstanceOf(LlmCallFailed::class, $exception);
        self::assertSame(LlmErrorType::RateLimited, $exception->type);
        self::assertSame(429, $exception->httpStatus);
        self::assertSame(1, $exception->attempts);
        self::assertSame('req_7', $exception->requestId);
        self::assertSame(LlmErrorType::RateLimited->userMessage(), $exception->getMessage());
    }
}
