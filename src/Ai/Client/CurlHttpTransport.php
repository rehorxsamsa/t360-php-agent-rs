<?php

declare(strict_types=1);

namespace App\Ai\Client;

/**
 * HTTP přes cURL – jediné místo v aplikaci, které volá `curl_*`.
 * Ve výchozím stavu jen HTTPS, bez následování přesměrování; ověřování TLS certifikátu zůstává ve výchozím (zapnutém) stavu.
 * Prostý HTTP povoluje jen volba `allowPlainHttp` – určená pro lokální službu uvnitř sítě Dockeru (Ollama, ADR-0009),
 * nikdy pro Claude API.
 */
final readonly class CurlHttpTransport implements HttpTransport
{
    /** Kolik bajtů chybové odpovědi streamu se uchová (stačí na zprávu chyby, chrání paměť). */
    private const int ERROR_BODY_LIMIT = 65536;

    public function __construct(
        private int $connectTimeoutSeconds = 5,
        private int $timeoutSeconds = 90,
        private bool $allowPlainHttp = false,
    ) {}

    public function post(string $url, #[\SensitiveParameter] array $headers, string $body): HttpResult
    {
        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

        $handle = $this->createHandle($url, $headers, $body, $responseHeaders);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

        $responseBody = curl_exec($handle);
        if ($responseBody === false || !is_string($responseBody)) {
            throw $this->transportFailure($handle);
        }

        return new HttpResult((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $responseBody);
    }

    public function stream(string $url, #[\SensitiveParameter] array $headers, string $body, callable $onChunk): HttpResult
    {
        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];
        $errorBody = '';
        $stoppedByCallback = false;

        $handle = $this->createHandle($url, $headers, $body, $responseHeaders);
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function (\CurlHandle $handle, string $data) use (
            $onChunk,
            &$errorBody,
            &$stoppedByCallback,
        ): int {
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($status < 200 || $status >= 300) {
                // Chybová odpověď se nestreamuje, jen se sesbírá, aby šla namapovat na typ chyby.
                $errorBody .= substr($data, 0, max(0, self::ERROR_BODY_LIMIT - strlen($errorBody)));

                return strlen($data);
            }

            if ($onChunk($data) === false) {
                $stoppedByCallback = true;

                // Menší návratová hodnota než strlen($data) přeruší přenos (CURLE_WRITE_ERROR).
                return 0;
            }

            return strlen($data);
        });

        $ok = curl_exec($handle);
        if ($ok === false && !($stoppedByCallback && curl_errno($handle) === CURLE_WRITE_ERROR)) {
            throw $this->transportFailure($handle);
        }

        return new HttpResult((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $errorBody);
    }

    /**
     * Společné nastavení pro `post()` i `stream()`.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $responseHeaders naplní se názvy hlaviček malými písmeny
     */
    private function createHandle(
        string $url,
        #[\SensitiveParameter]
        array $headers,
        string $body,
        array &$responseHeaders,
    ): \CurlHandle {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportFailed('Nepodařilo se inicializovat cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_PROTOCOLS => $this->allowPlainHttp ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS,
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

        return $handle;
    }

    private function transportFailure(\CurlHandle $handle): TransportFailed
    {
        $errorNumber = curl_errno($handle);

        // Záměrně curl_strerror(), ne curl_error(): text neobsahuje URL ani data požadavku.
        return new TransportFailed(
            sprintf('Chyba spojení (cURL %d): %s.', $errorNumber, curl_strerror($errorNumber) ?? 'neznámá chyba'),
            $errorNumber === CURLE_OPERATION_TIMEDOUT,
        );
    }
}
