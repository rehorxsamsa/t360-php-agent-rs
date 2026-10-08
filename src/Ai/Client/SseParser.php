<?php

declare(strict_types=1);

namespace App\Ai\Client;

/**
 * Inkrementální parser Server-Sent Events (SSE) z kousků, které přicházejí z cURL.
 * Kousek se může zlomit kdekoli (i uprostřed řádku nebo mezi CR a LF): neúplný řádek i
 * neukončená událost zůstávají v bufferu do dalšího `push()`. Komentáře (`: …`) se ignorují,
 * víceřádkové `data:` se spojí znakem `\n`, ostatní pole (`id`, `retry`) se zahazují.
 */
final class SseParser
{
    private string $buffer = '';
    private string $event = '';
    /** @var list<string> */
    private array $data = [];

    /** @return list<array{event: string, data: string}> události dokončené tímto kouskem */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;

        // Koncové CR může být první půlkou CRLF: počká se na další kousek.
        $pendingCarriageReturn = str_ends_with($this->buffer, "\r");
        $text = $pendingCarriageReturn ? substr($this->buffer, 0, -1) : $this->buffer;
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

        // Poslední prvek je neúplný řádek (případně prázdný řetězec).
        $this->buffer = array_pop($lines) . ($pendingCarriageReturn ? "\r" : '');

        $events = [];
        foreach ($lines as $line) {
            $completed = $this->processLine($line);
            if ($completed !== null) {
                $events[] = $completed;
            }
        }

        return $events;
    }

    /** @return array{event: string, data: string}|null */
    private function processLine(string $line): ?array
    {
        if ($line === '') {
            $event = $this->data === [] ? null : ['event' => $this->event === '' ? 'message' : $this->event, 'data' => implode("\n", $this->data)];
            $this->event = '';
            $this->data = [];

            return $event;
        }

        if ($line[0] === ':') {
            return null;
        }

        $colon = strpos($line, ':');
        $field = $colon === false ? $line : substr($line, 0, $colon);
        $value = $colon === false ? '' : substr($line, $colon + 1);
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }

        if ($field === 'event') {
            $this->event = $value;
        } elseif ($field === 'data') {
            $this->data[] = $value;
        }

        return null;
    }
}
