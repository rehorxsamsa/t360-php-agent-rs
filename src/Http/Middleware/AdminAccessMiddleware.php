<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Auth\AuthSession;
use App\Http\Request;
use App\Http\Response;

/**
 * Chrání prefix /admin (porovnává po segmentech, takže /adminx chráněno není).
 * Role je jediná (admin), proto přihlášený uživatel = administrátor.
 */
final readonly class AdminAccessMiddleware implements Middleware
{
    private const string PREFIX = '/admin';
    private const string LOGIN_PATH = '/admin/prihlaseni';
    /** @var list<string> */
    private const array PUBLIC_PATHS = [self::LOGIN_PATH];

    public function __construct(private AuthSession $auth) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->isProtected($request->path) && $this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        return $next($request);
    }

    private function isProtected(string $path): bool
    {
        $inAdmin = $path === self::PREFIX || str_starts_with($path, self::PREFIX . '/');

        return $inAdmin && !in_array($path, self::PUBLIC_PATHS, true);
    }
}
