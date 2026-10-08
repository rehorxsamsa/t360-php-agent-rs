<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Stream\StreamOutput;
use App\Tests\Unit\Support\BufferStreamOutput;
use PHPUnit\Framework\TestCase;

/** Plán 008, §1 a §3: streamovaná odpověď (`Response::stream` + producent). */
final class ResponseStreamTest extends TestCase
{
    public function test_stream_response_has_sse_headers_empty_body_and_keeps_producer(): void
    {
        $producer = static function (StreamOutput $output): void {
            $output->write(": start\n\n");
        };

        $response = Response::stream($producer);

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame($producer, $response->producer);
        self::assertSame('text/event-stream; charset=utf-8', $response->headers['Content-Type'] ?? null);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame('no', $response->headers['X-Accel-Buffering'] ?? null);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options'] ?? null);
    }

    public function test_extra_headers_are_added_but_cannot_override_stream_headers(): void
    {
        $response = Response::stream(
            static function (StreamOutput $output): void {},
            ['X-Example' => 'ano', 'Content-Type' => 'text/html', 'X-Accel-Buffering' => 'yes'],
        );

        self::assertSame('ano', $response->headers['X-Example'] ?? null);
        self::assertSame('text/event-stream; charset=utf-8', $response->headers['Content-Type'] ?? null);
        self::assertSame('no', $response->headers['X-Accel-Buffering'] ?? null);
    }

    public function test_producer_writes_into_given_output_only_when_called(): void
    {
        $calls = 0;
        $response = Response::stream(static function (StreamOutput $output) use (&$calls): void {
            ++$calls;
            $output->write(": start\n\n");
            $output->write("event: done\ndata: {}\n\n");
        });
        self::assertSame(0, $calls, 'Producent se nesmí spustit při vytvoření odpovědi.');

        $output = new BufferStreamOutput();
        self::assertNotNull($response->producer);
        ($response->producer)($output);

        self::assertSame(1, $calls);
        self::assertSame(": start\n\nevent: done\ndata: {}\n\n", $output->body());
    }

    public function test_with_headers_preserves_producer_and_merges_headers(): void
    {
        $producer = static function (StreamOutput $output): void {};
        $response = Response::stream($producer)->withHeaders(['X-Frame-Options' => 'DENY']);

        self::assertSame($producer, $response->producer);
        self::assertSame('DENY', $response->headers['X-Frame-Options'] ?? null);
        self::assertSame('text/event-stream; charset=utf-8', $response->headers['Content-Type'] ?? null);
        self::assertSame(200, $response->status);
    }

    public function test_security_headers_middleware_keeps_stream_producer(): void
    {
        $producer = static function (StreamOutput $output): void {};

        $response = new SecurityHeadersMiddleware()->process(
            new Request('POST', '/admin/ai/06/proud'),
            static fn(Request $request): Response => Response::stream($producer),
        );

        self::assertSame($producer, $response->producer);
        self::assertSame('DENY', $response->headers['X-Frame-Options'] ?? null);
        self::assertArrayHasKey('Content-Security-Policy', $response->headers);
        self::assertSame('no', $response->headers['X-Accel-Buffering'] ?? null);
    }

    public function test_ordinary_responses_have_no_producer(): void
    {
        self::assertNull(Response::html('<p>x</p>')->producer);
        self::assertNull(Response::redirect('/admin')->producer);
        self::assertNull(Response::html('x')->withHeaders(['X-A' => 'b'])->producer);
        self::assertNull(new Response(204, [], '')->producer);
    }
}
