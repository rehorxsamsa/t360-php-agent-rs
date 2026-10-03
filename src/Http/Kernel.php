<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Controller\HealthController;

/**
 * V M1 jen jedna pevná cesta; router ji v M2 nahradí.
 */
final readonly class Kernel
{
    public function __construct(private HealthController $health) {}

    public function handle(Request $request): Response
    {
        if ($request->path !== '/zdravi') {
            return Response::text('Stránka nenalezena.', 404);
        }

        if ($request->method !== 'GET') {
            return Response::text('Metoda není povolena.', 405, ['Allow' => 'GET']);
        }

        return ($this->health)();
    }
}
