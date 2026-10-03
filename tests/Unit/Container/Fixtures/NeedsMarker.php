<?php

declare(strict_types=1);

namespace App\Tests\Unit\Container\Fixtures;

final class NeedsMarker
{
    public function __construct(public readonly Marker $marker) {}
}
