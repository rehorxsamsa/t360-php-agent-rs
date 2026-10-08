<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\StreamingLlmClient;
use App\Domain\Ai\TokenUsage;

/**
 * Záložní adaptér pro `LlmClient`, který streamování neumí: zavolá `complete()` a celý text předá
 * callbacku jedním přírůstkem. Kontejner ho použije jen tehdy, když zaregistrovaný `LlmClient` není
 * `StreamingLlmClient` (v běžném zapojení je to vždy `MeteredLlmClient`, který streamování umí);
 * hlavně díky němu se dají služby závislé na `StreamingLlmClient` sestavit i nad jednoduchým testovacím klientem.
 */
final readonly class BufferedStreamingClient implements StreamingLlmClient
{
    public function __construct(private LlmClient $inner) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        return $this->inner->complete($request);
    }

    public function stream(LlmRequest $request, callable $onText): LlmResponse
    {
        if ($request->tools !== null) {
            throw new \LogicException('Streamování s nástroji není podporované.');
        }

        $response = $this->inner->complete($request);
        if ($response->text === '' || $onText($response->text) !== false) {
            return $response;
        }

        // Přerušení po jediném přírůstku: text už byl odeslán celý, takže se jen označí stav.
        return new LlmResponse(
            $response->text,
            $response->model,
            'aborted',
            new TokenUsage($response->usage->input, $response->usage->output, $response->usage->cacheWrite, $response->usage->cacheRead),
            $response->provider,
            $response->requestId,
            $response->attempts,
            $response->costUsd,
        );
    }
}
