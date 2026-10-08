<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Stream\PhpStreamOutput;
use App\Http\Stream\StreamOutput;

final readonly class Response
{
    /**
     * Texty stavového řádku (RFC 9110) pro kódy, které aplikace vrací. PHP-FPM zná jen část z nich
     * (422 ne) a nginx pak pošle `HTTP/1.1 422 ` bez textu – proto ho aplikace posílá sama.
     *
     * @var array<int, string>
     */
    private const array REASON_PHRASES = [
        200 => 'OK',
        303 => 'See Other',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    /**
     * @param array<string, string>               $headers  název hlavičky => hodnota
     * @param (\Closure(StreamOutput): void)|null $producer streamovaná odpověď: spustí se až v `send()`,
     *                                                      tedy po průchodu middleware; `$body` se pak neposílá
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public ?\Closure $producer = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $extraHeaders
     */
    public static function json(array $data, int $status = 200, array $extraHeaders = []): self
    {
        return new self(
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ] + $extraHeaders,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    public static function text(string $body, int $status = 200, array $extraHeaders = []): self
    {
        return new self(
            $status,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ] + $extraHeaders,
            $body,
        );
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    public static function html(string $body, int $status = 200, array $extraHeaders = []): self
    {
        return new self(
            $status,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ] + $extraHeaders,
            $body,
        );
    }

    /**
     * Streamovaná odpověď (Server-Sent Events). Producent běží mimo middleware, takže výjimky
     * musí ošetřit sám (převést na SSE událost `error`). `X-Accel-Buffering: no` vypne bufferování
     * v nginx jen pro tuto odpověď.
     *
     * @param \Closure(StreamOutput): void $producer
     * @param array<string, string>        $extraHeaders
     */
    public static function stream(\Closure $producer, array $extraHeaders = []): self
    {
        return new self(
            200,
            [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Accel-Buffering' => 'no',
                'X-Content-Type-Options' => 'nosniff',
            ] + $extraHeaders,
            '',
            $producer,
        );
    }

    /**
     * Přesměrování (PRG): jen na interní cestu začínající jedním `/`, jinak by šlo o open redirect.
     *
     * @throws \InvalidArgumentException cíl není interní cesta
     */
    public static function redirect(string $location, int $status = 303): self
    {
        if (preg_match('~^/(?![/\\\\])[^\x00-\x1f\x7f]*\z~', $location) !== 1) {
            throw new \InvalidArgumentException('Přesměrovat lze jen na interní cestu začínající jedním "/".');
        }

        return new self($status, ['Location' => $location, 'Cache-Control' => 'no-store'], '');
    }

    /**
     * @param array<string, string> $headers nové hodnoty přepíší stávající stejného názvu
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, array_merge($this->headers, $headers), $this->body, $this->producer);
    }

    /** Text stavového řádku (`422` → `Unprocessable Content`); neznámý kód vrací ''. */
    public static function reasonPhrase(int $status): string
    {
        return self::REASON_PHRASES[$status] ?? '';
    }

    public function send(): void
    {
        $reason = self::reasonPhrase($this->status);
        if ($reason === '') {
            http_response_code($this->status);
        } else {
            // Z hlavičky `HTTP/1.1 …` převezme PHP-FPM text do `Status:` a nginx ho předá klientovi.
            header(sprintf('HTTP/1.1 %d %s', $this->status, $reason), true, $this->status);
        }
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->producer === null) {
            echo $this->body;

            return;
        }

        // Proud: nic nesmí zůstat v PHP bufferu (jinak by klient dostal vše naráz) a skript
        // musí doběhnout i po odpojení klienta, aby se volání LLM zalogovalo jako přerušené.
        while (ob_get_level() > 0) {
            if (!ob_end_flush()) {
                break;
            }
        }
        ignore_user_abort(true);
        flush();

        ($this->producer)(new PhpStreamOutput());
    }
}
