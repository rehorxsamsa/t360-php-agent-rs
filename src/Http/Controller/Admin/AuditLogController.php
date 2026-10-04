<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Audit\AuditLogPage;
use App\Application\Audit\AuditLogSearch;
use App\Application\Audit\InvalidAuditLogFilter;
use App\Domain\Audit\AuditAction;
use App\Http\Auth\AuthSession;
use App\Http\PageNotFound;
use App\Http\PageNumber;
use App\Http\Request;
use App\Http\Response;
use App\Http\View\TemplateRenderer;

/**
 * Audit log v administraci (jen čtení): filtr podle akce a data jako GET formulář (nic nemění, CSRF netřeba),
 * stránkování po 50 se zachováním filtru. Tenký controller – validaci a stránkování řeší AuditLogSearch.
 */
final readonly class AuditLogController
{
    private const string LOGIN_PATH = '/admin/prihlaseni';
    private const string LIST_PATH = '/admin/audit';

    /** Parametry odkazu v pevném pořadí (filtr, pak strana). */
    private const array QUERY_KEYS = ['akce', 'od', 'do'];

    public function __construct(
        private TemplateRenderer $renderer,
        private AuditLogSearch $search,
        private AuthSession $auth,
    ) {}

    /** @throws PageNotFound neplatné číslo strany nebo strana mimo rozsah */
    public function index(Request $request): Response
    {
        // Obrana do hloubky: přístup hlídá už AdminAccessMiddleware, kontrola je i zde.
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $pageNumber = PageNumber::fromQuery($request->queryParameter('strana'));

        /** @var array<string, string> $query surové hodnoty filtru (do šablony a do odkazů stránkování) */
        $query = [];
        foreach (self::QUERY_KEYS as $key) {
            $query[$key] = $request->queryParameter($key);
        }

        try {
            $filter = $this->search->filter($query['akce'], $query['od'], $query['do']);
        } catch (InvalidAuditLogFilter $exception) {
            return $this->render(422, $query, $exception->errors, null, false);
        }

        $page = $this->search->page($filter, $pageNumber) ?? throw new PageNotFound();

        return $this->render(200, $query, [], $page, !$filter->isEmpty());
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $errors pole => česká hláška
     */
    private function render(int $status, array $query, array $errors, ?AuditLogPage $page, bool $filtered): Response
    {
        return Response::html($this->renderer->render('admin/audit/index', [
            'title' => 'Audit log',
            'query' => $query,
            'errors' => $errors,
            'actions' => AuditAction::cases(),
            'page' => $page,
            'filtered' => $filtered,
            'previousUrl' => $page !== null && $page->hasPrevious() ? $this->pageUrl($query, $page->page - 1) : null,
            'nextUrl' => $page !== null && $page->hasNext() ? $this->pageUrl($query, $page->page + 1) : null,
        ]), $status);
    }

    /**
     * Odkaz na stranu výpisu se zachováním (už validovaného) filtru: parametry `akce`, `od`, `do`, `strana`,
     * prázdné se vynechají, strana 1 je bez `strana`.
     *
     * @param array<string, string> $query
     */
    private function pageUrl(array $query, int $page): string
    {
        $parameters = [];
        foreach (self::QUERY_KEYS as $key) {
            if (($query[$key] ?? '') !== '') {
                $parameters[$key] = $query[$key];
            }
        }
        if ($page > 1) {
            $parameters['strana'] = (string) $page;
        }

        return $parameters === [] ? self::LIST_PATH : self::LIST_PATH . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
