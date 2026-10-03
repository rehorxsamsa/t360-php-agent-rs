<?php

declare(strict_types=1);

namespace App\Tests\Unit\Container\Fixtures;

final class CycleB
{
    public function __construct(public readonly CycleA $a) {}
}
