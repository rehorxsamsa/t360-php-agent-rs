<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Domain\Health\DatabaseHealth;
use App\Http\Controller\HealthController;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
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

    private function kernel(bool $databaseReachable): Kernel
    {
        $health = new class ($databaseReachable) implements DatabaseHealth {
            public function __construct(private readonly bool $reachable) {}

            public function isReachable(): bool
            {
                return $this->reachable;
            }
        };

        return new Kernel(new HealthController($health));
    }

    private function request(string $method, string $uri): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;

        return Request::fromGlobals();
    }

    private function handle(bool $databaseReachable, string $method, string $uri): Response
    {
        return $this->kernel($databaseReachable)->handle($this->request($method, $uri));
    }

    /** @return array<string, string> hlavičky s malými písmeny v názvech */
    private function lowercaseHeaders(Response $response): array
    {
        $result = [];
        foreach ($response->headers as $name => $value) {
            $result[strtolower((string) $name)] = (string) $value;
        }

        return $result;
    }

    public function test_health_returns_200_and_ok_body_when_database_is_up(): void
    {
        $response = $this->handle(true, 'GET', '/zdravi');

        self::assertSame(200, $response->status);
        self::assertSame('{"stav":"ok","db":"ok"}', $response->body);
    }

    public function test_health_returns_503_and_error_body_when_database_is_down(): void
    {
        $response = $this->handle(false, 'GET', '/zdravi');

        self::assertSame(503, $response->status);
        self::assertSame('{"stav":"chyba","db":"chyba"}', $response->body);
    }

    public function test_health_ignores_query_string(): void
    {
        $response = $this->handle(true, 'GET', '/zdravi?x=1');

        self::assertSame(200, $response->status);
    }

    public function test_health_sends_json_and_security_headers(): void
    {
        $headers = $this->lowercaseHeaders($this->handle(true, 'GET', '/zdravi'));

        self::assertSame('application/json; charset=utf-8', $headers['content-type'] ?? null);
        self::assertSame('no-store', $headers['cache-control'] ?? null);
        self::assertSame('nosniff', $headers['x-content-type-options'] ?? null);
        self::assertArrayNotHasKey('x-powered-by', $headers);
    }

    public function test_error_response_also_sends_no_store_header(): void
    {
        $headers = $this->lowercaseHeaders($this->handle(false, 'GET', '/zdravi'));

        self::assertSame('no-store', $headers['cache-control'] ?? null);
    }

    public function test_unknown_path_returns_404_without_leaking_details(): void
    {
        $response = $this->handle(true, 'GET', '/neexistuje');

        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('Stack trace', $response->body);
        self::assertStringNotContainsString('.php', $response->body);
    }

    public function test_root_path_returns_404_in_milestone_one(): void
    {
        self::assertSame(404, $this->handle(true, 'GET', '/')->status);
    }

    public function test_post_to_health_returns_405_with_allow_get(): void
    {
        $response = $this->handle(true, 'POST', '/zdravi');

        self::assertSame(405, $response->status);
        self::assertSame('GET', $this->lowercaseHeaders($response)['allow'] ?? null);
    }

    public function test_other_methods_to_health_return_405(): void
    {
        foreach (['PUT', 'DELETE', 'PATCH'] as $method) {
            self::assertSame(405, $this->handle(true, $method, '/zdravi')->status, $method);
        }
    }

    public function test_unknown_path_with_post_returns_404_not_405(): void
    {
        self::assertSame(404, $this->handle(true, 'POST', '/neexistuje')->status);
    }

    public function test_health_body_never_leaks_connection_details(): void
    {
        $body = $this->handle(false, 'GET', '/zdravi')->body;

        self::assertStringNotContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString('redakce', $body);
    }
}
