<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Klient, který umí odpověď předávat průběžně (ADR-0008). `LlmClient` zůstává beze změny,
 * takže příklady 01–05 a jejich testovací dvojníci streamování nepotřebují.
 */
interface StreamingLlmClient extends LlmClient
{
    /**
     * Jedno volání modelu se streamováním: každý přírůstek textu jde do `$onText`.
     * Vrátí-li callback `false`, klient přestane číst a výsledek má `stopReason 'aborted'`
     * (přerušení není chyba). Výsledné `LlmResponse` nese celý dosud přijatý text a spotřebu.
     *
     * @param callable(string): bool $onText
     * @throws LlmCallFailed volání se nepodařilo (i uprostřed proudu)
     * @throws AiBudgetExceeded denní limit tokenů by byl překročen
     * @throws \LogicException požadavek s nástroji (streamování s nástroji není podporované)
     */
    public function stream(LlmRequest $request, callable $onText): LlmResponse;
}
