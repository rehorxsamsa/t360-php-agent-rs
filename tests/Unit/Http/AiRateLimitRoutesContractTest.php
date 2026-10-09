<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Application\Ai\AiRateBucket;
use App\Http\Controller\Admin\AiController;
use App\Http\Controller\Admin\AiEditorController;
use App\Http\Controller\Admin\AskNewsroomController;
use App\Http\Controller\Admin\SemanticSearchController;
use App\Http\Controller\Admin\WritingAssistantController;
use App\Http\Middleware\AiRateLimitMiddleware;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Plán 013, AC 19 (riziko R1): kontrakt mezi config/routes.php a mapami handlerů v AiRateLimitMiddleware.
 * Každá `POST /admin/ai…` trasa musí mít rozhodnutí o limitu (LIMITED_HANDLERS, nebo EXEMPT_HANDLERS) a každý handler
 * z map musí v routes.php existovat. Nová AI trasa bez rozhodnutí tak neprojde testy.
 *
 * Klíč obou map je `Třída::metoda` s plně kvalifikovaným názvem třídy (`AiController::class . '::run'`).
 * Hodnota LIMITED_HANDLERS nese kbelík: buď přímo `AiRateBucket`, nebo pole s `AiRateBucket` (např. s příznakem JSON).
 */
final class AiRateLimitRoutesContractTest extends TestCase
{
    /** @var array<string, string> rozhodnutí z plánu 013 (handler → hodnota kbelíku) */
    private const array EXPECTED_BUCKETS = [
        AiController::class . '::run' => 'ai',
        WritingAssistantController::class . '::stream' => 'ai',
        AskNewsroomController::class . '::ask' => 'ai',
        SemanticSearchController::class . '::ask' => 'ai',
        SemanticSearchController::class . '::reindex' => 'ai',
        AiEditorController::class . '::draft' => 'ai_heavy',
    ];

    /**
     * POST trasy pod /admin/ai z config/routes.php: handler `FQCN::metoda` → cesta.
     *
     * @return array<string, string>
     */
    private static function aiPostRoutes(): array
    {
        $source = file_get_contents(AiFixtures::root() . '/config/routes.php');
        self::assertIsString($source);

        $aliases = [];
        preg_match_all('~^use\s+([A-Za-z0-9_\\\\]+?)(?:\s+as\s+(\w+))?;~m', $source, $uses, PREG_SET_ORDER);
        foreach ($uses as $use) {
            $fqcn = $use[1];
            $alias = $use[2] ?? substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            $aliases[$alias] = $fqcn;
        }

        $count = preg_match_all(
            '~\$router->post\(\s*\'(/admin/ai(?:/[^\']*)?)\'\s*,\s*\[\s*(\w+)::class\s*,\s*\'(\w+)\'\s*\]\s*\)~',
            $source,
            $matches,
            PREG_SET_ORDER,
        );
        self::assertGreaterThan(0, $count, 'V routes.php nebyla nalezena žádná POST trasa /admin/ai….');

        $routes = [];
        foreach ($matches as [, $path, $class, $method]) {
            self::assertArrayHasKey($class, $aliases, sprintf('Třída %s z trasy %s nemá v routes.php `use`.', $class, $path));
            $routes[$aliases[$class] . '::' . $method] = $path;
        }

        // Pojistka parseru: každý řádek `$router->post('/admin/ai…` se musel rozpoznat.
        self::assertSame(
            substr_count($source, "\$router->post('/admin/ai"),
            $count,
            'Některou POST trasu /admin/ai… parser testu nerozpoznal (jiný zápis handleru?).',
        );

        return $routes;
    }

    private static function bucketOf(mixed $value, string $handler): AiRateBucket
    {
        if ($value instanceof AiRateBucket) {
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item instanceof AiRateBucket) {
                    return $item;
                }
            }
        }

        self::fail(sprintf('LIMITED_HANDLERS[%s] nenese AiRateBucket.', $handler));
    }

    /** @return array<string, AiRateBucket> */
    private static function limited(): array
    {
        $limited = [];
        foreach (AiRateLimitMiddleware::LIMITED_HANDLERS as $handler => $value) {
            $limited[$handler] = self::bucketOf($value, $handler);
        }

        return $limited;
    }

    /** @return list<string> */
    private static function exempt(): array
    {
        $exempt = [];
        foreach (AiRateLimitMiddleware::EXEMPT_HANDLERS as $handler) {
            $exempt[] = $handler;
        }

        return $exempt;
    }

    public function test_routes_file_has_all_seven_limited_and_two_exempt_ai_post_routes(): void
    {
        self::assertSame(
            [
                AiController::class . '::run',
                AiEditorController::class . '::discard',
                AiEditorController::class . '::draft',
                AiEditorController::class . '::save',
                AskNewsroomController::class . '::ask',
                SemanticSearchController::class . '::ask',
                SemanticSearchController::class . '::reindex',
                WritingAssistantController::class . '::stream',
            ],
            self::sorted(array_keys(self::aiPostRoutes())),
        );
    }

    public function test_every_ai_post_route_has_a_rate_limit_decision(): void
    {
        $decided = [...array_keys(self::limited()), ...self::exempt()];

        foreach (self::aiPostRoutes() as $handler => $path) {
            self::assertContains(
                $handler,
                $decided,
                sprintf('POST %s (%s) není v LIMITED_HANDLERS ani v EXEMPT_HANDLERS – rozhodněte o limitu.', $path, $handler),
            );
        }
    }

    public function test_every_handler_in_both_maps_exists_as_ai_post_route(): void
    {
        $routes = self::aiPostRoutes();

        foreach ([...array_keys(self::limited()), ...self::exempt()] as $handler) {
            self::assertArrayHasKey($handler, $routes, sprintf('%s z mapy middleware v routes.php jako POST /admin/ai… není.', $handler));
            [$class, $method] = explode('::', $handler, 2);
            self::assertTrue(method_exists($class, $method), $handler . ' neexistuje.');
        }
    }

    public function test_no_handler_is_both_limited_and_exempt(): void
    {
        self::assertSame([], array_values(array_intersect(array_keys(self::limited()), self::exempt())));
    }

    public function test_only_save_and_discard_of_ai_editor_are_exempt(): void
    {
        self::assertSame(
            [AiEditorController::class . '::discard', AiEditorController::class . '::save'],
            self::sorted(self::exempt()),
        );
    }

    public function test_handlers_are_assigned_to_buckets_from_the_plan(): void
    {
        $actual = array_map(static fn(AiRateBucket $bucket): string => $bucket->value, self::limited());
        ksort($actual);
        $expected = self::EXPECTED_BUCKETS;
        ksort($expected);

        self::assertSame($expected, $actual);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
