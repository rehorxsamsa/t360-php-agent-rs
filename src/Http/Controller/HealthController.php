<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Health\DatabaseHealth;
use App\Http\Request;
use App\Http\Response;

final readonly class HealthController
{
    public function __construct(private DatabaseHealth $databaseHealth) {}

    public function __invoke(Request $request): Response
    {
        // Klíče a hodnoty jsou zamčený kontrakt (devops-kontrakt, healthcheck webu).
        if ($this->databaseHealth->isReachable()) {
            return Response::json(['stav' => 'ok', 'db' => 'ok']);
        }

        return Response::json(['stav' => 'chyba', 'db' => 'chyba'], 503);
    }
}
