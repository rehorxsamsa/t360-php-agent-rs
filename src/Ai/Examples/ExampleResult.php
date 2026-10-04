<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Domain\Ai\TokenUsage;

/** Výsledek příkladu pro zobrazení (pole, varování, surová odpověď, spotřeba). Dá se uložit do session. */
final readonly class ExampleResult
{
    /**
     * @param list<array{label: string, value: string}> $fields
     * @param list<string> $warnings
     */
    public function __construct(
        public string $exampleId,
        public array $fields,
        public array $warnings,
        public string $rawOutput,
        public TokenUsage $usage,
        public float $costUsd,
        public string $model,
        public string $provider,
        public int $calls,
    ) {}

    /**
     * @return array{
     *     exampleId: string,
     *     fields: list<array{label: string, value: string}>,
     *     warnings: list<string>,
     *     rawOutput: string,
     *     usage: array{input: int, output: int, cacheWrite: int, cacheRead: int},
     *     costUsd: float,
     *     model: string,
     *     provider: string,
     *     calls: int
     * }
     */
    public function toArray(): array
    {
        return [
            'exampleId' => $this->exampleId,
            'fields' => $this->fields,
            'warnings' => $this->warnings,
            'rawOutput' => $this->rawOutput,
            'usage' => [
                'input' => $this->usage->input,
                'output' => $this->usage->output,
                'cacheWrite' => $this->usage->cacheWrite,
                'cacheRead' => $this->usage->cacheRead,
            ],
            'costUsd' => $this->costUsd,
            'model' => $this->model,
            'provider' => $this->provider,
            'calls' => $this->calls,
        ];
    }

    /**
     * Opačný směr k `toArray()` s kontrolou tvaru (data ze session jsou nedůvěryhodná).
     *
     * @param array<mixed> $data
     * @return self|null null při neplatném tvaru
     */
    public static function fromArray(array $data): ?self
    {
        $fields = self::fields($data['fields'] ?? null);
        $warnings = self::strings($data['warnings'] ?? null);
        $usage = $data['usage'] ?? null;
        $cost = $data['costUsd'] ?? null;

        if (
            $fields === null
            || $warnings === null
            || !is_array($usage)
            || !is_string($data['exampleId'] ?? null)
            || !is_string($data['rawOutput'] ?? null)
            || !is_string($data['model'] ?? null)
            || !is_string($data['provider'] ?? null)
            || !is_int($data['calls'] ?? null)
            || (!is_int($cost) && !is_float($cost))
        ) {
            return null;
        }

        $tokens = [];
        foreach (['input', 'output', 'cacheWrite', 'cacheRead'] as $key) {
            $value = $usage[$key] ?? null;
            if (!is_int($value) || $value < 0) {
                return null;
            }
            $tokens[$key] = $value;
        }

        return new self(
            $data['exampleId'],
            $fields,
            $warnings,
            $data['rawOutput'],
            new TokenUsage($tokens['input'], $tokens['output'], $tokens['cacheWrite'], $tokens['cacheRead']),
            (float) $cost,
            $data['model'],
            $data['provider'],
            $data['calls'],
        );
    }

    /** @return list<array{label: string, value: string}>|null */
    private static function fields(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $fields = [];
        foreach ($value as $field) {
            if (!is_array($field) || !is_string($field['label'] ?? null) || !is_string($field['value'] ?? null)) {
                return null;
            }
            $fields[] = ['label' => $field['label'], 'value' => $field['value']];
        }

        return $fields;
    }

    /** @return list<string>|null */
    private static function strings(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }
            $strings[] = $item;
        }

        return $strings;
    }
}
