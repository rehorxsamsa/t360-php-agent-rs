<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Fixtures;

use App\Http\Request;
use App\Http\Response;

final class ThrowingController
{
    public function index(Request $request): Response
    {
        throw new \RuntimeException('tajny-detail');
    }
}
