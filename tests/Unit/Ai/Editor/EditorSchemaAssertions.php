<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Editor;

use PHPUnit\Framework\Assert;

/**
 * Plán 010, §2: schémata pro `output_config.format` – na každé úrovni objektu `additionalProperties: false`
 * a `required` = všechny klíče; bez `maxLength`/`maxItems`/`minLength`/`minItems` (délky hlídá PHP).
 */
trait EditorSchemaAssertions
{
    /** @param array<mixed> $schema */
    private static function assertStrictSchema(array $schema, string $path = '$'): void
    {
        foreach (['maxLength', 'minLength', 'maxItems', 'minItems'] as $forbidden) {
            Assert::assertArrayNotHasKey($forbidden, $schema, $path . ': ' . $forbidden);
        }

        if (($schema['type'] ?? null) === 'object') {
            Assert::assertFalse($schema['additionalProperties'] ?? null, $path . ': additionalProperties musí být false');
            Assert::assertIsArray($schema['properties'] ?? null, $path . ': chybí properties');
            $keys = array_keys($schema['properties']);
            $required = $schema['required'] ?? null;
            Assert::assertIsArray($required, $path . ': chybí required');
            sort($keys);
            sort($required);
            Assert::assertSame($keys, $required, $path . ': required musí obsahovat všechny klíče');
            foreach ($schema['properties'] as $key => $property) {
                Assert::assertIsArray($property);
                self::assertStrictSchema($property, $path . '.' . $key);
            }
        }

        if (($schema['type'] ?? null) === 'array') {
            Assert::assertIsArray($schema['items'] ?? null, $path . ': chybí items');
            self::assertStrictSchema($schema['items'], $path . '[]');
        }
    }

    /**
     * Vnořená část schématu podle cesty klíčů (každý krok musí být pole).
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private static function at(array $schema, string ...$path): array
    {
        $node = $schema;
        $walked = [];
        foreach ($path as $key) {
            $walked[] = $key;
            $next = $node[$key] ?? null;
            Assert::assertIsArray($next, 'Schéma: chybí ' . implode('.', $walked));
            $node = $next;
        }

        return $node;
    }

    /**
     * @param mixed $properties `properties` schématu
     * @return list<int|string>
     */
    private static function sortedKeys(mixed $properties): array
    {
        $keys = is_array($properties) ? array_keys($properties) : [];
        sort($keys);

        return $keys;
    }

    /**
     * Některá chyba obsahuje daný text (tvar `FieldRules`: „pole: popis“, u vnořených „sections[0].heading: …“).
     *
     * @param list<string> $errors
     */
    private static function assertErrorAbout(string $needle, array $errors): void
    {
        foreach ($errors as $error) {
            if (str_contains($error, $needle)) {
                return;
            }
        }

        Assert::fail(sprintf('Žádná chyba neobsahuje „%s“: %s', $needle, var_export($errors, true)));
    }
}
