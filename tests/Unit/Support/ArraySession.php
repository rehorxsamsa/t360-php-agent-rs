<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Http\Session\Session;

/**
 * Testovací session v poli; počítá volání regenerateId() a invalidate(). `release()` (plán 008) nastaví
 * příznak `released` a každý zápis po uvolnění se započítá do `writesAfterRelease` (nesmí nastat).
 */
final class ArraySession implements Session
{
    /** @var array<string, string|int> */
    public array $data = [];
    public int $regenerateCount = 0;
    public int $invalidateCount = 0;
    public bool $released = false;
    public int $releaseCount = 0;
    public int $writesAfterRelease = 0;

    public function get(string $key): string|int|null
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, string|int $value): void
    {
        $this->guardWrite();
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->guardWrite();
        unset($this->data[$key]);
    }

    public function pull(string $key): string|int|null
    {
        $this->guardWrite();
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

    public function release(): void
    {
        $this->released = true;
        ++$this->releaseCount;
    }

    private function guardWrite(): void
    {
        if ($this->released) {
            ++$this->writesAfterRelease;
        }
    }
}
