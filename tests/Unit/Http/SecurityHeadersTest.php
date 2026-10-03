<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AC 3 a 4 plánu 003: hlavičky na každé odpovědi, routing před CSRF. */
final class SecurityHeadersTest extends TestCase
{
    private const array EXPECTED = [
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'DENY',
        'referrer-policy' => 'strict-origin-when-cross-origin',
        'permissions-policy' => 'camera=(), microphone=(), geolocation=()',
        'cross-origin-opener-policy' => 'same-origin',
        'content-security-policy' => "default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
    ];

    private function handle(string $method, string $path): Response
    {
        $container = TestContainer::create(
            new ArraySession(),
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
        );

        return $container->get(Kernel::class)->handle(new Request($method, $path));
    }

    /** @return array<string, array{string, string, int}> */
    public static function requests(): array
    {
        return [
            'home' => ['GET', '/', 200],
            'not found' => ['GET', '/neexistuje', 404],
            'method not allowed' => ['POST', '/zdravi', 405],
            'login page' => ['GET', '/admin/prihlaseni', 200],
        ];
    }

    #[DataProvider('requests')]
    public function test_every_response_carries_security_headers(string $method, string $path, int $status): void
    {
        $response = $this->handle($method, $path);
        $headers = [];
        foreach ($response->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        self::assertSame($status, $response->status);
        foreach (self::EXPECTED as $name => $value) {
            self::assertSame($value, $headers[$name] ?? null, $name);
        }
    }

    public function test_post_to_existing_route_without_csrf_is_still_405_not_403(): void
    {
        $response = $this->handle('POST', '/zdravi');

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow'] ?? null);
    }

    public function test_post_to_unknown_path_is_404_not_403(): void
    {
        self::assertSame(404, $this->handle('POST', '/neexistuje')->status);
    }
}
