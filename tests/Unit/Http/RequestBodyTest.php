<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Request;
use App\Http\Routing\RouteMatch;
use PHPUnit\Framework\TestCase;

final class RequestBodyTest extends TestCase
{
    /** @var array<mixed> */
    private array $serverBackup = [];
    /** @var array<mixed> */
    private array $postBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_POST = $this->postBackup;
    }

    private function fromGlobals(): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/prihlaseni';
        $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
        $_POST = ['email' => 'a@b.cz', 'x' => ['pole']];

        return Request::fromGlobals();
    }

    public function test_input_returns_string_values_from_post(): void
    {
        self::assertSame('a@b.cz', $this->fromGlobals()->input('email'));
    }

    public function test_input_drops_non_string_values(): void
    {
        self::assertSame('', $this->fromGlobals()->input('x'));
    }

    public function test_input_returns_empty_string_for_missing_field(): void
    {
        self::assertSame('', $this->fromGlobals()->input('chybi'));
    }

    public function test_client_ip_comes_from_remote_addr(): void
    {
        self::assertSame('172.18.0.1', $this->fromGlobals()->clientIp);
    }

    public function test_client_ip_is_null_without_remote_addr(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        self::assertNull(Request::fromGlobals()->clientIp);
    }

    public function test_with_route_keeps_body_and_ip_and_sets_route_and_parameters(): void
    {
        $match = new RouteMatch([\stdClass::class, 'bar'], ['id' => '7']);

        $copy = $this->fromGlobals()->withRoute($match);

        self::assertSame($match, $copy->route);
        self::assertSame('7', $copy->routeParameter('id'));
        self::assertSame('a@b.cz', $copy->input('email'));
        self::assertSame('172.18.0.1', $copy->clientIp);
        self::assertSame('POST', $copy->method);
        self::assertSame('/admin/prihlaseni', $copy->path);
    }
}
