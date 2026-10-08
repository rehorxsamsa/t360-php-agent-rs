<?php

declare(strict_types=1);

namespace App\Http\Stream;

/**
 * Server-Sent Events do prohlížeče – jediné místo s formátem SSE na serveru.
 *
 * Kontrakt: `: komentář`, `event: delta|done|error` + `data: {JSON}`, každá událost končí prázdným
 * řádkem. Data jsou vždy JSON na jednom řádku, takže zalomení uvnitř textu rámec nerozbije.
 */
final readonly class SseWriter
{
    public function __construct(private StreamOutput $output) {}

    /** Komentář (řádek začínající `:`) – prohlížeč ho ignoruje, hodí se na úvodní odeslání hlaviček. */
    public function comment(string $text): void
    {
        $this->output->write(': ' . $this->singleLine($text) . "\n\n");
    }

    /**
     * Zapíše událost s daty v JSON. Neplatné UTF-8 se nahradí (výjimka by uprostřed proudu
     * neměla kam doputovat).
     *
     * @param array<string, mixed> $data
     *
     * @return bool false = klient se odpojil, producent má skončit
     */
    public function event(string $name, array $data): bool
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if ($json === false || $data === []) {
            // Prázdné pole by json_encode zapsal jako `[]`; data události jsou vždy objekt.
            $json = '{}';
        }

        $this->output->write('event: ' . $this->singleLine($name) . "\ndata: " . $json . "\n\n");

        return !$this->output->isAborted();
    }

    /** Název události ani komentář nesmí obsahovat konec řádku – jinak by šlo podvrhnout další pole. */
    private function singleLine(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n"], ' ', $text);
    }
}
