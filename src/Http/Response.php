<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Response
{
    /**
     * @param array<string, string> $headers název hlavičky => hodnota
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
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
        return new self($this->status, array_merge($this->headers, $headers), $this->body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
