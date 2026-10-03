<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Container\Container;
use App\Domain\Health\DatabaseHealth;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\Router;
use App\Http\Session\Session;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Http\Fixtures\ThrowingController;
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

    private function container(bool $databaseReachable): Container
    {
        $health = new class ($databaseReachable) implements DatabaseHealth {
            public function __construct(private readonly bool $reachable) {}

            public function isReachable(): bool
            {
                return $this->reachable;
            }
        };

        /** @var Container $container */
        $container = require __DIR__ . '/../../../config/container.php';
        $container->set(DatabaseHealth::class, static fn(): DatabaseHealth => $health);
        // Nativní session se v testech nikdy nespouští.
        $container->set(Session::class, static fn(): Session => new ArraySession());

        return $container;
    }

    private function kernel(bool $databaseReachable): Kernel
    {
        return $this->container($databaseReachable)->get(Kernel::class);
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

    public function test_root_path_returns_200_html_homepage(): void
    {
        $response = $this->handle(true, 'GET', '/');
        $headers = $this->lowercaseHeaders($response);

        self::assertSame(200, $response->status);
        self::assertSame('text/html; charset=utf-8', $headers['content-type'] ?? null);
        self::assertStringContainsString('<html lang="cs">', $response->body);
        self::assertStringContainsString('Redakční systém', $response->body);
    }

    public function test_unknown_path_renders_czech_html_404_page_with_link_home(): void
    {
        $response = $this->handle(true, 'GET', '/neexistuje');
        $headers = $this->lowercaseHeaders($response);

        self::assertSame(404, $response->status);
        self::assertSame('text/html; charset=utf-8', $headers['content-type'] ?? null);
        self::assertStringContainsString('Stránka nenalezena', $response->body);
        self::assertStringContainsString('href="/"', $response->body);
    }

    public function test_post_to_health_renders_czech_html_405_page(): void
    {
        $response = $this->handle(true, 'POST', '/zdravi');

        self::assertStringContainsString('Metoda není povolena', $response->body);
    }

    public function test_controller_exception_returns_500_without_leaking_detail_and_logs_it(): void
    {
        $logFile = sys_get_temp_dir() . '/t360-kernel-' . bin2hex(random_bytes(4)) . '.log';
        $previous = ini_set('error_log', $logFile);

        try {
            $container = $this->container(true);
            $container->get(Router::class)->get('/boom', [ThrowingController::class, 'index']);
            $response = $container->get(Kernel::class)->handle($this->request('GET', '/boom'));

            self::assertSame(500, $response->status);
            self::assertStringContainsString('Interní chyba serveru', $response->body);
            self::assertStringNotContainsString('tajny-detail', $response->body);

            $log = (string) file_get_contents($logFile);
            self::assertStringContainsString('RuntimeException', $log);
            self::assertStringContainsString('tajny-detail', $log);
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
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
