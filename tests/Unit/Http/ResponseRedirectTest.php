<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseRedirectTest extends TestCase
{
    public function test_redirect_is_303_with_location_no_store_and_empty_body(): void
    {
        $response = Response::redirect('/admin');

        self::assertSame(303, $response->status);
        self::assertSame('/admin', $response->headers['Location'] ?? null);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame('', $response->body);
    }

    /** @return array<string, array{string}> */
    public static function externalOrRelativeLocations(): array
    {
        return [
            'absolute url' => ['https://zlo.cz'],
            'protocol relative' => ['//zlo.cz'],
            'relative path' => ['admin'],
            'empty' => [''],
        ];
    }

    #[DataProvider('externalOrRelativeLocations')]
    public function test_redirect_rejects_non_internal_location(string $location): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Response::redirect($location);
    }

    public function test_with_headers_adds_and_overrides_values(): void
    {
        $response = new Response(200, ['A' => '1', 'B' => '2'], 'x');

        $copy = $response->withHeaders(['B' => 'nove', 'C' => '3']);

        self::assertSame(['A' => '1', 'B' => 'nove', 'C' => '3'], $copy->headers);
        self::assertSame('x', $copy->body);
        self::assertSame(200, $copy->status);
    }
}
