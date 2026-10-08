<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\StreamingLlmClient;

/**
 * Skriptovaný streamovací LLM klient (plán 008, §5): fronta „delty + odpověď nebo výjimka“,
 * zaznamenává každý LlmRequest a delty, které skutečně doručil callbacku.
 *
 * Vrátí-li callback `false`, další delty se nedoručí a výsledkem je skriptovaná odpověď se
 * `stopReason 'aborted'` a textem z doručených delt (tak se chová i skutečný klient).
 * Výjimka ve frontě se vyhodí až po doručení svých delt (chyba uprostřed proudu).
 */
final class ScriptedStreamingLlmClient implements StreamingLlmClient
{
    /** @var list<array{deltas: list<string>, outcome: LlmResponse|\Throwable}> */
    private array $queue = [];

    /** @var list<LlmRequest> */
    public array $requests = [];

    /** @var list<list<string>> delty doručené při každém volání stream() */
    public array $delivered = [];

    /** @param list<string> $deltas */
    public function push(array $deltas, LlmResponse|\Throwable $outcome): self
    {
        $this->queue[] = ['deltas' => $deltas, 'outcome' => $outcome];

        return $this;
    }

    /** Zařadí proud z delt; odpověď má text = spojené delty a stop_reason end_turn. */
    public function pushText(string ...$deltas): self
    {
        return $this->push(array_values($deltas), AiFixtures::response(implode('', $deltas)));
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;
        $next = $this->next();
        if ($next['outcome'] instanceof \Throwable) {
            throw $next['outcome'];
        }

        return $next['outcome'];
    }

    public function stream(LlmRequest $request, callable $onText): LlmResponse
    {
        $this->requests[] = $request;
        $next = $this->next();

        $delivered = [];
        $aborted = false;
        foreach ($next['deltas'] as $delta) {
            $delivered[] = $delta;
            if ($onText($delta) === false) {
                $aborted = true;

                break;
            }
        }
        $this->delivered[] = $delivered;

        $outcome = $next['outcome'];
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }
        if (!$aborted) {
            return $outcome;
        }

        return new LlmResponse(
            text: implode('', $delivered),
            model: $outcome->model,
            stopReason: 'aborted',
            usage: $outcome->usage,
            provider: $outcome->provider,
            requestId: $outcome->requestId,
            attempts: $outcome->attempts,
            costUsd: $outcome->costUsd,
        );
    }

    /** @return array{deltas: list<string>, outcome: LlmResponse|\Throwable} */
    private function next(): array
    {
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('Skriptovaný streamovací klient nemá další odpověď.');
        }

        return $next;
    }
}
