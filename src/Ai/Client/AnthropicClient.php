<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\Cost\ModelCatalog;
use App\Ai\Cost\ModelInfo;
use App\Ai\Cost\UnknownModel;
use App\Ai\LlmCallFailed;
use App\Ai\LlmClient;
use App\Ai\LlmErrorType;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Domain\Ai\TokenUsage;

/**
 * Klient Claude Messages API přes cURL (ADR-0006). Žádná teplota, žádný vynucený nástroj:
 * strukturovaný výstup jde přes `output_config.format`, validuje ho až volající.
 *
 * Retry: 429 jen s `retry-after` do `$maxRetryAfterSeconds`, 500 a 529; nejvýše
 * `count($retryDelaysMs) + 1` pokusů. 4xx, 504 a vypršení času se neopakují.
 */
final readonly class AnthropicClient implements LlmClient
{
    public const string URL = 'https://api.anthropic.com/v1/messages';
    public const string API_VERSION = '2023-06-01';

    /**
     * @param list<int> $retryDelaysMs čekání před 2., 3., … pokusem (v testech `[0, 0]`)
     */
    public function __construct(
        private HttpTransport $transport,
        private ModelCatalog $catalog,
        #[\SensitiveParameter]
        private string $apiKey,
        private array $retryDelaysMs = [1000, 2000],
        private int $maxRetryAfterSeconds = 5,
    ) {}

    /**
     * Klíč se nesmí dostat do `var_dump` ani `print_r` (stejný přístup jako `AiConfig`).
     * `var_export` `__debugInfo()` nepoužívá – klienta nikdy nevypisuj ani tím.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'transport' => $this->transport,
            'catalog' => $this->catalog,
            'apiKey' => $this->apiKey === '' ? '' : '***',
            'retryDelaysMs' => $this->retryDelaysMs,
            'maxRetryAfterSeconds' => $this->maxRetryAfterSeconds,
        ];
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($this->apiKey === '') {
            throw new LlmCallFailed(LlmErrorType::Configuration, attempts: 0);
        }

        try {
            $model = $this->catalog->get($request->model);
        } catch (UnknownModel $exception) {
            throw new LlmCallFailed(LlmErrorType::Configuration, attempts: 0, message: $exception->getMessage());
        }

        $body = $this->encode($this->buildBody($request, $model));
        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];
        $maxAttempts = count($this->retryDelaysMs) + 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                $result = $this->transport->post(self::URL, $headers, $body);
            } catch (TransportFailed $exception) {
                $type = $exception->timedOut ? LlmErrorType::Timeout : LlmErrorType::Transport;

                throw $this->fail($type, null, $attempt, null);
            }

            $requestId = $result->headers['request-id'] ?? null;

            if ($result->status >= 200 && $result->status < 300) {
                return $this->parse($result, $request, $attempt, $requestId);
            }

            $waitMs = $attempt < $maxAttempts ? $this->retryWaitMs($result, $attempt) : null;
            if ($waitMs === null) {
                throw $this->fail($this->errorTypeFor($result->status), $result->status, $attempt, $requestId);
            }

            if ($waitMs > 0) {
                usleep($waitMs * 1000);
            }
        }
    }

    /** @return array<string, mixed> */
    private function buildBody(LlmRequest $request, ModelInfo $model): array
    {
        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->cacheSystem
                ? [['type' => 'text', 'text' => $request->system, 'cache_control' => ['type' => 'ephemeral']]]
                : $request->system,
            'messages' => array_map(
                static fn(array $message): array => ['role' => $message['role'], 'content' => $message['content']],
                $request->messages,
            ),
        ];

        $outputConfig = [];
        // Haiku 4.5 `effort` nezná (API by vrátilo 400), proto se posílá jen podle katalogu.
        if ($request->effort !== null && $model->supportsEffort) {
            $outputConfig['effort'] = $request->effort;
        }

        if ($request->jsonSchema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $request->jsonSchema];
        }

        if ($outputConfig !== []) {
            $body['output_config'] = $outputConfig;
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function encode(array $body): string
    {
        try {
            return json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (\JsonException) {
            throw new LlmCallFailed(LlmErrorType::InvalidRequest, attempts: 0);
        }
    }

    /**
     * Kolik ms čekat před dalším pokusem; null = neopakovat.
     */
    private function retryWaitMs(HttpResult $result, int $attempt): ?int
    {
        $scheduledMs = $this->retryDelaysMs[$attempt - 1] ?? 0;

        if ($result->status === 500 || $result->status === 529) {
            return $scheduledMs;
        }

        if ($result->status === 429) {
            // Bez `retry-after` jde o vyčerpaný limit útraty – čekání nepomůže.
            $retryAfter = $result->headers['retry-after'] ?? '';
            if (preg_match('/^[0-9]{1,6}$/', $retryAfter) !== 1 || (int) $retryAfter > $this->maxRetryAfterSeconds) {
                return null;
            }

            return max($scheduledMs, (int) $retryAfter * 1000);
        }

        return null;
    }

    private function errorTypeFor(int $status): LlmErrorType
    {
        return match (true) {
            $status === 401 => LlmErrorType::Authentication,
            $status === 402 => LlmErrorType::Billing,
            $status === 403 => LlmErrorType::Permission,
            $status === 413 => LlmErrorType::RequestTooLarge,
            $status === 429 => LlmErrorType::RateLimited,
            $status === 504 => LlmErrorType::Timeout,
            $status === 529 => LlmErrorType::Overloaded,
            $status >= 500 => LlmErrorType::ServerError,
            $status >= 400 => LlmErrorType::InvalidRequest,
            default => LlmErrorType::InvalidResponse,
        };
    }

    private function parse(HttpResult $result, LlmRequest $request, int $attempt, ?string $requestId): LlmResponse
    {
        try {
            $data = json_decode($result->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->fail(LlmErrorType::InvalidResponse, $result->status, $attempt, $requestId);
        }

        if (!is_array($data) || !is_array($data['content'] ?? null) || !is_array($data['usage'] ?? null)) {
            throw $this->fail(LlmErrorType::InvalidResponse, $result->status, $attempt, $requestId);
        }

        $usage = $this->parseUsage($data['usage']);
        if ($usage === null) {
            throw $this->fail(LlmErrorType::InvalidResponse, $result->status, $attempt, $requestId);
        }

        // Bloky `thinking` (a případné další typy) se ignorují, text se skládá jen z bloků `text`.
        $text = '';
        foreach ($data['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return new LlmResponse(
            $text,
            is_string($data['model'] ?? null) ? $data['model'] : $request->model,
            is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : 'unknown',
            $usage,
            'anthropic',
            $requestId,
            $attempt,
        );
    }

    /** @param array<mixed> $usage */
    private function parseUsage(array $usage): ?TokenUsage
    {
        $values = [];
        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $key) {
            $value = $usage[$key] ?? 0;
            if (!is_int($value) || $value < 0) {
                return null;
            }
            $values[$key] = $value;
        }

        // Chybějící `input_tokens`/`output_tokens` by znamenalo zdarma volání – odpověď bereme jako neplatnou.
        if (!isset($usage['input_tokens'], $usage['output_tokens'])) {
            return null;
        }

        return new TokenUsage(
            $values['input_tokens'],
            $values['output_tokens'],
            $values['cache_creation_input_tokens'],
            $values['cache_read_input_tokens'],
        );
    }

    private function fail(LlmErrorType $type, ?int $httpStatus, int $attempts, ?string $requestId): LlmCallFailed
    {
        // Do logu jen typ, status a request-id – nikdy klíč, hlavičky ani tělo.
        error_log(sprintf(
            'AnthropicClient: %s (HTTP %s, pokusů %d, request-id %s)',
            $type->value,
            $httpStatus === null ? '-' : (string) $httpStatus,
            $attempts,
            $requestId ?? '-',
        ));

        return new LlmCallFailed($type, $httpStatus, $attempts, $requestId);
    }
}
