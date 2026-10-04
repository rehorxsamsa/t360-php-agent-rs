<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Port pro volání jazykového modelu. Zbytek aplikace zná jen toto rozhraní;
 * adaptéry jsou v `App\Ai\Client` (ADR-0006).
 */
interface LlmClient
{
    /**
     * Jedno volání modelu (bez streamování).
     *
     * @throws LlmCallFailed volání se nepodařilo (síť, chyba API, špatná konfigurace)
     * @throws AiBudgetExceeded denní limit tokenů by byl překročen
     */
    public function complete(LlmRequest $request): LlmResponse;
}
