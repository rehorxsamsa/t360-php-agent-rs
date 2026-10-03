<?php

declare(strict_types=1);

use App\Container\Container;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    /** @var Container $container */
    $container = require dirname(__DIR__) . '/config/container.php';

    $container->get(Kernel::class)->handle(Request::fromGlobals())->send();
} catch (Throwable $exception) {
    // Poslední pojistka (např. chyba při sestavení kontejneru). Detail jen do logu (stderr kontejneru).
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
