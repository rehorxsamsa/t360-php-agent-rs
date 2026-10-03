<?php

declare(strict_types=1);

namespace App\Container;

/**
 * Minimální DI kontejner: autowiring přes reflexi konstruktoru, sdílené instance (singletony)
 * a ruční továrny pro rozhraní a skalární parametry.
 *
 * Volá se jen s názvy tříd z kódu (trasy, konfigurace), nikdy se vstupem uživatele.
 */
final class Container
{
    /** @var array<class-string, \Closure(Container): object> */
    private array $factories = [];

    /** @var array<class-string, object> */
    private array $instances = [];

    /** @var list<class-string> právě sestavované služby (detekce zacyklení) */
    private array $resolving = [];

    public function __construct()
    {
        // get(Container::class) vrací kontejner sám.
        $this->instances[self::class] = $this;
    }

    /**
     * @param class-string $id
     * @param \Closure(Container): object $factory
     */
    public function set(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /** Má služba ručně zaregistrovanou továrnu? (autowiring se nepočítá) */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        $instance = $this->instances[$id] ?? $this->build($id);
        if (!$instance instanceof $id) {
            throw new ContainerException(sprintf('Továrna pro %s vrátila objekt jiného typu.', $id));
        }

        return $instance;
    }

    /**
     * @param class-string $id
     */
    private function build(string $id): object
    {
        if (in_array($id, $this->resolving, true)) {
            throw new ContainerException(sprintf(
                'Zacyklená závislost: %s',
                implode(' -> ', array_map($this->shortName(...), [...$this->resolving, $id])),
            ));
        }

        $this->resolving[] = $id;
        try {
            $instance = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->autowire($id);
        } finally {
            array_pop($this->resolving);
        }

        return $this->instances[$id] = $instance;
    }

    /**
     * @param class-string $id
     */
    private function autowire(string $id): object
    {
        if (!class_exists($id)) {
            throw new ContainerException(sprintf(
                'Pro %s není zaregistrována továrna a nejde o existující třídu (rozhraní se řeší přes set()).',
                $id,
            ));
        }

        $class = new \ReflectionClass($id);
        if (!$class->isInstantiable()) {
            throw new ContainerException(sprintf('Třídu %s nelze vytvořit (abstraktní) a nemá továrnu.', $id));
        }

        $arguments = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $arguments[] = $this->resolveParameter($id, $parameter);
        }

        return $class->newInstanceArgs($arguments);
    }

    private function resolveParameter(string $class, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            /** @var class-string $dependency */
            $dependency = $type->getName();

            return $this->get($dependency);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ContainerException(sprintf(
            'Třída %s: nelze určit hodnotu parametru $%s (typ %s) – zaregistrujte továrnu přes set().',
            $class,
            $parameter->getName(),
            $type === null ? 'bez typu' : (string) $type,
        ));
    }

    /** Název třídy bez jmenného prostoru (čitelný řetězec zacyklení). */
    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
