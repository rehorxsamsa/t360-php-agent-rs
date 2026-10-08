<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/** Vektor (embedding) textu: neprázdný seznam konečných čísel. */
final readonly class Embedding
{
    /** @param list<float> $values */
    public function __construct(public array $values)
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Embedding nesmí být prázdný.');
        }
        foreach ($values as $value) {
            if (!self::isFiniteFloat($value)) {
                throw new \InvalidArgumentException('Embedding smí obsahovat jen konečná čísla typu float.');
            }
        }
    }

    public function dimensions(): int
    {
        return count($this->values);
    }

    /**
     * Typ se kontroluje dřív než is_finite(): PHPDoc `list<float>` jazyk nevynutí a is_finite() by na řetězci
     * hodil TypeError místo srozumitelné \InvalidArgumentException.
     */
    private static function isFiniteFloat(mixed $value): bool
    {
        return is_float($value) && is_finite($value);
    }
}
