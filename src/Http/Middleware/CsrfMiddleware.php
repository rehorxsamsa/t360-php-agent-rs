<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\View\TemplateRenderer;

/** Každá metoda mimo GET/HEAD musí poslat platný token v poli `_csrf`, jinak 403. */
final readonly class CsrfMiddleware implements Middleware
{
    private const array SAFE_METHODS = ['GET', 'HEAD'];

    public function __construct(
        private CsrfToken $csrf,
        private TemplateRenderer $renderer,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->method, self::SAFE_METHODS, true) || $this->csrf->isValid($request->input('_csrf'))) {
            return $next($request);
        }

        $html = $this->renderer->render('error', [
            'status' => 403,
            'title' => 'Neplatný formulář',
            'message' => 'Platnost formuláře vypršela. Vraťte se, obnovte stránku a zkuste to znovu.',
        ]);

        return Response::html($html, 403);
    }
}
