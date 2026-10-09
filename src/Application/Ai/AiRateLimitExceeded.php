<?php

declare(strict_types=1);

namespace App\Application\Ai;

/** Požadavek překročil rate limit AI (plán 013). Zpráva je česká a skládá jen čísla, takže ji lze ukázat uživateli. */
final class AiRateLimitExceeded extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly int $limit,
        public readonly int $windowSeconds,
    ) {
        parent::__construct(sprintf(
            'Příliš mnoho požadavků na AI: nejvýše %d za %d s. Zkuste to znovu za %d s.',
            $limit,
            $windowSeconds,
            $retryAfterSeconds,
        ));
    }
}
