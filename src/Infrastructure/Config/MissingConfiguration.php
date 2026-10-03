<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * Chybějící nebo neplatná konfigurace. Hláška smí jmenovat jen proměnnou, nikdy její hodnotu.
 */
final class MissingConfiguration extends \RuntimeException
{
    public static function forVariable(string $variable): self
    {
        return new self(sprintf('Chybí proměnná prostředí %s.', $variable));
    }

    public static function invalidVariable(string $variable): self
    {
        return new self(sprintf('Proměnná prostředí %s má neplatný formát.', $variable));
    }
}
