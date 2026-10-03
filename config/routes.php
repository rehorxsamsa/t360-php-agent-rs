<?php

declare(strict_types=1);

use App\Http\Controller\HealthController;
use App\Http\Controller\HomeController;
use App\Http\Routing\Router;

/** Tabulka tras: nová stránka = jeden řádek zde + controller. */
return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/zdravi', [HealthController::class, '__invoke']);
};
