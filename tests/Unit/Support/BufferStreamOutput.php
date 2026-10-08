<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Http\Stream\StreamOutput;

/**
 * Výstup proudu v paměti (plán 008, §5): sbírá zápisy; s `abortAfterWrites` hlásí po N-tém zápisu
 * `isAborted() = true` (klient zavřel spojení) a počítá zápisy, které přišly až po přerušení.
 */
final class BufferStreamOutput implements StreamOutput
{
    /** @var list<string> */
    public array $writes = [];

    public int $writesAfterAbort = 0;

    public function __construct(private readonly ?int $abortAfterWrites = null) {}

    public function write(string $chunk): void
    {
        if ($this->isAborted()) {
            ++$this->writesAfterAbort;
        }
        $this->writes[] = $chunk;
    }

    public function isAborted(): bool
    {
        return $this->abortAfterWrites !== null && count($this->writes) >= $this->abortAfterWrites;
    }

    public function body(): string
    {
        return implode('', $this->writes);
    }

    /**
     * Události SSE z výstupu (komentáře vynechané), data dekódovaná z JSON.
     *
     * @return list<array{event: string, data: mixed}>
     */
    public function events(): array
    {
        $events = [];
        foreach (explode("\n\n", $this->body()) as $block) {
            if ($block === '' || str_starts_with($block, ':')) {
                continue;
            }
            $event = 'message';
            $data = [];
            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $data[] = substr($line, 6);
                }
            }
            $events[] = ['event' => $event, 'data' => json_decode(implode("\n", $data), true)];
        }

        return $events;
    }
}
