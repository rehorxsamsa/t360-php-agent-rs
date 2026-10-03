<?php

declare(strict_types=1);

namespace App\Tests\Unit\Container\Fixtures;

final class NeedsLeaf
{
    public function __construct(public readonly Leaf $leaf) {}
}
