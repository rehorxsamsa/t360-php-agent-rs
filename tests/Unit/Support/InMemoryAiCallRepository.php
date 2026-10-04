<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Ai\AiCall;
use App\Domain\Ai\AiCallRepository;
use App\Domain\Ai\AiCallStatus;
use App\Domain\Ai\AiUsageTotals;
use App\Domain\Ai\TokenUsage;

/**
 * Log volání AI v paměti (plán 006, §5). `usageSince` počítá stejně jako SQL v PdoAiCallRepository:
 * počet volání, součet všech čtyř druhů tokenů a cen od daného okamžiku včetně.
 */
final class InMemoryAiCallRepository implements AiCallRepository
{
    /** @var list<AiCall> v pořadí zápisu (index = „id“ - 1) */
    public array $calls = [];

    public function add(AiCall $call): void
    {
        $this->calls[] = $call;
    }

    public function usageSince(\DateTimeImmutable $since): AiUsageTotals
    {
        $count = 0;
        $tokens = 0;
        $cost = 0.0;
        foreach ($this->calls as $call) {
            if ($call->createdAt < $since) {
                continue;
            }
            ++$count;
            $tokens += $call->usage->input + $call->usage->output + $call->usage->cacheWrite + $call->usage->cacheRead;
            $cost += $call->costUsd;
        }

        return new AiUsageTotals($count, $tokens, $cost);
    }

    public function recent(int $limit): array
    {
        $indexed = [];
        foreach ($this->calls as $index => $call) {
            $indexed[] = ['index' => $index, 'call' => $call];
        }
        usort(
            $indexed,
            static fn(array $a, array $b): int => [$b['call']->createdAt, $b['index']] <=> [$a['call']->createdAt, $a['index']],
        );

        return array_map(
            static fn(array $item): AiCall => $item['call'],
            array_slice($indexed, 0, max(0, $limit)),
        );
    }

    /** Pomocník pro testy: záznam volání s rozumnými výchozími hodnotami (čas v Europe/Prague). */
    public static function call(
        string $createdAt,
        TokenUsage $usage,
        float $costUsd = 0.0,
        ?int $userId = 7,
        string $exampleId = '01',
        AiCallStatus $status = AiCallStatus::Ok,
        ?string $errorType = null,
        string $model = 'claude-sonnet-5-5',
        string $provider = 'fake',
        int $durationMs = 12,
    ): AiCall {
        return new AiCall(
            createdAt: new \DateTimeImmutable($createdAt, new \DateTimeZone('Europe/Prague')),
            userId: $userId,
            exampleId: $exampleId,
            provider: $provider,
            model: $model,
            usage: $usage,
            costUsd: $costUsd,
            durationMs: $durationMs,
            attempts: 1,
            status: $status,
            errorType: $errorType,
            stopReason: $status === AiCallStatus::Ok ? 'end_turn' : null,
            requestId: null,
        );
    }
}
