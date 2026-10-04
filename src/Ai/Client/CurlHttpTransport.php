<?php

declare(strict_types=1);

namespace App\Ai\Client;

/**
 * HTTP přes cURL – jediné místo v aplikaci, které volá `curl_*`.
 * Jen HTTPS, bez následování přesměrování; ověřování TLS certifikátu zůstává ve výchozím (zapnutém) stavu.
 */
final readonly class CurlHttpTransport implements HttpTransport
{
    public function __construct(
        private int $connectTimeoutSeconds = 5,
        private int $timeoutSeconds = 90,
    ) {}

    public function post(string $url, #[\SensitiveParameter] array $headers, string $body): HttpResult
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportFailed('Nepodařilo se inicializovat cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    // Nový blok hlaviček (např. po „100 Continue“) – začít od nuly.
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        $responseBody = curl_exec($handle);
        if ($responseBody === false || !is_string($responseBody)) {
            $errorNumber = curl_errno($handle);

            // Záměrně curl_strerror(), ne curl_error(): text neobsahuje URL ani data požadavku.
            throw new TransportFailed(
                sprintf('Chyba spojení (cURL %d): %s.', $errorNumber, curl_strerror($errorNumber) ?? 'neznámá chyba'),
                $errorNumber === CURLE_OPERATION_TIMEDOUT,
            );
        }

        return new HttpResult((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $responseBody);
    }
}
