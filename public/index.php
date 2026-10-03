<?php

declare(strict_types=1);

use App\Http\Controller\HealthController;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Config\DatabaseConfig;
use App\Infrastructure\Persistence\ConnectionFactory;
use App\Infrastructure\Persistence\PdoDatabaseHealthRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config = DatabaseConfig::fromEnvironment(getenv());
    $kernel = new Kernel(
        new HealthController(
            new PdoDatabaseHealthRepository(new ConnectionFactory($config)),
        ),
    );

    $kernel->handle(Request::fromGlobals())->send();
} catch (Throwable $exception) {
    // Detail jen do logu (stderr kontejneru), uživatel nikdy nevidí stack trace.
    error_log(sprintf(
        'Nezachycená výjimka %s: %s (%s:%d)',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
    ));

    if (!headers_sent()) {
        Response::text('Interní chyba serveru', 500)->send();
    }
}
