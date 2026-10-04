<?php

declare(strict_types=1);

namespace App\Ai\Cost;

/** Model není v ceníku `config/ai-models.php`; bez ceny se nevolá. */
final class UnknownModel extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Model „%s“ není v ceníku config/ai-models.php.', $id));
    }
}
