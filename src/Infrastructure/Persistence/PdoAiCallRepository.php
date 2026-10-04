<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Ai\AiCall;
use App\Domain\Ai\AiCallRepository;
use App\Domain\Ai\AiCallStatus;
use App\Domain\Ai\AiUsageTotals;
use App\Domain\Ai\TokenUsage;

final readonly class PdoAiCallRepository implements AiCallRepository
{
    public function __construct(private \PDO $pdo) {}

    public function add(AiCall $call): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ai_calls (user_id, example_id, provider, model, input_tokens, output_tokens, '
            . 'cache_creation_input_tokens, cache_read_input_tokens, cost_usd, duration_ms, attempts, '
            . 'status, error_type, stop_reason, request_id, created_at) '
            . 'VALUES (:user_id, :example_id, :provider, :model, :input_tokens, :output_tokens, '
            . ':cache_creation, :cache_read, :cost_usd, :duration_ms, :attempts, '
            . ':status, :error_type, :stop_reason, :request_id, :created_at)',
        );
        $statement->execute([
            'user_id' => $call->userId,
            'example_id' => $call->exampleId,
            'provider' => $call->provider,
            'model' => $call->model,
            'input_tokens' => $call->usage->input,
            'output_tokens' => $call->usage->output,
            'cache_creation' => $call->usage->cacheWrite,
            'cache_read' => $call->usage->cacheRead,
            'cost_usd' => number_format($call->costUsd, 6, '.', ''),
            'duration_ms' => $call->durationMs,
            'attempts' => $call->attempts,
            'status' => $call->status->value,
            'error_type' => $call->errorType,
            'stop_reason' => $call->stopReason,
            'request_id' => $call->requestId,
            'created_at' => $this->formatDateTime($call->createdAt),
        ]);
    }

    public function usageSince(\DateTimeImmutable $since): AiUsageTotals
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS calls, '
            . 'COALESCE(SUM(input_tokens + output_tokens + cache_creation_input_tokens + cache_read_input_tokens), 0) AS tokens, '
            . 'COALESCE(SUM(cost_usd), 0) AS cost_usd '
            . 'FROM ai_calls WHERE created_at >= :since',
        );
        $statement->execute(['since' => $this->formatDateTime($since)]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (
            !is_array($row)
            || !is_numeric($row['calls'] ?? null)
            || !is_numeric($row['tokens'] ?? null)
            || !is_numeric($row['cost_usd'] ?? null)
        ) {
            throw new \UnexpectedValueException('Souhrn tabulky ai_calls má neočekávaný tvar.');
        }

        return new AiUsageTotals((int) $row['calls'], (int) $row['tokens'], (float) $row['cost_usd']);
    }

    public function recent(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id, example_id, provider, model, input_tokens, output_tokens, '
            . 'cache_creation_input_tokens, cache_read_input_tokens, cost_usd, duration_ms, attempts, '
            . 'status, error_type, stop_reason, request_id, created_at '
            . 'FROM ai_calls ORDER BY created_at DESC, id DESC LIMIT :limit',
        );
        $statement->bindValue('limit', max(0, $limit), \PDO::PARAM_INT);
        $statement->execute();

        $calls = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $calls[] = $this->hydrate($row);
        }

        return $calls;
    }

    private function hydrate(mixed $row): AiCall
    {
        if (
            !is_array($row)
            || !is_string($row['example_id'] ?? null)
            || !is_string($row['provider'] ?? null)
            || !is_string($row['model'] ?? null)
            || !is_string($row['created_at'] ?? null)
            || !is_numeric($row['input_tokens'] ?? null)
            || !is_numeric($row['output_tokens'] ?? null)
            || !is_numeric($row['cache_creation_input_tokens'] ?? null)
            || !is_numeric($row['cache_read_input_tokens'] ?? null)
            || !is_numeric($row['cost_usd'] ?? null)
            || !is_numeric($row['duration_ms'] ?? null)
            || !is_numeric($row['attempts'] ?? null)
            || !is_string($row['status'] ?? null)
            || (($row['user_id'] ?? null) !== null && !is_numeric($row['user_id']))
        ) {
            throw new \UnexpectedValueException('Řádek tabulky ai_calls má neočekávaný tvar.');
        }

        $userId = $row['user_id'] ?? null;
        $status = AiCallStatus::tryFrom($row['status'])
            ?? throw new \UnexpectedValueException('Stav volání AI má neočekávanou hodnotu.');

        return new AiCall(
            new \DateTimeImmutable($row['created_at']),
            is_numeric($userId) ? (int) $userId : null,
            $row['example_id'],
            $row['provider'],
            $row['model'],
            new TokenUsage(
                (int) $row['input_tokens'],
                (int) $row['output_tokens'],
                (int) $row['cache_creation_input_tokens'],
                (int) $row['cache_read_input_tokens'],
            ),
            (float) $row['cost_usd'],
            (int) $row['duration_ms'],
            (int) $row['attempts'],
            $status,
            $this->nullableString($row['error_type'] ?? null),
            $this->nullableString($row['stop_reason'] ?? null),
            $this->nullableString($row['request_id'] ?? null),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Textový sloupec ai_calls má neočekávaný tvar.');
        }

        return $value;
    }

    private function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s.u');
    }
}
