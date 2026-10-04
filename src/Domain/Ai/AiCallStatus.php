<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/** Výsledek volání AI; hodnoty odpovídají ENUM `ai_calls.status`. */
enum AiCallStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
}
