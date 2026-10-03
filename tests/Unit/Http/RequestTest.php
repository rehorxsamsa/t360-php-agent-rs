<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /** @var array<mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function test_path_is_taken_without_query_string(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/zdravi?foo=bar&x=1';

        $request = Request::fromGlobals();

        self::assertSame('/zdravi', $request->path);
    }

    public function test_path_without_query_string_stays_unchanged(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/clanek/muj-slug';

        self::assertSame('/clanek/muj-slug', Request::fromGlobals()->path);
    }

    public function test_root_path_with_query_string_is_slash(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/?page=2';

        self::assertSame('/', Request::fromGlobals()->path);
    }

    #[DataProvider('methodProvider')]
    public function test_method_is_uppercase(string $raw, string $expected): void
    {
        $_SERVER['REQUEST_METHOD'] = $raw;
        $_SERVER['REQUEST_URI'] = '/zdravi';

        self::assertSame($expected, Request::fromGlobals()->method);
    }

    /** @return iterable<string, array{string, string}> */
    public static function methodProvider(): iterable
    {
        yield 'already uppercase' => ['GET', 'GET'];
        yield 'lowercase' => ['post', 'POST'];
        yield 'mixed case' => ['Delete', 'DELETE'];
    }
}
