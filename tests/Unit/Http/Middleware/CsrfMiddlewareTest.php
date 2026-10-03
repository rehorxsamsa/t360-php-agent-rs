<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Middleware\CsrfMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware $middleware;
    private CsrfToken $token;
    private int $handlerCalls = 0;

    protected function setUp(): void
    {
        $container = TestContainer::create(
            new ArraySession(),
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
        );
        $this->middleware = $container->get(CsrfMiddleware::class);
        $this->token = $container->get(CsrfToken::class);
        $this->handlerCalls = 0;
    }

    private function process(Request $request): Response
    {
        return $this->middleware->process($request, function (): Response {
            ++$this->handlerCalls;

            return Response::text('ok');
        });
    }

    public function test_get_without_token_passes_to_handler(): void
    {
        $response = $this->process(new Request('GET', '/x'));

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->handlerCalls);
    }

    public function test_head_without_token_passes_to_handler(): void
    {
        $this->process(new Request('HEAD', '/x'));

        self::assertSame(1, $this->handlerCalls);
    }

    public function test_post_without_token_is_403_and_handler_not_called(): void
    {
        $response = $this->process(new Request('POST', '/x'));

        self::assertSame(403, $response->status);
        self::assertStringContainsString('Neplatný formulář', $response->body);
        self::assertSame(0, $this->handlerCalls);
    }

    public function test_post_with_wrong_token_is_403_and_handler_not_called(): void
    {
        $this->token->token();

        $response = $this->process(new Request('POST', '/x', body: ['_csrf' => 'spatny']));

        self::assertSame(403, $response->status);
        self::assertSame(0, $this->handlerCalls);
    }

    public function test_post_with_correct_token_calls_handler(): void
    {
        $response = $this->process(new Request('POST', '/x', body: ['_csrf' => $this->token->token()]));

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->handlerCalls);
    }

    public function test_other_unsafe_methods_also_require_token(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertSame(403, $this->process(new Request($method, '/x'))->status, $method);
        }
        self::assertSame(0, $this->handlerCalls);
    }

    public function test_403_page_does_not_leak_expected_token(): void
    {
        $response = $this->process(new Request('POST', '/x'));

        self::assertStringNotContainsString($this->token->token(), $response->body);
    }
}
