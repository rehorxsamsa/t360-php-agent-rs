<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Ai\Client\HttpResult;
use App\Ai\Client\HttpTransport;
use App\Ai\Client\TransportFailed;

/**
 * Skriptovaný HTTP transport (plán 006, §5; plán 008, §5): vrací předem připravené odpovědi nebo vyhazuje
 * TransportFailed a zaznamenává každý požadavek. Prázdná fronta = chyba testu, nikdy síť.
 *
 * `stream()` má vlastní frontu (`pushStream`): seznam kousků těla 2xx odpovědi (doručí se po jednom do
 * callbacku), nebo `HttpResult` s chybovým stavem (vrátí se celé), nebo `TransportFailed`. Kolik kousků
 * se skutečně doručilo, zaznamenává `$deliveredChunks` (callback vrátil `false` = transport přestane číst).
 */
final class ScriptedHttpTransport implements HttpTransport
{
    /** @var list<HttpResult|TransportFailed> */
    private array $queue = [];

    /** @var list<HttpResult|TransportFailed|array{chunks: list<string>, headers: array<string, string>}> */
    private array $streamQueue = [];

    /** @var list<array{url: string, headers: array<string, string>, body: string, stream: bool}> */
    public array $requests = [];

    /** @var list<int> počet kousků doručených callbacku při každém úspěšném volání stream() */
    public array $deliveredChunks = [];

    /** @var list<int> celkový počet připravených kousků při každém úspěšném volání stream() */
    public array $preparedChunks = [];

    public function push(HttpResult|TransportFailed ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    /**
     * Zařadí odpověď pro stream(): seznam kousků (2xx), nebo chybovou odpověď / selhání spojení.
     *
     * @param HttpResult|TransportFailed|list<string> $item
     * @param array<string, string> $headers hlavičky 2xx odpovědi (malými písmeny)
     */
    public function pushStream(HttpResult|TransportFailed|array $item, array $headers = []): self
    {
        $this->streamQueue[] = is_array($item) ? ['chunks' => $item, 'headers' => $headers] : $item;

        return $this;
    }

    public function post(string $url, array $headers, string $body): HttpResult
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'stream' => false];

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('Skriptovaný transport nemá další odpověď – test nesmí volat síť.');
        }
        if ($next instanceof TransportFailed) {
            throw $next;
        }

        return $next;
    }

    public function stream(string $url, array $headers, string $body, callable $onChunk): HttpResult
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'stream' => true];

        $next = array_shift($this->streamQueue);
        if ($next === null) {
            throw new \LogicException('Skriptovaný transport nemá další proud – test nesmí volat síť.');
        }
        if ($next instanceof TransportFailed) {
            throw $next;
        }
        if ($next instanceof HttpResult) {
            return $next;
        }

        $delivered = 0;
        foreach ($next['chunks'] as $chunk) {
            ++$delivered;
            if ($onChunk($chunk) === false) {
                break;
            }
        }
        $this->deliveredChunks[] = $delivered;
        $this->preparedChunks[] = count($next['chunks']);

        return new HttpResult(status: 200, headers: $next['headers'] + ['content-type' => 'text/event-stream'], body: '');
    }

    /** Počet volání stream() (včetně chybových odpovědí). */
    public function streamCalls(): int
    {
        return count(array_filter($this->requests, static fn(array $request): bool => $request['stream']));
    }

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers názvy malými písmeny (kontrakt HttpResult)
     */
    public static function json(int $status, array $body, array $headers = []): HttpResult
    {
        return new HttpResult(
            status: $status,
            headers: $headers + ['content-type' => 'application/json'],
            body: json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    /** @param array<string, string> $headers */
    public static function raw(int $status, string $body, array $headers = []): HttpResult
    {
        return new HttpResult(status: $status, headers: $headers, body: $body);
    }

    /**
     * Jedna SSE událost ve tvaru Claude API (`event: …`, `data: {JSON}`, prázdný řádek).
     *
     * @param array<mixed> $data
     */
    public static function sse(string $event, array $data): string
    {
        return 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    /**
     * Rozdělí proud na kousky po `$size` bajtech (dělí uprostřed řádků i vícebajtových znaků).
     *
     * @return list<string>
     */
    public static function chunks(string $stream, int $size = 7): array
    {
        return str_split($stream, max(1, $size));
    }
}
