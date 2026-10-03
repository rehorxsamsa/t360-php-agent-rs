<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

interface Middleware
{
    /**
     * @param callable(Request): Response $next další článek řetězu; nezavolá-li ho middleware, požadavek se zastaví
     */
    public function process(Request $request, callable $next): Response;
}
