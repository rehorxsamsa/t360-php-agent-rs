<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Stream;

use App\Http\Stream\SseWriter;
use App\Tests\Unit\Support\BufferStreamOutput;
use PHPUnit\Framework\TestCase;

/** Plán 008, §3 „Kontrakt SSE do prohlížeče“: formát událostí na serveru. */
final class SseWriterTest extends TestCase
{
    public function test_comment_is_single_line_followed_by_blank_line(): void
    {
        $output = new BufferStreamOutput();

        new SseWriter($output)->comment('start');

        self::assertSame([": start\n\n"], $output->writes);
    }

    public function test_comment_cannot_inject_another_field(): void
    {
        $output = new BufferStreamOutput();

        new SseWriter($output)->comment("a\nevent: done\r\ndata: {}");

        self::assertSame(": a event: done data: {}\n\n", $output->body());
        self::assertSame([], $output->events());
    }

    public function test_event_writes_name_and_json_data_in_one_write(): void
    {
        $output = new BufferStreamOutput();

        $continue = new SseWriter($output)->event('delta', ['text' => 'Žluťoučký kůň / ok']);

        self::assertTrue($continue);
        self::assertSame(["event: delta\ndata: {\"text\":\"Žluťoučký kůň / ok\"}\n\n"], $output->writes);
    }

    public function test_line_breaks_in_text_stay_inside_json_on_one_data_line(): void
    {
        $output = new BufferStreamOutput();

        new SseWriter($output)->event('delta', ['text' => "první\n\nevent: done\r\ndruhý"]);

        $body = $output->body();
        self::assertSame(1, substr_count($body, "\n\n"), 'Rámec události končí jediným prázdným řádkem.');
        self::assertSame(1, substr_count($body, 'data: '));
        self::assertSame(
            [['event' => 'delta', 'data' => ['text' => "první\n\nevent: done\r\ndruhý"]]],
            $output->events(),
        );
    }

    public function test_html_in_text_is_only_json_data(): void
    {
        $output = new BufferStreamOutput();

        new SseWriter($output)->event('delta', ['text' => '<img src=x onerror=alert(1)>']);

        self::assertSame(
            [['event' => 'delta', 'data' => ['text' => '<img src=x onerror=alert(1)>']]],
            $output->events(),
        );
    }

    public function test_event_name_cannot_contain_line_break(): void
    {
        $output = new BufferStreamOutput();

        new SseWriter($output)->event("delta\ndata: x", ['text' => 'a']);

        self::assertStringStartsWith("event: delta data: x\ndata: ", $output->body());
    }

    public function test_invalid_utf8_is_substituted_instead_of_throwing(): void
    {
        $output = new BufferStreamOutput();

        new SseWriter($output)->event('delta', ['text' => "a\xC3b"]);

        self::assertSame([['event' => 'delta', 'data' => ['text' => "a\u{FFFD}b"]]], $output->events());
    }

    public function test_empty_data_is_json_object(): void
    {
        $output = new BufferStreamOutput();

        new SseWriter($output)->event('done', []);

        self::assertSame("event: done\ndata: {}\n\n", $output->body());
    }

    public function test_done_event_with_numbers_and_null(): void
    {
        $output = new BufferStreamOutput();

        new SseWriter($output)->event('done', [
            'stopReason' => 'end_turn',
            'inputTokens' => 25,
            'costUsd' => 0.007,
            'requestId' => null,
        ]);

        self::assertSame(
            "event: done\ndata: {\"stopReason\":\"end_turn\",\"inputTokens\":25,\"costUsd\":0.007,\"requestId\":null}\n\n",
            $output->body(),
        );
    }

    public function test_event_returns_false_once_client_disconnected(): void
    {
        $output = new BufferStreamOutput(abortAfterWrites: 2);
        $writer = new SseWriter($output);

        self::assertTrue($writer->event('delta', ['text' => 'a']));
        self::assertFalse($writer->event('delta', ['text' => 'b']));
        self::assertSame(0, $output->writesAfterAbort);
    }
}
