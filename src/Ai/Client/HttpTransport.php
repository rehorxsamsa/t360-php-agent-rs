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
}
