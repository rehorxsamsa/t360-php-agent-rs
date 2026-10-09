<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

use App\Domain\Ai\RateLimit;

/**
 * Limity AI tras v administraci (plán 013, ADR-0013) z proměnných prostředí ve tvaru `počet/sekundy`.
 * Prázdná hodnota = výchozí limit; neplatná hodnota = MissingConfiguration se jménem proměnné (bez hodnoty).
 */
final readonly class AiRateLimitConfig
{
    public const string DEFAULT_STANDARD = '10/60';
    public const string DEFAULT_HEAVY = '3/600';

    private const string STANDARD_VARIABLE = 'AI_LIMIT_POZADAVKU';
    private const string HEAVY_VARIABLE = 'AI_LIMIT_NAROCNYCH';

    /** Kladná celá čísla bez úvodních nul; rozsah hlídá RateLimit (1–9999 požadavků, 1–86 400 s). */
    private const string PATTERN = '~^([1-9][0-9]{0,5})/([1-9][0-9]{0,5})\z~';

    public function __construct(
        public RateLimit $standard,
        public RateLimit $heavy,
    ) {}

    /**
     * @param array<string, string> $environment typicky výsledek getenv()
     * @throws MissingConfiguration neplatná hodnota (hláška jmenuje jen proměnnou, nikdy hodnotu)
     */
    public static function fromEnvironment(array $environment): self
    {
        return new self(
            self::limit($environment, self::STANDARD_VARIABLE, self::DEFAULT_STANDARD),
            self::limit($environment, self::HEAVY_VARIABLE, self::DEFAULT_HEAVY),
        );
    }

    /** @param array<string, string> $environment */
    private static function limit(array $environment, string $variable, string $default): RateLimit
    {
        $value = trim($environment[$variable] ?? '');

        if (preg_match(self::PATTERN, $value === '' ? $default : $value, $parts) !== 1) {
            throw MissingConfiguration::invalidVariable($variable);
        }

        try {
            return new RateLimit((int) $parts[1], (int) $parts[2]);
        } catch (\InvalidArgumentException) {
            throw MissingConfiguration::invalidVariable($variable);
        }
    }
}
