<?php

declare(strict_types=1);

namespace App\Ai\Client;

/**
 * Nejmenší možné HTTP rozhraní, které `AnthropicClient` potřebuje. Díky němu se klient
 * testuje bez sítě (skriptovaný transport) a cURL je jen na jednom místě.
 */
interface HttpTransport
{
    /**
     * Odešle POST a vrátí odpověď bez ohledu na stavový kód (chyby 4xx/5xx nejsou výjimka).
     *
     * @param array<string, string> $headers
     * @throws TransportFailed spojení se nezdařilo nebo vypršel čas
     */
    public function post(string $url, #[\SensitiveParameter] array $headers, string $body): HttpResult;

    /**
     * Odešle POST a tělo úspěšné (2xx) odpovědi předává po kouscích do `$onChunk` (`HttpResult::$body` je pak
     * prázdné). Odpověď s jiným stavem se nestreamuje: vrátí se celé tělo jako u `post()`, aby šla namapovat chyba.
     * Vrátí-li callback `false`, přenos se ukončí a metoda se vrátí normálně (vyžádané ukončení není chyba).
     *
     * @param array<string, string> $headers
     * @param callable(string): bool $onChunk
     * @throws TransportFailed spojení se nezdařilo nebo vypršel čas
     */
    public function stream(string $url, #[\SensitiveParameter] array $headers, string $body, callable $onChunk): HttpResult;
}
