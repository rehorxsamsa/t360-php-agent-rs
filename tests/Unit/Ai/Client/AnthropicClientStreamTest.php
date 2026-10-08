<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Client;

use App\Ai\Client\AnthropicClient;
use App\Ai\LlmCallFailed;
use App\Ai\LlmErrorType;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\ScriptedHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 008, AC 4–7: `AnthropicClient::stream()` nad skriptovaným `HttpTransport::stream()`
 * (bez sítě, zpoždění retry [0, 0]); kousky proudu se dělí uprostřed řádků i znaků.
 */
final class AnthropicClientStreamTest extends TestCase
{
    private const string KEY = 'sk-ant-api03-testovaci-klic-stream';

    private ScriptedHttpTransport $transport;
    private string $errorLogFile;
    private string|false $previousErrorLog;

    /** @var list<string> */
    private array $deltas = [];

    protected function setUp(): void
    {
        $this->transport = new ScriptedHttpTransport();
        $this->deltas = [];

        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'anthropic-stream-log-') ?: '';
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

    private function client(?ScriptedHttpTransport $transport = null): AnthropicClient
    {
        return new AnthropicClient($transport ?? $this->transport, AiFixtures::catalog(), self::KEY, [0, 0]);
    }

    /** Callback, který delty sbírá; s `$abortAfter` po N-té deltě vrátí `false`. */
    private function collector(?int $abortAfter = null): \Closure
    {
        return function (string $delta) use ($abortAfter): bool {
            $this->deltas[] = $delta;

            return $abortAfter === null || count($this->deltas) < $abortAfter;
        };
    }

    private function stream(LlmRequest $request, ?int $abortAfter = null): LlmResponse
    {
        return $this->client()->stream($request, $this->collector($abortAfter));
    }

    // ---------------------------------------------------------------- data proudu

    private static function messageStart(int $input = 25, int $output = 1, int $cacheRead = 3): string
    {
        return ScriptedHttpTransport::sse('message_start', [
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_01',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-5-5',
                'content' => [],
                'stop_reason' => null,
                'usage' => ['input_tokens' => $input, 'output_tokens' => $output, 'cache_read_input_tokens' => $cacheRead],
            ],
        ]);
    }

    private static function textDelta(string $text, int $index = 1): string
    {
        return ScriptedHttpTransport::sse('content_block_delta', [
            'type' => 'content_block_delta',
            'index' => $index,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]);
    }

    /** @param array<string, int> $usage */
    private static function messageDelta(string $stopReason = 'end_turn', array $usage = ['output_tokens' => 15]): string
    {
        return ScriptedHttpTransport::sse('message_delta', [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => $stopReason, 'stop_sequence' => null],
            'usage' => $usage,
        ]);
    }

    private static function messageStop(): string
    {
        return ScriptedHttpTransport::sse('message_stop', ['type' => 'message_stop']);
    }

    /** Proud z AC 5 až po druhou textovou deltu (bez závěru). */
    private static function streamHead(int $startOutput = 1): string
    {
        return self::messageStart(output: $startOutput)
            . ScriptedHttpTransport::sse('content_block_start', [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => ''],
            ])
            . ScriptedHttpTransport::sse('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'thinking_delta', 'thinking' => 'Přemýšlím…'],
            ])
            . ScriptedHttpTransport::sse('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'signature_delta', 'signature' => 'EqQBCgIYAhIM'],
            ])
            . ScriptedHttpTransport::sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0])
            . ScriptedHttpTransport::sse('content_block_start', [
                'type' => 'content_block_start',
                'index' => 1,
                'content_block' => ['type' => 'text', 'text' => ''],
            ])
            . ScriptedHttpTransport::sse('ping', ['type' => 'ping'])
            . self::textDelta('Ahoj')
            . ScriptedHttpTransport::sse('content_block_foo', ['type' => 'content_block_foo', 'index' => 1, 'foo' => ['bar' => 1]])
            . self::textDelta(' světe');
    }

    /** @param array<string, int> $finalUsage */
    private static function fullStream(array $finalUsage = ['output_tokens' => 15]): string
    {
        return self::streamHead()
            . ScriptedHttpTransport::sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 1])
            . self::messageDelta('end_turn', $finalUsage)
            . self::messageStop();
    }

    private static function errorEvent(string $type): string
    {
        return ScriptedHttpTransport::sse('error', ['type' => 'error', 'error' => ['type' => $type, 'message' => 'Chyba ' . $type]]);
    }

    private function captureFailure(LlmRequest $request): LlmCallFailed
    {
        try {
            $this->stream($request);
        } catch (LlmCallFailed $exception) {
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
            self::assertStringNotContainsString(self::KEY, $exception->getTraceAsString());
            clearstatcache();
            self::assertStringNotContainsString(self::KEY, (string) file_get_contents($this->errorLogFile));

            return $exception;
        }

        self::fail('Očekávána výjimka LlmCallFailed.');
    }

    /** Rekurzivně seřadí klíče asociativních polí (seznamy nechá v pořadí). */
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

    // ---------------------------------------------------------------- AC 4: tvar požadavku

    public function test_stream_sends_single_streaming_request_with_same_headers_and_body_as_complete_plus_stream_flag(): void
    {
        $request = AiFixtures::request(cacheSystem: true, user: 'Žluťoučký kůň');

        $plain = new ScriptedHttpTransport();
        $plain->push(ScriptedHttpTransport::json(200, [
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => 'A']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));
        $this->client($plain)->complete($request);

        $this->transport->pushStream(ScriptedHttpTransport::chunks(self::fullStream()));
        $this->stream($request);

        self::assertCount(1, $this->transport->requests);
        self::assertSame(1, $this->transport->streamCalls());
        $sent = $this->transport->requests[0];
        self::assertSame('https://api.anthropic.com/v1/messages', $sent['url']);
        self::assertSame($plain->requests[0]['headers'], $sent['headers']);
        self::assertSame(self::KEY, array_change_key_case($sent['headers'])['x-api-key'] ?? null);

        $expected = json_decode($plain->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);
        $expected['stream'] = true;
        $actual = json_decode($sent['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(self::canonical($expected), self::canonical($actual));
        self::assertStringNotContainsString(self::KEY, $sent['body']);
    }

    public function test_stream_with_tools_is_refused_without_calling_transport(): void
    {
        $request = new LlmRequest(
            'claude-sonnet-5-5',
            'S',
            [['role' => 'user', 'content' => 'Q']],
            400,
            '07',
            tools: [['name' => 'hledej_clanky', 'description' => 'D', 'input_schema' => ['type' => 'object', 'properties' => []]]],
        );

        try {
            $this->stream($request);
            self::fail('Očekávána výjimka LogicException.');
        } catch (\LogicException $exception) {
            self::assertSame('Streamování s nástroji není podporované.', $exception->getMessage());
        }

        self::assertSame([], $this->transport->requests);
        self::assertSame([], $this->deltas);
    }

    // ---------------------------------------------------------------- AC 5: úspěšný proud

    public function test_successful_stream_passes_only_text_deltas_and_builds_response(): void
    {
        $this->transport->pushStream(ScriptedHttpTransport::chunks(self::fullStream(), 7), ['request-id' => 'req_stream']);

        $response = $this->stream(AiFixtures::request());

        self::assertSame(['Ahoj', ' světe'], $this->deltas);
        self::assertSame('Ahoj světe', $response->text);
        self::assertSame('end_turn', $response->stopReason);
        self::assertSame('claude-sonnet-5-5', $response->model);
        self::assertSame([25, 15, 0, 3], [$response->usage->input, $response->usage->output, $response->usage->cacheWrite, $response->usage->cacheRead]);
        self::assertSame('anthropic', $response->provider);
        self::assertSame('req_stream', $response->requestId);
        self::assertSame(1, $response->attempts);
        self::assertNull($response->costUsd);
    }

    public function test_cumulative_input_tokens_in_message_delta_override_message_start(): void
    {
        $this->transport->pushStream(ScriptedHttpTransport::chunks(self::fullStream(['input_tokens' => 30, 'output_tokens' => 15]), 5));

        $response = $this->stream(AiFixtures::request());

        self::assertSame(30, $response->usage->input);
        self::assertSame(15, $response->usage->output);
    }

    public function test_stream_delivered_in_single_chunk_gives_same_result(): void
    {
        $this->transport->pushStream([self::fullStream()]);

        $response = $this->stream(AiFixtures::request());

        self::assertSame(['Ahoj', ' světe'], $this->deltas);
        self::assertSame('Ahoj světe', $response->text);
    }

    // ---------------------------------------------------------------- AC 6: přerušení

    public function test_abort_after_first_delta_stops_reading_and_returns_aborted_response(): void
    {
        $this->transport->pushStream(ScriptedHttpTransport::chunks(self::fullStream(), 20));

        $response = $this->stream(AiFixtures::request(), abortAfter: 1);

        self::assertSame(['Ahoj'], $this->deltas);
        self::assertLessThan($this->transport->preparedChunks[0], $this->transport->deliveredChunks[0], 'Transport musí přestat číst.');
        self::assertSame('aborted', $response->stopReason);
        self::assertSame('Ahoj', $response->text);
        self::assertSame(25, $response->usage->input);
        self::assertSame(max(1, intdiv(mb_strlen('Ahoj') + 3, 4)), $response->usage->output);
        self::assertSame('anthropic', $response->provider);
    }

    public function test_aborted_output_keeps_higher_last_known_value(): void
    {
        $this->transport->pushStream(ScriptedHttpTransport::chunks(self::streamHead(startOutput: 9) . self::messageDelta() . self::messageStop(), 20));

        $response = $this->stream(AiFixtures::request(), abortAfter: 1);

        self::assertSame('aborted', $response->stopReason);
        self::assertSame(9, $response->usage->output);
    }

    public function test_aborted_output_uses_estimate_when_it_is_higher(): void
    {
        $long = 'Dobrý den, toto je delší první přírůstek.';
        $stream = self::messageStart(output: 1)
            . self::textDelta($long)
            . self::textDelta(' Další.')
            . self::messageDelta()
            . self::messageStop();
        $this->transport->pushStream(ScriptedHttpTransport::chunks($stream, 11));

        $response = $this->stream(AiFixtures::request(), abortAfter: 1);

        self::assertSame($long, $response->text);
        self::assertSame(intdiv(mb_strlen($long) + 3, 4), $response->usage->output);
    }

    // ---------------------------------------------------------------- AC 7: chyby

    public function test_overloaded_before_stream_is_retried_and_only_third_attempt_is_streamed(): void
    {
        $this->transport
            ->pushStream(ScriptedHttpTransport::json(529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'x']]))
            ->pushStream(ScriptedHttpTransport::json(529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'x']]))
            ->pushStream(ScriptedHttpTransport::chunks(self::fullStream()));

        $response = $this->stream(AiFixtures::request());

        self::assertSame(3, $response->attempts);
        self::assertSame(3, $this->transport->streamCalls());
        self::assertSame(['Ahoj', ' světe'], $this->deltas);
        self::assertSame('Ahoj světe', $response->text);
    }

    public function test_authentication_error_before_stream_fails_like_complete(): void
    {
        $this->transport->pushStream(ScriptedHttpTransport::json(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'x']]));

        $failure = $this->captureFailure(AiFixtures::request());

        self::assertSame(LlmErrorType::Authentication, $failure->type);
        self::assertSame(401, $failure->httpStatus);
        self::assertSame(1, $this->transport->streamCalls());
        self::assertSame([], $this->deltas);
    }

    /** @return iterable<string, array{string, LlmErrorType}> */
    public static function streamErrors(): iterable
    {
        yield 'overloaded' => ['overloaded_error', LlmErrorType::Overloaded];
        yield 'rate limit' => ['rate_limit_error', LlmErrorType::RateLimited];
        yield 'api error' => ['api_error', LlmErrorType::ServerError];
        yield 'other' => ['invalid_request_error', LlmErrorType::InvalidResponse];
    }

    #[DataProvider('streamErrors')]
    public function test_error_event_in_middle_of_stream_fails_without_retry(string $errorType, LlmErrorType $expected): void
    {
        $stream = self::messageStart() . self::textDelta('Ahoj') . self::errorEvent($errorType) . self::textDelta(' světe') . self::messageStop();
        $this->transport->pushStream(ScriptedHttpTransport::chunks($stream, 9));
        $this->transport->pushStream(ScriptedHttpTransport::chunks(self::fullStream()));

        $failure = $this->captureFailure(AiFixtures::request());

        self::assertSame($expected, $failure->type);
        self::assertSame(1, $this->transport->streamCalls(), 'Chyba uprostřed proudu se nesmí opakovat.');
        self::assertSame(['Ahoj'], $this->deltas);
    }

    public function test_stream_without_message_stop_is_invalid_response(): void
    {
        $this->transport->pushStream(ScriptedHttpTransport::chunks(self::streamHead() . self::messageDelta()));

        $failure = $this->captureFailure(AiFixtures::request());

        self::assertSame(LlmErrorType::InvalidResponse, $failure->type);
        self::assertSame(1, $this->transport->streamCalls());
    }

    public function test_invalid_json_in_data_is_invalid_response(): void
    {
        $stream = self::messageStart() . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\n\n" . self::messageStop();
        $this->transport->pushStream(ScriptedHttpTransport::chunks($stream));

        $failure = $this->captureFailure(AiFixtures::request());

        self::assertSame(LlmErrorType::InvalidResponse, $failure->type);
        self::assertSame(1, $this->transport->streamCalls());
    }
}
