<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestRouteParametersTest extends TestCase
{
    public function test_with_route_parameters_returns_new_request_with_values(): void
    {
        $request = new Request('GET', '/clanek/x');
        $with = $request->withRouteParameters(['slug' => 'x']);

        self::assertSame('x', $with->routeParameter('slug'));
        self::assertSame('GET', $with->method);
        self::assertSame('/clanek/x', $with->path);
        self::assertNotSame($request, $with);
    }

    public function test_missing_route_parameter_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);

        new Request('GET', '/')->routeParameter('slug');
    }
}
