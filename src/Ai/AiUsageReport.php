<?php

declare(strict_types=1);

namespace App\Ai;

use App\Domain\Ai\AiCall;
use App\Domain\Ai\AiCallRepository;
use App\Domain\Ai\AiUsageTotals;
use App\Domain\Time\Clock;

/** Data pro přehled `/admin/ai`: dnešní spotřeba, limit a poslední volání. */
final readonly class AiUsageReport
{
    private const int RECENT_LIMIT = 20;
    private const string TIME_ZONE = 'Europe/Prague';

    public function __construct(
        private AiCallRepository $calls,
        private Clock $clock,
        private AiConfig $config,
    ) {}

    /** Spotřeba od dnešní půlnoci (Europe/Prague). */
    public function today(): AiUsageTotals
    {
        $dayStart = $this->clock->now()->setTimezone(new \DateTimeZone(self::TIME_ZONE))->setTime(0, 0);

        return $this->calls->usageSince($dayStart);
    }

    /** @return list<AiCall> posledních 20 volání, nejnovější první */
    public function recent(): array
    {
        return $this->calls->recent(self::RECENT_LIMIT);
    }

    public function dailyLimit(): int
    {
        return $this->config->dailyTokenLimit;
    }

    public function provider(): AiProvider
    {
        return $this->config->provider;
    }

    /** @return array{text: string, cheap: string} */
    public function models(): array
    {
        return ['text' => $this->config->model, 'cheap' => $this->config->cheapModel];
    }
}
