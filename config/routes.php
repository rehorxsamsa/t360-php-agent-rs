<?php

declare(strict_types=1);

use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\LoginController;
use App\Http\Controller\ArticleController;
use App\Http\Controller\HealthController;
use App\Http\Controller\HomeController;
use App\Http\Routing\Router;

/** Tabulka tras: nová stránka = jeden řádek zde + controller. */
return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/clanek/{slug}', [ArticleController::class, 'show']);
    $router->get('/zdravi', [HealthController::class, '__invoke']);

    // Administrace: ochranu prefixu /admin zajišťuje AdminAccessMiddleware (veřejné je jen přihlášení).
    $router->get('/admin/prihlaseni', [LoginController::class, 'show']);
    $router->post('/admin/prihlaseni', [LoginController::class, 'login']);
    $router->post('/admin/odhlaseni', [LoginController::class, 'logout']);
    $router->get('/admin', [DashboardController::class, 'index']);
};
