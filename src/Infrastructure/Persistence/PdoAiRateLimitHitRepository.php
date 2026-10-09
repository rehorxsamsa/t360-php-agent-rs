<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Ai\AiRateLimitHitRepository;
use App\Domain\Ai\AiRateLimitWindow;

final readonly class PdoAiRateLimitHitRepository implements AiRateLimitHitRepository
{
    public function __construct(private \PDO $pdo) {}

    public function add(int $userId, string $bucket, \DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ai_rate_limit_hits (user_id, bucket, created_at) VALUES (:user_id, :bucket, :created_at)',
        );
        $statement->execute([
            'user_id' => $userId,
            'bucket' => $bucket,
            'created_at' => $this->formatDateTime($at),
        ]);
    }

    public function windowSince(int $userId, string $bucket, \DateTimeImmutable $since): AiRateLimitWindow
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS hits, MIN(created_at) AS oldest FROM ai_rate_limit_hits '
            . 'WHERE user_id = :user_id AND bucket = :bucket AND created_at > :since',
        );
        $statement->execute([
            'user_id' => $userId,
            'bucket' => $bucket,
            'since' => $this->formatDateTime($since),
        ]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row) || !is_numeric($row['hits'] ?? null)) {
            throw new \UnexpectedValueException('Souhrn tabulky ai_rate_limit_hits má neočekávaný tvar.');
        }

        $oldest = $row['oldest'] ?? null;
        if ($oldest !== null && !is_string($oldest)) {
            throw new \UnexpectedValueException('Čas záznamu ai_rate_limit_hits má neočekávaný tvar.');
        }

        return new AiRateLimitWindow((int) $row['hits'], $oldest === null ? null : new \DateTimeImmutable($oldest));
    }

    private function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s.u');
    }
}
