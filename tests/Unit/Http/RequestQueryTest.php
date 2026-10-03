<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Request;
use App\Http\Routing\RouteMatch;
use PHPUnit\Framework\TestCase;

final class RequestQueryTest extends TestCase
{
    /** @var array<mixed> */
    private array $getBackup = [];
    /** @var array<mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->getBackup = $_GET;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_SERVER = $this->serverBackup;
    }

    public function test_from_globals_reads_string_query_parameters_only(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/?strana=2&x[]=pole';
        $_GET = ['strana' => '2', 'x' => ['pole']];

        $request = Request::fromGlobals();

        self::assertSame('2', $request->queryParameter('strana'));
        self::assertSame('', $request->queryParameter('x'));
        self::assertSame('', $request->queryParameter('chybi'));
        self::assertSame('/', $request->path);
    }

    public function test_query_defaults_to_empty(): void
    {
        self::assertSame('', new Request('GET', '/')->queryParameter('strana'));
    }

    public function test_with_route_parameters_keeps_query(): void
    {
        $request = new Request('GET', '/clanek/x', query: ['strana' => '2']);

        self::assertSame('2', $request->withRouteParameters(['slug' => 'x'])->queryParameter('strana'));
    }

    public function test_with_route_keeps_query(): void
    {
        $request = new Request('GET', '/clanek/x', query: ['strana' => '2']);
        $route = new RouteMatch(['App\\Http\\Controller\\HomeController', 'index'], ['slug' => 'x']);

        self::assertSame('2', $request->withRoute($route)->queryParameter('strana'));
    }
}
