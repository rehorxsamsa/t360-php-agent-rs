<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Ai\Examples\ExampleResult;

/**
 * Výsledek AI příkladu vytažený ze session spolu s volbami formuláře, se kterými vznikl
 * (zdroj článku a zvolený model). Volby jsou ze session, tedy nedůvěryhodné: controller
 * je před předvyplněním formuláře ověří proti seznamu voleb.
 */
final readonly class StashedExampleResult
{
    public function __construct(
        public ExampleResult $result,
        public string $article,
        public string $model,
    ) {}
}
