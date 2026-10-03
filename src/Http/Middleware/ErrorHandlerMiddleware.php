<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\MethodNotAllowed;
use App\Http\Routing\RouteNotFound;
use App\Http\View\TemplateRenderer;

/**
 * Nejvnější middleware: změní výjimky na české HTML stránky 404 / 405 / 500.
 * Detail neočekávané chyby jde jen do error_log, uživatel ho nikdy neuvidí.
 */
final readonly class ErrorHandlerMiddleware implements Middleware
{
    public function __construct(private TemplateRenderer $renderer) {}

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (RouteNotFound) {
            return $this->page(404, 'Stránka nenalezena', 'Požadovaná stránka neexistuje nebo byla přesunuta.');
        } catch (MethodNotAllowed $exception) {
            return $this->page(
                405,
                'Metoda není povolena',
                'Tuto stránku nelze takto zobrazit.',
                ['Allow' => implode(', ', $exception->allowedMethods)],
            );
        } catch (\Throwable $exception) {
            $this->log($exception);

            return $this->page(
                500,
                'Interní chyba serveru',
                'Omlouváme se, něco se pokazilo. Zkuste to prosím později.',
            );
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function page(int $status, string $title, string $message, array $headers = []): Response
    {
        try {
            $html = $this->renderer->render('error', ['status' => $status, 'title' => $title, 'message' => $message]);

            return Response::html($html, $status, $headers);
        } catch (\Throwable $exception) {
            // Selhala i chybová stránka – poslední záchrana je prostý text.
            $this->log($exception);

            return Response::text($title, $status, $headers);
        }
    }

    private function log(\Throwable $exception): void
    {
        error_log(sprintf(
            'Nezachycená výjimka %s: %s (%s:%d)',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        ));
    }
}
