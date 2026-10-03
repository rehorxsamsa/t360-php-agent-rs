<?php

declare(strict_types=1);

use App\Console\Command\MigrateCommand;
use App\Console\Command\MigrationStatusCommand;
use App\Console\Command\RollbackCommand;
use App\Console\ConsoleApplication;
use App\Container\Container;
use App\Domain\Health\DatabaseHealth;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Routing\Router;
use App\Http\View\TemplateRenderer;
use App\Infrastructure\Config\DatabaseConfig;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\ConnectionFactory;
use App\Infrastructure\Persistence\PdoDatabaseHealthRepository;

/**
 * Kompoziční kořen: vrací při každém načtení nový Container.
 * Továrny jsou líné – web nikdy nesahá na migrační heslo (Migrator se sestaví jen v konzoli).
 */
$root = dirname(__DIR__);
$container = new Container();

$container->set(
    DatabaseConfig::class,
    static fn(): DatabaseConfig => DatabaseConfig::fromEnvironment(getenv()),
);

$container->set(
    DatabaseHealth::class,
    static fn(Container $c): DatabaseHealth => $c->get(PdoDatabaseHealthRepository::class),
);

$container->set(
    TemplateRenderer::class,
    static fn(): TemplateRenderer => new TemplateRenderer($root . '/templates'),
);

$container->set(Router::class, static function () use ($root): Router {
    $router = new Router();
    /** @var Closure(Router): void $registerRoutes */
    $registerRoutes = require $root . '/config/routes.php';
    $registerRoutes($router);

    return $router;
});

$container->set(
    MiddlewarePipeline::class,
    static fn(Container $c): MiddlewarePipeline => new MiddlewarePipeline([
        $c->get(ErrorHandlerMiddleware::class),
    ]),
);

$container->set(
    ConsoleApplication::class,
    static fn(Container $c): ConsoleApplication => new ConsoleApplication($c, [
        'migrace:spust' => MigrateCommand::class,
        'migrace:vrat' => RollbackCommand::class,
        'migrace:stav' => MigrationStatusCommand::class,
    ]),
);

$container->set(Migrator::class, static function () use ($root): Migrator {
    // Jedno spojení (účet s právem DDL) sdílí migrátor i jeho repozitář.
    $pdo = new ConnectionFactory(DatabaseConfig::forMigrations(getenv()))->create();

    return new Migrator(new PdoMigrationRepository($pdo), $pdo, $root . '/database/migrations');
});

return $container;
