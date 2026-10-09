<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Ai\AiRateBucket;
use App\Application\Ai\AiRateLimiter;
use App\Application\Ai\AiRateLimitExceeded;
use App\Http\Auth\AuthSession;
use App\Http\Controller\Admin\AiController;
use App\Http\Controller\Admin\AiEditorController;
use App\Http\Controller\Admin\AskNewsroomController;
use App\Http\Controller\Admin\SemanticSearchController;
use App\Http\Controller\Admin\WritingAssistantController;
use App\Http\Request;
use App\Http\Response;
use App\Http\View\TemplateRenderer;

/**
 * Rate limit AI tras (plán 013, ADR-0013, OWASP LLM10). Poslední článek řetězu: běží až za CSRF a AdminAccess,
 * takže cizí web ani nepřihlášený adminovi limit „nevypálí“. Trasu pozná podle handleru z RouteMatch (ne podle
 * textu cesty), záznam vzniká před controllerem. Překročení = 429 + Retry-After (HTML, u 06 proudu JSON před startem proudu).
 *
 * Každá `POST /admin/ai…` trasa musí být v LIMITED_HANDLERS, nebo v EXEMPT_HANDLERS (hlídá AiRateLimitRoutesContractTest).
 * Opomenutá POST trasa pod `/admin/ai/` dostane běžný kbelík (fail-closed).
 */
final readonly class AiRateLimitMiddleware implements Middleware
{
    /** @var array<string, array{bucket: AiRateBucket, json: bool}> handler `FQCN::metoda` → kbelík a formát odpovědi */
    public const array LIMITED_HANDLERS = [
        AiController::class . '::run' => ['bucket' => AiRateBucket::Standard, 'json' => false],
        WritingAssistantController::class . '::stream' => ['bucket' => AiRateBucket::Standard, 'json' => true],
        AskNewsroomController::class . '::ask' => ['bucket' => AiRateBucket::Standard, 'json' => false],
        SemanticSearchController::class . '::ask' => ['bucket' => AiRateBucket::Standard, 'json' => false],
        SemanticSearchController::class . '::reindex' => ['bucket' => AiRateBucket::Standard, 'json' => false],
        AiEditorController::class . '::draft' => ['bucket' => AiRateBucket::Heavy, 'json' => false],
    ];

    /**
     * Vědomě neomezené AI trasy: nevolají LLM a blokace by zahodila už zaplacený návrh AI redaktora (plán 013, AC 14).
     *
     * @var list<string>
     */
    public const array EXEMPT_HANDLERS = [
        AiEditorController::class . '::save',
        AiEditorController::class . '::discard',
    ];

    private const string TITLE = 'Příliš mnoho požadavků na AI';
    private const string BACK_PATH = '/admin/ai';
    private const string BACK_LABEL = 'Zpět na přehled AI příkladů';

    public function __construct(
        private AuthSession $auth,
        private AiRateLimiter $limiter,
        private TemplateRenderer $renderer,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $rule = $this->ruleFor($request);
        if ($rule === null) {
            return $next($request);
        }

        $user = $this->auth->user();
        if ($user === null) {
            // AdminAccessMiddleware nepřihlášeného na /admin nepustí; bez uživatele není komu limit počítat.
            return $next($request);
        }

        try {
            $this->limiter->consume($user->id, $rule['bucket'], $request->clientIp, $request->method . ' ' . $request->path);
        } catch (AiRateLimitExceeded $exception) {
            return $this->tooManyRequests($exception, $rule['json']);
        }

        return $next($request);
    }

    /** @return array{bucket: AiRateBucket, json: bool}|null */
    private function ruleFor(Request $request): ?array
    {
        if ($request->route === null) {
            return null;
        }

        [$class, $method] = $request->route->handler;
        $key = $class . '::' . $method;

        if (isset(self::LIMITED_HANDLERS[$key])) {
            return self::LIMITED_HANDLERS[$key];
        }

        // Fail-closed: nová POST trasa pod /admin/ai/, kterou nikdo nezařadil do map, dostane běžný kbelík.
        if ($request->method === 'POST'
            && str_starts_with($request->path, '/admin/ai/')
            && !in_array($key, self::EXEMPT_HANDLERS, true)) {
            return ['bucket' => AiRateBucket::Standard, 'json' => false];
        }

        return null;
    }

    private function tooManyRequests(AiRateLimitExceeded $exception, bool $json): Response
    {
        $headers = ['Retry-After' => (string) $exception->retryAfterSeconds, 'Cache-Control' => 'no-store'];

        if ($json) {
            return Response::json(['error' => $exception->getMessage()], 429, $headers);
        }

        $html = $this->renderer->render('error', [
            'status' => 429,
            'title' => self::TITLE,
            'message' => $exception->getMessage(),
            'backPath' => self::BACK_PATH,
            'backLabel' => self::BACK_LABEL,
        ]);

        return Response::html($html, 429, $headers);
    }
}
