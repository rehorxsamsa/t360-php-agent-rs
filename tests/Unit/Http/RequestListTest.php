<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Request;
use App\Http\Routing\RouteMatch;
use PHPUnit\Framework\TestCase;

/** Plán 005, AC 14: seznamy hodnot z formuláře (`tags[]`). */
final class RequestListTest extends TestCase
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
        $_SERVER['REQUEST_URI'] = '/admin/clanky/novy';
        $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
        $_POST = ['tags' => ['3', '5'], 'x' => [['vnořené']], 'y' => ['a' => 'b'], 'title' => 'T'];

        return Request::fromGlobals();
    }

    public function test_input_list_returns_string_list_from_post(): void
    {
        self::assertSame(['3', '5'], $this->fromGlobals()->inputList('tags'));
    }

    public function test_nested_array_is_dropped(): void
    {
        self::assertSame([], $this->fromGlobals()->inputList('x'));
    }

    public function test_keys_are_dropped(): void
    {
        self::assertSame(['b'], $this->fromGlobals()->inputList('y'));
    }

    public function test_missing_field_is_empty_list(): void
    {
        self::assertSame([], $this->fromGlobals()->inputList('chybi'));
    }

    public function test_scalar_field_is_not_a_list(): void
    {
        self::assertSame([], $this->fromGlobals()->inputList('title'));
        self::assertSame('T', $this->fromGlobals()->input('title'));
    }

    public function test_list_field_is_not_a_scalar_input(): void
    {
        self::assertSame('', $this->fromGlobals()->input('tags'));
    }

    public function test_mixed_list_with_non_string_item_is_dropped(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/clanky/novy';
        $_POST = ['tags' => ['1', ['2']]];

        self::assertSame([], Request::fromGlobals()->inputList('tags'));
    }

    /** Plán 007, AC 17: pole s neplatnou strukturou je „odeslané, ale bez platné hodnoty“, ne „neodeslané“. */
    public function test_nested_array_field_is_present_as_empty_list(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/ai/05';
        $_POST = ['model' => [['x']]];

        $request = Request::fromGlobals();

        self::assertArrayHasKey('model', $request->bodyLists);
        self::assertSame([], $request->bodyLists['model']);
        self::assertSame('', $request->input('model'));
        self::assertSame([], $request->inputList('model'));
    }

    public function test_mixed_list_field_is_present_as_empty_list(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/clanky/novy';
        $_POST = ['tags' => ['1', ['2']]];

        self::assertSame(['tags' => []], Request::fromGlobals()->bodyLists);
    }

    public function test_valid_lists_are_unchanged_next_to_invalid_ones(): void
    {
        self::assertSame(['tags' => ['3', '5'], 'x' => [], 'y' => ['b']], $this->fromGlobals()->bodyLists);
    }

    public function test_constructor_accepts_lists(): void
    {
        $request = new Request('POST', '/admin/clanky/novy', bodyLists: ['tags' => ['1', '2']]);

        self::assertSame(['1', '2'], $request->inputList('tags'));
    }

    public function test_with_route_keeps_lists(): void
    {
        $copy = $this->fromGlobals()->withRoute(new RouteMatch([\stdClass::class, 'bar'], ['id' => '5']));

        self::assertSame(['3', '5'], $copy->inputList('tags'));
        self::assertSame('5', $copy->routeParameter('id'));
        self::assertSame('T', $copy->input('title'));
    }

    public function test_with_route_parameters_keeps_lists(): void
    {
        $copy = $this->fromGlobals()->withRouteParameters(['id' => '5']);

        self::assertSame(['3', '5'], $copy->inputList('tags'));
    }
}
