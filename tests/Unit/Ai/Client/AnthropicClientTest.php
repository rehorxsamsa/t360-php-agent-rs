<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Client;

use App\Ai\Client\AnthropicClient;
use App\Ai\Client\HttpResult;
use App\Ai\Client\TransportFailed;
use App\Ai\LlmCallFailed;
use App\Ai\LlmErrorType;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\ScriptedHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 006, AC 4–6: AnthropicClient nad skriptovaným HttpTransport (bez sítě, zpoždění retry [0, 0]). */
final class AnthropicClientTest extends TestCase
{
    private const string KEY = 'sk-ant-api03-testovaci-klic-123';

    private ScriptedHttpTransport $transport;
    private string $errorLogFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->transport = new ScriptedHttpTransport();

        // error_log() z klienta nesmí zahlcovat výstup PHPUnitu; obsah logu se v testech kontroluje.
        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'anthropic-client-log-') ?: '';
        self::assertNotSame('', $this->errorLogFile);
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

    private function loggedErrors(): string
    {
        clearstatcache();

        return (string) file_get_contents($this->errorLogFile);
    }

    private function client(string $apiKey = self::KEY): AnthropicClient
    {
        return new AnthropicClient($this->transport, AiFixtures::catalog(), $apiKey, [0, 0]);
    }

    /**
     * @param list<array<string, mixed>>|null $content
     * @param array<string, int>|null $usage
     * @return array<string, mixed>
     */
    private static function okBody(
        ?array $content = null,
        string $stopReason = 'end_turn',
        ?array $usage = null,
        string $model = 'claude-sonnet-5-5',
    ): array {
        return [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => $content ?? [['type' => 'text', 'text' => 'Odpověď']],
            'stop_reason' => $stopReason,
            'usage' => $usage ?? ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    private static function ok(): HttpResult
    {
        return ScriptedHttpTransport::json(200, self::okBody(), ['request-id' => 'req_ok']);
    }

    /** @param array<string, string> $headers */
    private static function error(int $status, array $headers = []): HttpResult
    {
        return ScriptedHttpTransport::json(
            $status,
            ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Chyba ' . $status]],
            $headers + ['request-id' => 'req_err_' . $status],
        );
    }

    /**
     * Rekurzivně seřadí klíče asociativních polí (seznamy nechá v pořadí), aby šlo porovnat JSON
     * přes assertSame bez ohledu na pořadí klíčů.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function sentBody(int $index = 0): array
    {
        $decoded = json_decode($this->transport->requests[$index]['body'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function captureFailure(LlmRequest $request, ?AnthropicClient $client = null): LlmCallFailed
    {
        try {
            ($client ?? $this->client())->complete($request);
        } catch (LlmCallFailed $exception) {
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
            self::assertStringNotContainsString(self::KEY, $exception->getTraceAsString());

            return $exception;
        }

        self::fail('Očekávána výjimka LlmCallFailed.');
    }

    // ---------------------------------------------------------------- AC 4: tvar požadavku

    public function test_request_is_single_post_with_headers_and_exact_body(): void
    {
        $this->transport->push(self::ok());

        $this->client()->complete(AiFixtures::request());

        self::assertCount(1, $this->transport->requests);
        $sent = $this->transport->requests[0];
        self::assertSame('https://api.anthropic.com/v1/messages', $sent['url']);
        $headers = array_change_key_case($sent['headers']);
        self::assertSame(self::KEY, $headers['x-api-key'] ?? null);
        self::assertSame('2023-06-01', $headers['anthropic-version'] ?? null);
        self::assertSame('application/json', $headers['content-type'] ?? null);

        self::assertSame(self::canonical([
            'model' => 'claude-sonnet-5-5',
            'max_tokens' => 400,
            'system' => 'S',
            'messages' => [['role' => 'user', 'content' => 'U']],
            'output_config' => ['effort' => 'low'],
        ]), self::canonical($this->sentBody()));
    }

    public function test_request_never_sends_temperature_tool_choice_thinking_or_metadata(): void
    {
        $this->transport->push(self::ok());

        $this->client()->complete(AiFixtures::request());

        $body = $this->sentBody();
        foreach (['temperature', 'tool_choice', 'tools', 'thinking', 'exampleId', 'userId', 'example_id', 'user_id', 'metadata'] as $key) {
            self::assertArrayNotHasKey($key, $body, $key);
        }
        self::assertStringNotContainsString(self::KEY, $this->transport->requests[0]['body']);
    }

    /** Plán 012, AC 9: legacy Haiku 4.5 `effort` nezná, takže `output_config` chybí úplně. */
    public function test_legacy_haiku_does_not_get_effort_so_output_config_is_omitted(): void
    {
        $this->transport->push(self::ok());

        $this->client()->complete(AiFixtures::request(model: AiFixtures::LEGACY_HAIKU, effort: 'low'));

        self::assertArrayNotHasKey('output_config', $this->sentBody());
        self::assertSame(AiFixtures::LEGACY_HAIKU, $this->sentBody()['model']);
    }

    public function test_request_without_effort_has_no_output_config(): void
    {
        $this->transport->push(self::ok());

        $this->client()->complete(AiFixtures::request(effort: null));

        self::assertArrayNotHasKey('output_config', $this->sentBody());
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string', 'description' => 'Titulek']],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function test_json_schema_on_sonnet_goes_to_output_config_format_with_effort(): void
    {
        $this->transport->push(self::ok());

        $this->client()->complete(AiFixtures::request(jsonSchema: self::schema()));

        self::assertSame(
            self::canonical(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]]),
            self::canonical($this->sentBody()['output_config'] ?? null),
        );
    }

    /** Plán 012, AC 9. */
    public function test_json_schema_on_legacy_haiku_has_format_without_effort(): void
    {
        $this->transport->push(self::ok());

        $this->client()->complete(AiFixtures::request(model: AiFixtures::LEGACY_HAIKU, jsonSchema: self::schema()));

        self::assertSame(
            self::canonical(['format' => ['type' => 'json_schema', 'schema' => self::schema()]]),
            self::canonical($this->sentBody()['output_config'] ?? null),
        );
    }

    /**
     * Plán 012, AC 8: Haiku 5.5 `effort` podporuje, takže dostane `effort` i `format`. Parametry, na které
     * vrací 400 (`temperature`, `top_p`, `top_k`, `thinking`), ani `tool_choice` a `metadata` se neposílají.
     */
    public function test_haiku_5_5_gets_effort_and_json_schema_without_forbidden_parameters(): void
    {
        $this->transport->push(self::ok());

        $this->client()->complete(AiFixtures::request(model: AiFixtures::HAIKU, effort: 'low', jsonSchema: self::schema()));

        $body = $this->sentBody();
        self::assertSame('claude-haiku-5-5', $body['model'] ?? null);
        self::assertSame(
            self::canonical(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]]),
            self::canonical($body['output_config'] ?? null),
        );
        foreach (['temperature', 'top_p', 'top_k', 'thinking', 'tool_choice', 'metadata'] as $key) {
            self::assertArrayNotHasKey($key, $body, $key);
        }
        self::assertStringContainsString('"model":"claude-haiku-5-5"', $this->transport->requests[0]['body']);
    }

    public function test_cache_system_sends_system_as_text_block_with_ephemeral_cache_control(): void
    {
        $this->transport->push(self::ok());

        $this->client()->complete(AiFixtures::request(cacheSystem: true));

        self::assertSame(
            self::canonical([['type' => 'text', 'text' => 'S', 'cache_control' => ['type' => 'ephemeral']]]),
            self::canonical($this->sentBody()['system'] ?? null),
        );
    }

    // ---------------------------------------------------------------- AC 5: odpověď

    public function test_response_joins_text_blocks_ignores_thinking_and_maps_usage(): void
    {
        $this->transport->push(ScriptedHttpTransport::json(200, self::okBody(
            content: [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => 'A'],
                ['type' => 'text', 'text' => 'B'],
            ],
            usage: ['input_tokens' => 10, 'output_tokens' => 5, 'cache_creation_input_tokens' => 3, 'cache_read_input_tokens' => 2],
            model: 'claude-sonnet-5-5-z-odpovedi',
        ), ['request-id' => 'req_1']));

        $response = $this->client()->complete(AiFixtures::request());

        self::assertInstanceOf(LlmResponse::class, $response);
        self::assertSame('AB', $response->text);
        self::assertSame('claude-sonnet-5-5-z-odpovedi', $response->model);
        self::assertSame('end_turn', $response->stopReason);
        self::assertSame(
            [10, 5, 3, 2],
            [$response->usage->input, $response->usage->output, $response->usage->cacheWrite, $response->usage->cacheRead],
        );
        self::assertSame('anthropic', $response->provider);
        self::assertSame('req_1', $response->requestId);
        self::assertSame(1, $response->attempts);
        self::assertNull($response->costUsd);
    }

    public function test_missing_cache_fields_are_zero(): void
    {
        $this->transport->push(ScriptedHttpTransport::json(200, self::okBody(usage: ['input_tokens' => 7, 'output_tokens' => 3])));

        $response = $this->client()->complete(AiFixtures::request());

        self::assertSame(0, $response->usage->cacheWrite);
        self::assertSame(0, $response->usage->cacheRead);
        self::assertNull($response->requestId);
    }

    /** @return iterable<string, array{string}> */
    public static function nonErrorStopReasons(): iterable
    {
        yield 'refusal' => ['refusal'];
        yield 'max_tokens' => ['max_tokens'];
    }

    #[DataProvider('nonErrorStopReasons')]
    public function test_refusal_and_max_tokens_are_regular_responses(string $stopReason): void
    {
        $this->transport->push(ScriptedHttpTransport::json(200, self::okBody(stopReason: $stopReason)));

        $response = $this->client()->complete(AiFixtures::request());

        self::assertSame($stopReason, $response->stopReason);
    }

    // ---------------------------------------------------------------- AC 6: chyby a retry

    /** @return iterable<string, array{int, LlmErrorType}> */
    public static function nonRetryableStatuses(): iterable
    {
        yield '400' => [400, LlmErrorType::InvalidRequest];
        yield '401' => [401, LlmErrorType::Authentication];
        yield '402' => [402, LlmErrorType::Billing];
        yield '403' => [403, LlmErrorType::Permission];
        yield '404' => [404, LlmErrorType::InvalidRequest];
        yield '413' => [413, LlmErrorType::RequestTooLarge];
        yield '504' => [504, LlmErrorType::Timeout];
    }

    #[DataProvider('nonRetryableStatuses')]
    public function test_client_errors_and_504_fail_without_retry(int $status, LlmErrorType $type): void
    {
        $this->transport->push(self::error($status), self::ok());

        $exception = $this->captureFailure(AiFixtures::request());

        self::assertSame($type, $exception->type);
        self::assertSame($status, $exception->httpStatus);
        self::assertSame(1, $exception->attempts);
        self::assertSame('req_err_' . $status, $exception->requestId);
        self::assertSame($type->userMessage(), $exception->getMessage());
        self::assertCount(1, $this->transport->requests);
    }

    public function test_401_message_tells_to_check_api_key(): void
    {
        $this->transport->push(self::error(401));

        $exception = $this->captureFailure(AiFixtures::request());

        self::assertSame('AI odmítla API klíč (401). Zkontrolujte ANTHROPIC_API_KEY v .env.', $exception->getMessage());
    }

    public function test_429_with_short_retry_after_is_retried(): void
    {
        $this->transport->push(self::error(429, ['retry-after' => '1']), self::ok());

        $response = $this->client()->complete(AiFixtures::request());

        self::assertSame(2, $response->attempts);
        self::assertCount(2, $this->transport->requests);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function nonRetryable429(): iterable
    {
        yield 'without retry-after (spend limit)' => [[]];
        yield 'retry-after 30 s' => [['retry-after' => '30']];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('nonRetryable429')]
    public function test_429_without_or_with_long_retry_after_fails_immediately(array $headers): void
    {
        $this->transport->push(self::error(429, $headers), self::ok());

        $exception = $this->captureFailure(AiFixtures::request());

        self::assertSame(LlmErrorType::RateLimited, $exception->type);
        self::assertSame(429, $exception->httpStatus);
        self::assertSame(1, $exception->attempts);
        self::assertCount(1, $this->transport->requests);
    }

    /** @return iterable<string, array{int, LlmErrorType}> */
    public static function retryableStatuses(): iterable
    {
        yield '500' => [500, LlmErrorType::ServerError];
        yield '529' => [529, LlmErrorType::Overloaded];
    }

    #[DataProvider('retryableStatuses')]
    public function test_server_errors_are_retried_three_times_in_total(int $status, LlmErrorType $type): void
    {
        $this->transport->push(self::error($status), self::error($status), self::error($status), self::ok());

        $exception = $this->captureFailure(AiFixtures::request());

        self::assertSame($type, $exception->type);
        self::assertSame($status, $exception->httpStatus);
        self::assertSame(3, $exception->attempts);
        self::assertCount(3, $this->transport->requests);
    }

    public function test_529_then_success_returns_response_with_two_attempts(): void
    {
        $this->transport->push(self::error(529), self::ok());

        $response = $this->client()->complete(AiFixtures::request());

        self::assertSame(2, $response->attempts);
        self::assertSame('Odpověď', $response->text);
    }

    /** @return iterable<string, array{TransportFailed, LlmErrorType}> */
    public static function transportFailures(): iterable
    {
        yield 'timed out' => [AiFixtures::transportFailed(true), LlmErrorType::Timeout];
        yield 'connection' => [AiFixtures::transportFailed(false), LlmErrorType::Transport];
    }

    #[DataProvider('transportFailures')]
    public function test_transport_failure_is_not_retried(TransportFailed $failure, LlmErrorType $type): void
    {
        $this->transport->push($failure, self::ok());

        $exception = $this->captureFailure(AiFixtures::request());

        self::assertSame($type, $exception->type);
        self::assertNull($exception->httpStatus);
        self::assertSame(1, $exception->attempts);
        self::assertCount(1, $this->transport->requests);
    }

    /** @return iterable<string, array{HttpResult}> */
    public static function invalidResponses(): iterable
    {
        yield 'not json' => [ScriptedHttpTransport::raw(200, '<html>chyba</html>')];
        yield 'without content' => [ScriptedHttpTransport::json(200, ['model' => 'm', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])];
        yield 'without usage' => [ScriptedHttpTransport::json(200, ['model' => 'm', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'A']]])];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_200_response_is_invalid_response(HttpResult $result): void
    {
        $this->transport->push($result, self::ok());

        $exception = $this->captureFailure(AiFixtures::request());

        self::assertSame(LlmErrorType::InvalidResponse, $exception->type);
        self::assertSame(1, $exception->attempts);
        self::assertCount(1, $this->transport->requests);
    }

    public function test_error_log_contains_only_type_status_and_request_id(): void
    {
        $this->transport->push(ScriptedHttpTransport::json(
            401,
            ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'TAJNE-TELO-ODPOVEDI']],
            ['request-id' => 'req_log_1'],
        ));

        $this->captureFailure(AiFixtures::request(system: 'TAJNY-SYSTEM-PROMPT'));

        $log = $this->loggedErrors();
        self::assertSame(1, substr_count($log, 'AnthropicClient:'));
        self::assertStringContainsString(LlmErrorType::Authentication->value, $log);
        self::assertStringContainsString('HTTP 401', $log);
        self::assertStringContainsString('pokusů 1', $log);
        self::assertStringContainsString('request-id req_log_1', $log);
        foreach ([self::KEY, 'sk-ant', 'x-api-key', 'anthropic-version', 'TAJNE-TELO-ODPOVEDI', 'TAJNY-SYSTEM-PROMPT'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $log, $forbidden);
        }
    }

    public function test_error_log_for_transport_failure_has_no_status_and_no_key(): void
    {
        $this->transport->push(AiFixtures::transportFailed(true));

        $this->captureFailure(AiFixtures::request());

        $log = $this->loggedErrors();
        self::assertStringContainsString(LlmErrorType::Timeout->value, $log);
        self::assertStringContainsString('HTTP -', $log);
        self::assertStringContainsString('request-id -', $log);
        self::assertStringNotContainsString(self::KEY, $log);
        self::assertStringNotContainsString('x-api-key', $log);
    }

    public function test_successful_call_writes_nothing_to_error_log(): void
    {
        $this->transport->push(self::ok());

        $this->client()->complete(AiFixtures::request());

        self::assertSame('', $this->loggedErrors());
    }

    public function test_print_r_and_var_dump_do_not_reveal_api_key(): void
    {
        $client = $this->client();

        $printed = print_r($client, true);
        ob_start();
        var_dump($client);
        $dumped = (string) ob_get_clean();

        foreach ([$printed, $dumped] as $output) {
            self::assertStringNotContainsString(self::KEY, $output);
            self::assertStringNotContainsString('sk-ant', $output);
            self::assertStringContainsString('***', $output);
        }
    }

    public function test_debug_info_of_client_without_key_shows_empty_value(): void
    {
        self::assertStringNotContainsString('***', print_r($this->client(''), true));
    }

    public function test_empty_api_key_fails_before_calling_transport(): void
    {
        $exception = $this->captureFailure(AiFixtures::request(), $this->client(''));

        self::assertSame(LlmErrorType::Configuration, $exception->type);
        self::assertSame(0, $exception->attempts);
        self::assertNull($exception->httpStatus);
        self::assertSame('AI_PROVIDER=anthropic vyžaduje ANTHROPIC_API_KEY v .env.', $exception->getMessage());
        self::assertSame([], $this->transport->requests);
    }
}
