<?php

declare(strict_types=1);

use App\Http\Controller\Admin\AiController;
use App\Http\Controller\Admin\AiEditorController;
use App\Http\Controller\Admin\ArticleController as AdminArticleController;
use App\Http\Controller\Admin\AskNewsroomController;
use App\Http\Controller\Admin\AuditLogController;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\LoginController;
use App\Http\Controller\Admin\McpServerController;
use App\Http\Controller\Admin\SemanticSearchController;
use App\Http\Controller\Admin\WritingAssistantController;
use App\Http\Controller\ArticleController;
use App\Http\Controller\HealthController;
use App\Http\Controller\HomeController;
use App\Http\Controller\SearchController;
use App\Http\Routing\Router;

/** Tabulka tras: nová stránka = jeden řádek zde + controller. */
return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/clanek/{slug}', [ArticleController::class, 'show']);
    $router->get('/hledani', [SearchController::class, 'index']);
    $router->get('/zdravi', [HealthController::class, '__invoke']);

    // Administrace: ochranu prefixu /admin zajišťuje AdminAccessMiddleware (veřejné je jen přihlášení).
    $router->get('/admin/prihlaseni', [LoginController::class, 'show']);
    $router->post('/admin/prihlaseni', [LoginController::class, 'login']);
    $router->post('/admin/odhlaseni', [LoginController::class, 'logout']);
    $router->get('/admin', [DashboardController::class, 'index']);
    $router->get('/admin/clanky', [AdminArticleController::class, 'index']);
    $router->get('/admin/clanky/novy', [AdminArticleController::class, 'create']);
    $router->post('/admin/clanky/novy', [AdminArticleController::class, 'store']);
    $router->get('/admin/clanky/{id}/upravit', [AdminArticleController::class, 'edit']);
    $router->post('/admin/clanky/{id}/upravit', [AdminArticleController::class, 'update']);
    $router->get('/admin/clanky/{id}/smazat', [AdminArticleController::class, 'confirmDelete']);
    $router->post('/admin/clanky/{id}/smazat', [AdminArticleController::class, 'delete']);
    $router->get('/admin/ai', [AiController::class, 'index']);
    // Příklady 06–10 mají vlastní stránky; musí stát PŘED obecnou trasou /admin/ai/{example} (router bere první shodu).
    $router->get('/admin/ai/06', [WritingAssistantController::class, 'show']);
    $router->post('/admin/ai/06/proud', [WritingAssistantController::class, 'stream']);
    $router->get('/admin/ai/07', [AskNewsroomController::class, 'show']);
    $router->post('/admin/ai/07', [AskNewsroomController::class, 'ask']);
    $router->get('/admin/ai/08', [SemanticSearchController::class, 'show']);
    $router->post('/admin/ai/08', [SemanticSearchController::class, 'ask']);
    $router->post('/admin/ai/08/indexace', [SemanticSearchController::class, 'reindex']);
    // 09 – AI redaktor: návrh jen v session, jediný zápis (koncept) je POST /ulozit od admina (ADR-0010).
    $router->get('/admin/ai/09', [AiEditorController::class, 'show']);
    $router->post('/admin/ai/09', [AiEditorController::class, 'draft']);
    $router->post('/admin/ai/09/ulozit', [AiEditorController::class, 'save']);
    $router->post('/admin/ai/09/zahodit', [AiEditorController::class, 'discard']);
    // 10 – MCP server redakce: jen informační GET (žádná akce; POST /admin/ai/10 propadne obecné trase a skončí 404).
    $router->get('/admin/ai/10', [McpServerController::class, 'show']);
    $router->get('/admin/ai/{example}', [AiController::class, 'show']);
    $router->post('/admin/ai/{example}', [AiController::class, 'run']);
    $router->get('/admin/audit', [AuditLogController::class, 'index']);
};
