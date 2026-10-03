<?php

declare(strict_types=1);

namespace App\Tests\Unit\Container\Fixtures;

final class NeedsDirectory
{
    public function __construct(public readonly string $directory) {}
}
