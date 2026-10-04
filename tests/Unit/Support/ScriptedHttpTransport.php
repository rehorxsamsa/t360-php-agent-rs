<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Ai\Client\HttpResult;
use App\Ai\Client\HttpTransport;
use App\Ai\Client\TransportFailed;

/**
 * Skriptovaný HTTP transport (plán 006, §5): vrací předem připravené odpovědi nebo vyhazuje
 * TransportFailed a zaznamenává každý požadavek. Prázdná fronta = chyba testu, nikdy síť.
 */
final class ScriptedHttpTransport implements HttpTransport
{
    /** @var list<HttpResult|TransportFailed> */
    private array $queue = [];

    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    public function push(HttpResult|TransportFailed ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    public function post(string $url, array $headers, string $body): HttpResult
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('Skriptovaný transport nemá další odpověď – test nesmí volat síť.');
        }
        if ($next instanceof TransportFailed) {
            throw $next;
        }

        return $next;
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
}
