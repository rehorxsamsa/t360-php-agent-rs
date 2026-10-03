<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseHtmlTest extends TestCase
{
    public function test_html_response_has_html_content_type_and_security_headers(): void
    {
        $response = Response::html('<p>x</p>', 404, ['Allow' => 'GET']);

        self::assertSame(404, $response->status);
        self::assertSame('<p>x</p>', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers['Content-Type'] ?? null);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options'] ?? null);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
    }

    public function test_html_defaults_to_status_200(): void
    {
        self::assertSame(200, Response::html('x')->status);
    }
}
