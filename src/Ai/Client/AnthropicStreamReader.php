<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\LlmErrorType;
use App\Domain\Ai\TokenUsage;

/**
 * Skládá odpověď Claude API ze streamu SSE událostí (ADR-0008): kousky dat vstupují do `push()`,
 * ven jdou textové přírůstky. Pamatuje si spotřebu (`message_start`, pak kumulativní hodnoty z `message_delta`),
 * `stop_reason`, model a to, zda proud skončil `message_stop`. Chyba (událost `error`, neplatný JSON) se
 * zaznamená do `failure()` a další data se ignorují. Neznámé události (a `ping`) se ignorují.
 * Bloky `thinking` a `tool_use` se ve streamu nesledují (streamování s nástroji se nepodporuje).
 */
final class AnthropicStreamReader
{
    private SseParser $parser;
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private int $cacheWriteTokens = 0;
    private int $cacheReadTokens = 0;
    private ?string $stopReason = null;
    private ?string $model = null;
    private bool $stopped = false;
    private ?LlmErrorType $failure = null;

    public function __construct()
    {
        $this->parser = new SseParser();
    }

    /** @return list<string> textové přírůstky obsažené v kousku, v pořadí */
    public function push(string $chunk): array
    {
        $deltas = [];
        foreach ($this->parser->push($chunk) as $event) {
            if ($this->failure !== null || $this->stopped) {
                break;
            }

            $delta = $this->handle($event['event'], $event['data']);
            if ($delta !== null && $delta !== '') {
                $deltas[] = $delta;
            }
        }

        return $deltas;
    }

    /** Proud je kompletní: přišel `message_stop` a nic selhalo. */
    public function isComplete(): bool
    {
        return $this->stopped && $this->failure === null;
    }

    public function failure(): ?LlmErrorType
    {
        return $this->failure;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    /** Poslední známá spotřeba; `$outputFloor` je odhad výstupu, pod který se nejde (přerušený proud). */
    public function usage(int $outputFloor = 0): TokenUsage
    {
        return new TokenUsage(
            $this->inputTokens,
            max($this->outputTokens, $outputFloor),
            $this->cacheWriteTokens,
            $this->cacheReadTokens,
        );
    }

    private function handle(string $event, string $data): ?string
    {
        if ($event === 'ping' || !in_array($event, ['message_start', 'content_block_start', 'content_block_delta', 'message_delta', 'message_stop', 'error'], true)) {
            return null;
        }

        try {
            $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->failure = LlmErrorType::InvalidResponse;

            return null;
        }
        if (!is_array($payload)) {
            $this->failure = LlmErrorType::InvalidResponse;

            return null;
        }

        return match ($event) {
            'message_start' => $this->onMessageStart($payload),
            'content_block_start' => $this->onBlockStart($payload),
            'content_block_delta' => $this->onBlockDelta($payload),
            'message_delta' => $this->onMessageDelta($payload),
            'error' => $this->onError($payload),
            default => $this->onMessageStop(),
        };
    }

    /** @param array<mixed> $payload */
    private function onMessageStart(array $payload): null
    {
        $message = $payload['message'] ?? null;
        if (!is_array($message)) {
            $this->failure = LlmErrorType::InvalidResponse;

            return null;
        }

        if (is_string($message['model'] ?? null)) {
            $this->model = $message['model'];
        }
        $this->readUsage($message['usage'] ?? null);

        return null;
    }

    /** @param array<mixed> $payload */
    private function onBlockStart(array $payload): ?string
    {
        $block = $payload['content_block'] ?? null;
        if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
            return $block['text'];
        }

        return null;
    }

    /** @param array<mixed> $payload */
    private function onBlockDelta(array $payload): ?string
    {
        $delta = $payload['delta'] ?? null;
        // `thinking_delta`, `signature_delta` a `input_json_delta` se ignorují (nezobrazují se).
        if (is_array($delta) && ($delta['type'] ?? null) === 'text_delta' && is_string($delta['text'] ?? null)) {
            return $delta['text'];
        }

        return null;
    }

    /** @param array<mixed> $payload */
    private function onMessageDelta(array $payload): null
    {
        $delta = $payload['delta'] ?? null;
        if (is_array($delta) && is_string($delta['stop_reason'] ?? null)) {
            $this->stopReason = $delta['stop_reason'];
        }

        // Hodnoty v `message_delta` jsou kumulativní, takže přepisují ty z `message_start`.
        $this->readUsage($payload['usage'] ?? null);

        return null;
    }

    private function onMessageStop(): null
    {
        $this->stopped = true;

        return null;
    }

    /** @param array<mixed> $payload */
    private function onError(array $payload): null
    {
        $error = $payload['error'] ?? null;
        $type = is_array($error) && is_string($error['type'] ?? null) ? $error['type'] : '';

        $this->failure = match ($type) {
            'overloaded_error' => LlmErrorType::Overloaded,
            'rate_limit_error' => LlmErrorType::RateLimited,
            'api_error' => LlmErrorType::ServerError,
            default => LlmErrorType::InvalidResponse,
        };

        return null;
    }

    /** Přepíše jen hodnoty, které v `usage` jsou (a jsou nezáporná celá čísla). */
    private function readUsage(mixed $usage): void
    {
        if (!is_array($usage)) {
            return;
        }

        $input = $usage['input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;
        $cacheWrite = $usage['cache_creation_input_tokens'] ?? null;
        $cacheRead = $usage['cache_read_input_tokens'] ?? null;

        $this->inputTokens = is_int($input) && $input >= 0 ? $input : $this->inputTokens;
        $this->outputTokens = is_int($output) && $output >= 0 ? $output : $this->outputTokens;
        $this->cacheWriteTokens = is_int($cacheWrite) && $cacheWrite >= 0 ? $cacheWrite : $this->cacheWriteTokens;
        $this->cacheReadTokens = is_int($cacheRead) && $cacheRead >= 0 ? $cacheRead : $this->cacheReadTokens;
    }
}
