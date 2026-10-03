<?php

declare(strict_types=1);

namespace App\Tests\Unit\Container;

use App\Container\Container;
use App\Container\ContainerException;
use App\Domain\Health\DatabaseHealth;
use App\Http\Controller\HealthController;
use App\Tests\Unit\Container\Fixtures\CycleA;
use App\Tests\Unit\Container\Fixtures\Leaf;
use App\Tests\Unit\Container\Fixtures\Marker;
use App\Tests\Unit\Container\Fixtures\NeedsDirectory;
use App\Tests\Unit\Container\Fixtures\NeedsLeaf;
use App\Tests\Unit\Container\Fixtures\NeedsMarker;
use App\Tests\Unit\Container\Fixtures\WithDefault;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function test_autowires_class_dependencies_and_shares_instance(): void
    {
        $container = new Container();

        $first = $container->get(NeedsLeaf::class);

        self::assertInstanceOf(Leaf::class, $first->leaf);
        self::assertSame($first, $container->get(NeedsLeaf::class));
        self::assertSame($first->leaf, $container->get(Leaf::class));
    }

    public function test_factory_supplies_interface_implementation_to_controller(): void
    {
        $health = new class implements DatabaseHealth {
            public function isReachable(): bool
            {
                return true;
            }
        };
        $container = new Container();
        $container->set(DatabaseHealth::class, static fn(Container $c): DatabaseHealth => $health);

        $controller = $container->get(HealthController::class);

        self::assertInstanceOf(HealthController::class, $controller);
        self::assertSame($health, $container->get(DatabaseHealth::class));
    }

    public function test_uses_default_value_for_scalar_parameter_with_default(): void
    {
        self::assertSame('vychozi', new Container()->get(WithDefault::class)->label);
    }

    public function test_unresolvable_scalar_parameter_names_class_and_parameter(): void
    {
        try {
            new Container()->get(NeedsDirectory::class);
            self::fail('Očekávána výjimka ContainerException.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString(NeedsDirectory::class, $exception->getMessage());
            self::assertStringContainsString('$directory', $exception->getMessage());
        }
    }

    public function test_interface_without_factory_throws_container_exception(): void
    {
        $this->expectException(ContainerException::class);

        new Container()->get(NeedsMarker::class);
    }

    public function test_interface_itself_without_factory_throws_container_exception(): void
    {
        $this->expectException(ContainerException::class);

        new Container()->get(Marker::class);
    }

    public function test_circular_dependency_reports_chain_instead_of_recursing(): void
    {
        try {
            new Container()->get(CycleA::class);
            self::fail('Očekávána výjimka ContainerException.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString('CycleA -> ', $exception->getMessage());
            self::assertStringContainsString('CycleB -> ', $exception->getMessage());
            self::assertMatchesRegularExpression('/CycleA -> CycleB -> (.*\\\\)?CycleA/', $exception->getMessage());
        }
    }

    public function test_get_container_returns_itself(): void
    {
        $container = new Container();

        self::assertSame($container, $container->get(Container::class));
    }

    public function test_has_reports_registered_factory(): void
    {
        $container = new Container();
        self::assertFalse($container->has(Marker::class));

        $container->set(Marker::class, static fn(): Marker => new class implements Marker {});

        self::assertTrue($container->has(Marker::class));
    }

    public function test_factory_is_called_once(): void
    {
        $calls = 0;
        $container = new Container();
        $container->set(Leaf::class, static function () use (&$calls): Leaf {
            $calls++;

            return new Leaf();
        });

        $container->get(Leaf::class);
        $container->get(Leaf::class);

        self::assertSame(1, $calls);
    }
}
