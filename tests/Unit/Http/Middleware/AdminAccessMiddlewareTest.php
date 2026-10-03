<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Domain\User\Role;
use App\Http\Middleware\AdminAccessMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminAccessMiddlewareTest extends TestCase
{
    private ArraySession $session;
    private InMemoryUserRepository $users;
    private AdminAccessMiddleware $middleware;
    private int $handlerCalls = 0;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->users = new InMemoryUserRepository();
        $container = TestContainer::create($this->session, $this->users, new InMemoryAuditLogRepository());
        $this->middleware = $container->get(AdminAccessMiddleware::class);
        $this->handlerCalls = 0;
    }

    private function process(string $path): Response
    {
        return $this->middleware->process(new Request('GET', $path), function (): Response {
            ++$this->handlerCalls;

            return Response::text('ok');
        });
    }

    /** @return array<string, array{string}> */
    public static function protectedPaths(): array
    {
        return ['admin root' => ['/admin'], 'admin sub' => ['/admin/cokoliv'], 'admin deep' => ['/admin/clanky/1/upravit'], 'logout' => ['/admin/odhlaseni']];
    }

    #[DataProvider('protectedPaths')]
    public function test_anonymous_is_redirected_to_login(string $path): void
    {
        $response = $this->process($path);

        self::assertSame(303, $response->status);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null);
        self::assertSame(0, $this->handlerCalls);
    }

    /** @return array<string, array{string}> */
    public static function openPaths(): array
    {
        return ['login' => ['/admin/prihlaseni'], 'home' => ['/'], 'similar prefix' => ['/adminx'], 'health' => ['/zdravi']];
    }

    #[DataProvider('openPaths')]
    public function test_public_paths_pass_through_for_anonymous(string $path): void
    {
        $response = $this->process($path);

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->handlerCalls);
    }

    public function test_logged_in_user_passes_to_admin(): void
    {
        $id = $this->users->add('a@example.cz', 'Admin', 'hash', Role::Admin);
        $this->session->set('user_id', $id);

        $response = $this->process('/admin');

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->handlerCalls);
    }

    public function test_session_of_deleted_user_is_redirected_and_user_id_removed(): void
    {
        $id = $this->users->add('a@example.cz', 'Admin', 'hash', Role::Admin);
        $this->session->set('user_id', $id);
        $this->users->remove($id);

        $response = $this->process('/admin');

        self::assertSame(303, $response->status);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null);
        self::assertNull($this->session->get('user_id'));
        self::assertSame(0, $this->handlerCalls);
    }
}
