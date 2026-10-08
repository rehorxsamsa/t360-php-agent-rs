<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\LlmCallFailed;
use App\Ai\LlmClient;
use App\Ai\LlmErrorType;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\StreamingLlmClient;
use App\Ai\ToolCall;
use App\Domain\Ai\TokenUsage;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 006, §2: kontrakt LlmRequest / LlmResponse / LlmErrorType / LlmCallFailed.
 * Plán 008, AC 1–2: StreamingLlmClient, nástroje v LlmRequest, bloky obsahu a ToolCall v LlmResponse.
 */
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

    // ---------------------------------------------------------------- plán 008, AC 1–2: streaming a nástroje

    public function test_llm_client_still_has_only_complete(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            new \ReflectionClass(LlmClient::class)->getMethods(),
        );

        self::assertSame(['complete'], $methods);
    }

    public function test_streaming_client_extends_llm_client_with_stream(): void
    {
        $interface = new \ReflectionClass(StreamingLlmClient::class);
        $methods = array_map(static fn(\ReflectionMethod $method): string => $method->getName(), $interface->getMethods());
        sort($methods);

        self::assertTrue($interface->isInterface());
        self::assertTrue($interface->isSubclassOf(LlmClient::class));
        self::assertSame(['complete', 'stream'], $methods);
        $parameters = $interface->getMethod('stream')->getParameters();
        self::assertCount(2, $parameters);
        self::assertSame(LlmRequest::class, (string) $parameters[0]->getType());
        self::assertSame('callable', (string) $parameters[1]->getType());
        self::assertSame(LlmResponse::class, (string) $interface->getMethod('stream')->getReturnType());
    }

    public function test_request_without_tools_behaves_as_in_m6(): void
    {
        $request = new LlmRequest('claude-sonnet-5-5', 'S', [['role' => 'user', 'content' => 'U']], 400, '01');

        self::assertNull($request->tools);
        self::assertNull($request->withMessages([['role' => 'user', 'content' => 'V']])->tools);
    }

    /** @return list<array<string, mixed>> */
    private static function tools(): array
    {
        return [
            [
                'name' => 'hledej_clanky',
                'description' => 'Hledá v publikovaných článcích.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'nacti-clanek_2',
                'description' => 'Načte článek.',
                'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ],
        ];
    }

    /** @return list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}> */
    private static function toolConversation(): array
    {
        return [
            ['role' => 'user', 'content' => 'Q'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'hledej_clanky', 'input' => ['query' => 'docker']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"query":"docker","results":[]}'],
            ]],
        ];
    }

    public function test_request_accepts_tools_and_conversation_with_content_blocks(): void
    {
        $request = new LlmRequest('claude-sonnet-5-5', 'S', self::toolConversation(), 1024, '07', tools: self::tools());

        self::assertSame(self::tools(), $request->tools);
        self::assertSame(self::toolConversation(), $request->messages);
    }

    public function test_with_messages_keeps_tools(): void
    {
        $request = new LlmRequest('claude-sonnet-5-5', 'S', [['role' => 'user', 'content' => 'Q']], 1024, '07', effort: 'low', tools: self::tools());

        $next = $request->withMessages(self::toolConversation());

        self::assertSame(self::tools(), $next->tools);
        self::assertSame(self::toolConversation(), $next->messages);
        self::assertSame('low', $next->effort);
        self::assertSame('07', $next->exampleId);
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function invalidTools(): iterable
    {
        yield 'name with space' => [[['name' => 'hledej clanky', 'description' => 'D', 'input_schema' => ['type' => 'object']]]];
        yield 'empty name' => [[['name' => '', 'description' => 'D', 'input_schema' => ['type' => 'object']]]];
        yield 'name 129 chars' => [[['name' => str_repeat('a', 129), 'description' => 'D', 'input_schema' => ['type' => 'object']]]];
        yield 'empty definition' => [[[]]];
        yield 'definition without name' => [[['description' => 'D', 'input_schema' => ['type' => 'object']]]];
    }

    /** @param list<array<string, mixed>> $tools */
    #[DataProvider('invalidTools')]
    public function test_invalid_tool_definitions_are_rejected(array $tools): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LlmRequest('claude-sonnet-5-5', 'S', [['role' => 'user', 'content' => 'Q']], 1024, '07', tools: $tools);
    }

    public function test_conversation_with_blocks_must_still_end_with_user(): void
    {
        $messages = self::toolConversation();
        array_pop($messages);

        $this->expectException(\InvalidArgumentException::class);

        new LlmRequest('claude-sonnet-5-5', 'S', $messages, 1024, '07', tools: self::tools());
    }

    public function test_response_has_empty_content_and_tool_calls_by_default(): void
    {
        $response = new LlmResponse('A', 'm', 'end_turn', new TokenUsage(1, 1), 'fake');

        self::assertSame([], $response->content);
        self::assertSame([], $response->toolCalls);
    }

    public function test_with_cost_keeps_content_and_tool_calls(): void
    {
        $call = new ToolCall('toolu_1', 'hledej_clanky', ['query' => 'docker']);
        $response = AiFixtures::toolUseResponse([$call], 'Hledám.', costUsd: null);

        $priced = $response->withCost(0.002);

        self::assertSame(0.002, $priced->costUsd);
        self::assertSame($response->content, $priced->content);
        self::assertEquals([$call], $priced->toolCalls);
        self::assertSame('tool_use', $priced->stopReason);
    }

    public function test_tool_call_keeps_values(): void
    {
        $call = new ToolCall('toolu_1', 'hledej_clanky', ['query' => 'docker']);

        self::assertSame('toolu_1', $call->id);
        self::assertSame('hledej_clanky', $call->name);
        self::assertSame(['query' => 'docker'], $call->input);
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
