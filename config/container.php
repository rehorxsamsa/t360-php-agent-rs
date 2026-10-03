<?php

declare(strict_types=1);

use App\Console\Command\CreateAdminCommand;
use App\Console\Command\MigrateCommand;
use App\Console\Command\MigrationStatusCommand;
use App\Console\Command\RollbackCommand;
use App\Console\Command\SeedCommand;
use App\Console\ConsoleApplication;
use App\Container\Container;
use App\Domain\Article\ArticleRepository;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\Health\DatabaseHealth;
use App\Domain\Time\Clock;
use App\Domain\User\UserRepository;
use App\Http\Middleware\AdminAccessMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Middleware\RoutingMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Routing\Router;
use App\Http\Session\Session;
use App\Http\View\TemplateRenderer;
use App\Infrastructure\Config\DatabaseConfig;
use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Infrastructure\Persistence\ConnectionFactory;
use App\Infrastructure\Persistence\PdoArticleRepository;
use App\Infrastructure\Persistence\PdoAuditLogRepository;
use App\Infrastructure\Persistence\PdoDatabaseHealthRepository;
use App\Infrastructure\Persistence\PdoUserRepository;
use App\Infrastructure\Session\NativeSession;
use App\Infrastructure\Time\SystemClock;

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

// Sdílené líné spojení aplikačního účtu (bez práva DDL) pro repozitáře.
$container->set(
    \PDO::class,
    static fn(Container $c): \PDO => new ConnectionFactory($c->get(DatabaseConfig::class))->create(),
);

$container->set(
    UserRepository::class,
    static fn(Container $c): UserRepository => new PdoUserRepository($c->get(\PDO::class)),
);

$container->set(
    AuditLogRepository::class,
    static fn(Container $c): AuditLogRepository => new PdoAuditLogRepository($c->get(\PDO::class)),
);

$container->set(
    ArticleRepository::class,
    static fn(Container $c): ArticleRepository => new PdoArticleRepository($c->get(\PDO::class)),
);

$container->set(Clock::class, static fn(): Clock => new SystemClock());

// Session startuje líně; Secure cookie zapíná produkce proměnnou SESSION_COOKIE_SECURE=1 (dev běží přes HTTP).
$container->set(
    Session::class,
    static fn(): Session => new NativeSession(getenv('SESSION_COOKIE_SECURE') === '1'),
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
        $c->get(SecurityHeadersMiddleware::class),
        $c->get(ErrorHandlerMiddleware::class),
        $c->get(RoutingMiddleware::class),
        $c->get(CsrfMiddleware::class),
        $c->get(AdminAccessMiddleware::class),
    ]),
);

$container->set(
    ConsoleApplication::class,
    static fn(Container $c): ConsoleApplication => new ConsoleApplication($c, [
        'migrace:spust' => MigrateCommand::class,
        'migrace:vrat' => RollbackCommand::class,
        'migrace:stav' => MigrationStatusCommand::class,
        'admin:vytvor' => CreateAdminCommand::class,
        'db:seed' => SeedCommand::class,
    ]),
);

// Seed běží jako aplikační účet (stačí DML); samotný příkaz odmítne prostředí mimo dev|test.
$container->set(
    SeedCommand::class,
    static fn(Container $c): SeedCommand => new SeedCommand(
        new ConnectionFactory($c->get(DatabaseConfig::class)),
        $root . '/database/seeds/demo_content.php',
        (string) getenv('APP_ENV'),
    ),
);

$container->set(Migrator::class, static function () use ($root): Migrator {
    // Jedno spojení (účet s právem DDL) sdílí migrátor i jeho repozitář.
    $pdo = new ConnectionFactory(DatabaseConfig::forMigrations(getenv()))->create();

    return new Migrator(new PdoMigrationRepository($pdo), $pdo, $root . '/database/migrations');
});

return $container;
