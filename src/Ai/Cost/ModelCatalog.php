<?php

declare(strict_types=1);

namespace App\Ai\Cost;

/** Katalog modelů, které smí aplikace volat (načtený z `config/ai-models.php`). */
final readonly class ModelCatalog
{
    /** @param array<string, ModelInfo> $models */
    public function __construct(private array $models) {}

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Soubor ceníku %s neexistuje.', basename($path)));
        }

        $definitions = require $path;
        if (!is_array($definitions)) {
            throw new \RuntimeException(sprintf('Soubor ceníku %s musí vracet pole.', basename($path)));
        }

        $models = [];
        foreach ($definitions as $id => $definition) {
            $models[(string) $id] = self::createInfo((string) $id, $definition);
        }

        return new self($models);
    }

    /** @throws UnknownModel */
    public function get(string $id): ModelInfo
    {
        return $this->models[$id] ?? throw UnknownModel::forId($id);
    }

    public function has(string $id): bool
    {
        return isset($this->models[$id]);
    }

    private static function createInfo(string $id, mixed $definition): ModelInfo
    {
        if (!is_array($definition)) {
            throw new \RuntimeException(sprintf('Model %s v ceníku musí být pole.', $id));
        }

        return new ModelInfo(
            $id,
            self::price($id, $definition, 'input_per_mtok'),
            self::price($id, $definition, 'output_per_mtok'),
            self::price($id, $definition, 'cache_write_per_mtok'),
            self::price($id, $definition, 'cache_read_per_mtok'),
            ($definition['supports_effort'] ?? null) === true,
        );
    }

    /** @param array<mixed> $definition */
    private static function price(string $id, array $definition, string $key): float
    {
        $value = $definition[$key] ?? null;
        if ((!is_int($value) && !is_float($value)) || $value < 0) {
            throw new \RuntimeException(sprintf('Model %s v ceníku: chybí nebo je neplatná cena %s.', $id, $key));
        }

        return (float) $value;
    }
}
