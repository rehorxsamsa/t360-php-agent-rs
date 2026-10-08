<?php

declare(strict_types=1);

use App\Ai\AiConfig;
use App\Ai\AiProvider;
use App\Ai\Client\AnthropicClient;
use App\Ai\Client\BufferedStreamingClient;
use App\Ai\Client\CurlHttpTransport;
use App\Ai\Client\FakeLlmClient;
use App\Ai\Client\HttpTransport;
use App\Ai\Client\MeteredLlmClient;
use App\Ai\Cost\ModelCatalog;
use App\Ai\LlmClient;
use App\Ai\PromptLibrary;
use App\Ai\StreamingLlmClient;
use App\Console\Command\AiExampleCommand;
use App\Console\Command\CreateAdminCommand;
use App\Console\Command\MigrateCommand;
use App\Console\Command\MigrationStatusCommand;
use App\Console\Command\RollbackCommand;
use App\Console\Command\SeedCommand;
use App\Console\ConsoleApplication;
use App\Container\Container;
use App\Domain\Ai\AiCallRepository;
use App\Domain\Article\ArticleAdminRepository;
use App\Domain\Article\ArticleRepository;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\Category\CategoryRepository;
use App\Domain\Health\DatabaseHealth;
use App\Domain\Tag\TagRepository;
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
use App\Infrastructure\Persistence\PdoAiCallRepository;
use App\Infrastructure\Persistence\PdoArticleAdminRepository;
use App\Infrastructure\Persistence\PdoArticleRepository;
use App\Infrastructure\Persistence\PdoAuditLogRepository;
use App\Infrastructure\Persistence\PdoCategoryRepository;
use App\Infrastructure\Persistence\PdoDatabaseHealthRepository;
use App\Infrastructure\Persistence\PdoTagRepository;
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

// Administrace článků (všechny stavy) má vlastní rozhraní, veřejné čtení zůstává jen pro publikované.
$container->set(
    ArticleAdminRepository::class,
    static fn(Container $c): ArticleAdminRepository => new PdoArticleAdminRepository($c->get(\PDO::class)),
);

$container->set(
    CategoryRepository::class,
    static fn(Container $c): CategoryRepository => new PdoCategoryRepository($c->get(\PDO::class)),
);

$container->set(
    TagRepository::class,
    static fn(Container $c): TagRepository => new PdoTagRepository($c->get(\PDO::class)),
);

$container->set(
    AiCallRepository::class,
    static fn(Container $c): AiCallRepository => new PdoAiCallRepository($c->get(\PDO::class)),
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
        'ai:priklad' => AiExampleCommand::class,
    ]),
);

// AI: konfigurace z prostředí, ceník modelů, HTTP přes cURL a klient. Kontejner vždy skládá
// MeteredLlmClient (limit, cena, log) nad falešným nebo Anthropic klientem podle AI_PROVIDER.
$container->set(
    AiConfig::class,
    static fn(): AiConfig => AiConfig::fromEnvironment(getenv()),
);

$container->set(
    ModelCatalog::class,
    static fn(): ModelCatalog => ModelCatalog::fromFile($root . '/config/ai-models.php'),
);

$container->set(
    HttpTransport::class,
    static fn(): HttpTransport => new CurlHttpTransport(),
);

$container->set(
    PromptLibrary::class,
    static fn(): PromptLibrary => new PromptLibrary($root . '/src/Ai/Prompts'),
);

// Falešný klient v dev kontejneru čeká 60 ms mezi přírůstky proudu, aby byl streaming vidět (testy ho nahrazují instancí bez zpoždění).
$container->set(
    FakeLlmClient::class,
    static fn(): FakeLlmClient => new FakeLlmClient(streamDelayMs: 60),
);

$container->set(LlmClient::class, static function (Container $c): LlmClient {
    $config = $c->get(AiConfig::class);
    $catalog = $c->get(ModelCatalog::class);

    $inner = match ($config->provider) {
        AiProvider::Fake => $c->get(FakeLlmClient::class),
        AiProvider::Anthropic => new AnthropicClient($c->get(HttpTransport::class), $catalog, $config->apiKey),
    };

    return new MeteredLlmClient(
        $inner,
        $catalog,
        $c->get(AiCallRepository::class),
        $c->get(Clock::class),
        $config->provider,
        $config->dailyTokenLimit,
    );
});

// Streamování (ADR-0008): stejná instance jako LlmClient (MeteredLlmClient umí obojí), aby se volání ze streamu
// počítalo do téhož limitu a logu. Klient bez streamování (jen v testech) se obalí záložním adaptérem.
$container->set(StreamingLlmClient::class, static function (Container $c): StreamingLlmClient {
    $client = $c->get(LlmClient::class);

    return $client instanceof StreamingLlmClient ? $client : new BufferedStreamingClient($client);
});

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
