<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

/**
 * Nejvnější middleware: základní bezpečnostní hlavičky na každé odpovědi včetně chybových stránek.
 * CSP je statická bez nonce – aplikace nemá žádné inline skripty ani styly.
 */
final readonly class SecurityHeadersMiddleware implements Middleware
{
    private const array HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
    ];

    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeaders(self::HEADERS);
    }
}
