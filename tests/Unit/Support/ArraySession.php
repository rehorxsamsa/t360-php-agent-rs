<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Http\Session\Session;

/** Testovací session v poli; počítá volání regenerateId() a invalidate(). */
final class ArraySession implements Session
{
    /** @var array<string, string|int> */
    public array $data = [];
    public int $regenerateCount = 0;
    public int $invalidateCount = 0;

    public function get(string $key): string|int|null
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, string|int $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function pull(string $key): string|int|null
    {
        $value = $this->data[$key] ?? null;
        unset($this->data[$key]);

        return $value;
    }

    public function regenerateId(): void
    {
        ++$this->regenerateCount;
    }

    public function invalidate(): void
    {
        $this->data = [];
        ++$this->invalidateCount;
    }
}
