<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/** Počty tokenů jednoho (nebo více sečtených) volání AI. */
final readonly class TokenUsage
{
    public function __construct(
        public int $input,
        public int $output,
        public int $cacheWrite = 0,
        public int $cacheRead = 0,
    ) {
        if ($input < 0 || $output < 0 || $cacheWrite < 0 || $cacheRead < 0) {
            throw new \InvalidArgumentException('Počet tokenů nesmí být záporný.');
        }
    }

    public function total(): int
    {
        return $this->input + $this->output + $this->cacheWrite + $this->cacheRead;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->output + $other->output,
            $this->cacheWrite + $other->cacheWrite,
            $this->cacheRead + $other->cacheRead,
        );
    }
}
