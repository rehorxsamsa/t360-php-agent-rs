<?php

declare(strict_types=1);

namespace App\Ai;

/** Denní limit tokenů by byl překročen; volání se vůbec neodeslalo. */
final class AiBudgetExceeded extends \RuntimeException
{
    public static function forLimit(int $limit, int $used, int $reserved): self
    {
        return new self(sprintf(
            'Denní limit AI tokenů (%s) by byl překročen: dnes použito %s, požadavek si rezervuje až %s. '
            . 'Zkuste to zítra nebo zvyšte AI_DENNI_LIMIT_TOKENU.',
            self::format($limit),
            self::format($used),
            self::format($reserved),
        ));
    }

    private static function format(int $number): string
    {
        return number_format($number, 0, ',', ' ');
    }
}
