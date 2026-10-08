<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Client;

use App\Ai\Client\SseParser;
use PHPUnit\Framework\TestCase;

/** Plán 008, AC 3: inkrementální parser Server-Sent Events z kousků cURL. */
final class SseParserTest extends TestCase
{
    public function test_events_split_across_chunks_comments_and_crlf(): void
    {
        $parser = new SseParser();

        $first = $parser->push("event: message_start\nda");
        $second = $parser->push("ta: {\"a\":1}\n\n: komentář\n\nevent: ping\r\ndata: {}\r\n\r\n");

        self::assertSame([], $first, 'Neúplná událost musí zůstat v bufferu.');
        self::assertSame(
            [
                ['event' => 'message_start', 'data' => '{"a":1}'],
                ['event' => 'ping', 'data' => '{}'],
            ],
            $second,
        );
    }

    public function test_multiline_data_is_joined_with_line_feed(): void
    {
        $parser = new SseParser();

        $events = $parser->push("event: delta\ndata: první\ndata: druhý\n\n");

        self::assertSame([['event' => 'delta', 'data' => "první\ndruhý"]], $events);
    }

    public function test_comment_only_block_produces_no_event(): void
    {
        $parser = new SseParser();

        self::assertSame([], $parser->push(": ping\n\n: další komentář\n\n"));
    }

    public function test_event_waits_for_terminating_blank_line(): void
    {
        $parser = new SseParser();

        self::assertSame([], $parser->push("event: message_stop\ndata: {}\n"));
        self::assertSame([['event' => 'message_stop', 'data' => '{}']], $parser->push("\n"));
    }

    public function test_crlf_split_between_chunks_is_one_line_break(): void
    {
        $parser = new SseParser();

        self::assertSame([], $parser->push("event: ping\r"));
        self::assertSame([], $parser->push("\ndata: {}\r"));
        self::assertSame([['event' => 'ping', 'data' => '{}']], $parser->push("\n\r\n"));
    }

    public function test_byte_by_byte_feeding_gives_same_events_as_whole_stream(): void
    {
        $stream = "event: message_start\ndata: {\"text\":\"Žluťoučký kůň\"}\n\n"
            . ": komentář\n\n"
            . "event: content_block_delta\r\ndata: {\"a\":1}\r\n\r\n"
            . "event: message_stop\ndata: {}\n\n";

        $whole = new SseParser()->push($stream);

        $parser = new SseParser();
        $pieces = [];
        foreach (str_split($stream) as $byte) {
            array_push($pieces, ...$parser->push($byte));
        }

        self::assertCount(3, $whole);
        self::assertSame($whole, $pieces);
        self::assertSame('{"text":"Žluťoučký kůň"}', $whole[0]['data']);
    }
}
