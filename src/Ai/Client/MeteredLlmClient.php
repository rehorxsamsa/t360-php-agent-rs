<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiProvider;
use App\Ai\Cost\ModelCatalog;
use App\Ai\Cost\UnknownModel;
use App\Ai\LlmCallFailed;
use App\Ai\LlmClient;
use App\Ai\LlmErrorType;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\StreamingLlmClient;
use App\Domain\Ai\AiCall;
use App\Domain\Ai\AiCallRepository;
use App\Domain\Ai\AiCallStatus;
use App\Domain\Ai\TokenUsage;
use App\Domain\Time\Clock;

/**
 * Dekorátor kolem skutečného klienta: hlídá denní limit tokenů, počítá cenu z katalogu modelů
 * a zapisuje metadata každého volání do `ai_calls` (bez obsahu promptů a odpovědí).
 * Kontejner skládá vždy tento dekorátor, takže limit a log platí pro všechny příklady.
 *
 * `stream()` (ADR-0008) měří stejně jako `complete()`: přerušené volání se zapíše jako `ok` se `stopReason 'aborted'`
 * a odhadnutou spotřebou, chyba uprostřed proudu jako `error` s nulovou spotřebou.
 */
final readonly class MeteredLlmClient implements StreamingLlmClient
{
    private const string TIME_ZONE = 'Europe/Prague';

    public function __construct(
        private LlmClient $inner,
        private ModelCatalog $catalog,
        private AiCallRepository $calls,
        private Clock $clock,
        private AiProvider $provider,
        private int $dailyTokenLimit,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        return $this->metered($request, fn(): LlmResponse => $this->inner->complete($request));
    }

    public function stream(LlmRequest $request, callable $onText): LlmResponse
    {
        $inner = $this->inner;
        if (!$inner instanceof StreamingLlmClient) {
            throw new \LogicException('Vnitřní klient neumí streamování.');
        }

        return $this->metered($request, static fn(): LlmResponse => $inner->stream($request, $onText));
    }

    /**
     * Společná část `complete()` a `stream()`: limit před voláním, cena a záznam do `ai_calls` po něm.
     *
     * @param callable(): LlmResponse $call vlastní volání vnitřního klienta
     */
    private function metered(LlmRequest $request, callable $call): LlmResponse
    {
        try {
            $model = $this->catalog->get($request->model);
        } catch (UnknownModel $exception) {
            throw new LlmCallFailed(LlmErrorType::Configuration, attempts: 0, message: $exception->getMessage());
        }

        $now = $this->clock->now();
        $dayStart = $now->setTimezone(new \DateTimeZone(self::TIME_ZONE))->setTime(0, 0);

        // Rezervuje se maxTokens; vstup se neodhaduje, jedno volání tak může limit přesáhnout o svůj vstup.
        $used = $this->calls->usageSince($dayStart)->tokens;
        if ($used + $request->maxTokens > $this->dailyTokenLimit) {
            throw AiBudgetExceeded::forLimit($this->dailyTokenLimit, $used, $request->maxTokens);
        }

        $startedAt = hrtime(true);
        try {
            $response = $call();
        } catch (LlmCallFailed $exception) {
            $this->recordFailure($request, $now, $this->elapsedMs($startedAt), $exception);

            throw $exception;
        }

        $cost = $model->cost($response->usage);
        $this->calls->add(new AiCall(
            $now,
            $request->userId,
            $request->exampleId,
            $this->provider->logName(),
            $request->model,
            $response->usage,
            $cost,
            $this->elapsedMs($startedAt),
            $response->attempts,
            AiCallStatus::Ok,
            null,
            $response->stopReason,
            $response->requestId,
        ));

        return $response->withCost($cost);
    }

    private function recordFailure(
        LlmRequest $request,
        \DateTimeImmutable $now,
        int $durationMs,
        LlmCallFailed $failure,
    ): void {
        try {
            $this->calls->add(new AiCall(
                $now,
                $request->userId,
                $request->exampleId,
                $this->provider->logName(),
                $request->model,
                new TokenUsage(0, 0),
                0.0,
                $durationMs,
                $failure->attempts,
                AiCallStatus::Error,
                $failure->type->value,
                null,
                $failure->requestId,
            ));
        } catch (\Throwable) {
            // Selhání zápisu logu nesmí zakrýt původní chybu volání.
            error_log('MeteredLlmClient: nepodařilo se zapsat selhané volání do ai_calls.');
        }
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
