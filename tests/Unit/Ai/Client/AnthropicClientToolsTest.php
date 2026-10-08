<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Client;

use App\Ai\Client\AnthropicClient;
use App\Ai\LlmCallFailed;
use App\Ai\LlmErrorType;
use App\Ai\LlmRequest;
use App\Ai\ToolCall;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\ScriptedHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 008, AC 8–9: tool use v `AnthropicClient` – tělo požadavku a parsování odpovědi. */
final class AnthropicClientToolsTest extends TestCase
{
    private const string KEY = 'sk-ant-api03-testovaci-klic-tools';

    private ScriptedHttpTransport $transport;
    private string|false $previousErrorLog;
    private string $errorLogFile;

    protected function setUp(): void
    {
        $this->transport = new ScriptedHttpTransport();
        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'anthropic-tools-log-') ?: '';
        $this->previousErrorLog = ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
    }

    private function client(): AnthropicClient
    {
        return new AnthropicClient($this->transport, AiFixtures::catalog(), self::KEY, [0, 0]);
    }

    /** @return list<array<string, mixed>> */
    private static function tools(): array
    {
        return [
            [
                'name' => 'hledej_clanky',
                'description' => 'Vyhledá publikované články.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string', 'description' => 'Hledaný výraz']],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'bez_parametru',
                'description' => 'Nástroj bez parametrů.',
                'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ],
        ];
    }

    /** @return list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}> */
    private static function messages(): array
    {
        return [
            ['role' => 'user', 'content' => 'Q'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'hledej_clanky', 'input' => []],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"query":"docker","results":[]}'],
            ]],
        ];
    }

    private static function toolRequest(): LlmRequest
    {
        return new LlmRequest('claude-sonnet-5-5', 'S', self::messages(), 1024, '07', userId: 7, effort: 'low', tools: self::tools());
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function pushOk(array $content, string $stopReason = 'tool_use'): void
    {
        $this->transport->push(ScriptedHttpTransport::json(200, [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ], ['request-id' => 'req_tools']));
    }

    // ---------------------------------------------------------------- AC 8: tělo

    public function test_body_contains_tools_and_messages_unchanged_without_tool_choice(): void
    {
        $this->pushOk([['type' => 'text', 'text' => 'Hotovo.']], 'end_turn');

        $this->client()->complete(self::toolRequest());

        $raw = $this->transport->requests[0]['body'];
        $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(self::tools(), $body['tools'] ?? null);
        self::assertSame(self::messages(), $body['messages'] ?? null);
        self::assertArrayNotHasKey('tool_choice', $body);
        self::assertStringNotContainsString('tool_choice', $raw);
        self::assertStringNotContainsString('temperature', $raw);
    }

    public function test_empty_tool_input_and_empty_properties_are_encoded_as_json_objects(): void
    {
        $this->pushOk([['type' => 'text', 'text' => 'Hotovo.']], 'end_turn');

        $this->client()->complete(self::toolRequest());

        $raw = $this->transport->requests[0]['body'];
        self::assertStringContainsString('"input":{}', $raw);
        self::assertStringNotContainsString('"input":[]', $raw);
        self::assertStringContainsString('"properties":{}', $raw);
        self::assertStringNotContainsString('"properties":[]', $raw);
        self::assertStringContainsString('"signature":"sig"', $raw);
    }

    public function test_non_empty_tool_input_is_sent_unchanged(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Q'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'hledej_clanky', 'input' => ['query' => 'docker']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{}'],
            ]],
        ];
        $this->pushOk([['type' => 'text', 'text' => 'Hotovo.']], 'end_turn');

        $this->client()->complete(new LlmRequest('claude-sonnet-5-5', 'S', $messages, 1024, '07', tools: self::tools()));

        self::assertStringContainsString('"input":{"query":"docker"}', $this->transport->requests[0]['body']);
    }

    // ---------------------------------------------------------------- AC 9: odpověď

    public function test_tool_use_response_has_text_tool_calls_and_raw_content(): void
    {
        $content = [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'Hledám.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'hledej_clanky', 'input' => ['query' => 'docker']],
        ];
        $this->pushOk($content);

        $response = $this->client()->complete(self::toolRequest());

        self::assertSame('Hledám.', $response->text);
        self::assertSame('tool_use', $response->stopReason);
        self::assertEquals([new ToolCall('toolu_1', 'hledej_clanky', ['query' => 'docker'])], $response->toolCalls);
        self::assertSame($content, $response->content);
        self::assertSame('anthropic', $response->provider);
        self::assertSame('req_tools', $response->requestId);
    }

    public function test_parallel_tool_calls_keep_order(): void
    {
        $this->pushOk([
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'hledej_clanky', 'input' => ['query' => 'docker']],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'nacti_clanek', 'input' => ['slug' => 'docker-pro-vyvojare']],
        ]);

        $response = $this->client()->complete(self::toolRequest());

        self::assertSame(['toolu_1', 'toolu_2'], array_map(static fn(ToolCall $call): string => $call->id, $response->toolCalls));
        self::assertSame('', $response->text);
    }

    public function test_tool_use_with_empty_input_object_is_valid(): void
    {
        $this->pushOk([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'bez_parametru', 'input' => new \stdClass()]]);

        $response = $this->client()->complete(self::toolRequest());

        self::assertEquals([new ToolCall('toolu_1', 'bez_parametru', [])], $response->toolCalls);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidToolUseBlocks(): iterable
    {
        yield 'missing id' => [['type' => 'tool_use', 'name' => 'hledej_clanky', 'input' => ['query' => 'x']]];
        yield 'missing name' => [['type' => 'tool_use', 'id' => 'toolu_1', 'input' => ['query' => 'x']]];
        yield 'input is string' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'hledej_clanky', 'input' => 'docker']];
        yield 'input is list' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'hledej_clanky', 'input' => ['docker']]];
        yield 'input missing' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'hledej_clanky']];
    }

    /** @param array<string, mixed> $block */
    #[DataProvider('invalidToolUseBlocks')]
    public function test_invalid_tool_use_block_is_invalid_response(array $block): void
    {
        $this->pushOk([$block]);

        try {
            $this->client()->complete(self::toolRequest());
            self::fail('Očekávána výjimka LlmCallFailed.');
        } catch (LlmCallFailed $exception) {
            self::assertSame(LlmErrorType::InvalidResponse, $exception->type);
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
    }
}
