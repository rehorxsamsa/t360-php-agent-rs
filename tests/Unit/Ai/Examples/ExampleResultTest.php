<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\ExampleResult;
use App\Domain\Ai\TokenUsage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 006, §2: výsledek příkladu a jeho serializace do session (kontrola tvaru). */
final class ExampleResultTest extends TestCase
{
    private static function sample(): ExampleResult
    {
        return new ExampleResult(
            exampleId: '02',
            fields: [['label' => 'Titulek', 'value' => 'SEO <b>'], ['label' => 'Meta popis', 'value' => 'Popis']],
            warnings: ['Varování.'],
            rawOutput: '{"title":"SEO <b>"}',
            usage: new TokenUsage(100, 20, 3, 4),
            costUsd: 0.000512,
            model: 'claude-sonnet-5-5',
            provider: 'fake',
            calls: 2,
        );
    }

    public function test_round_trip_through_array_and_json(): void
    {
        $array = json_decode(json_encode(self::sample()->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($array);

        $restored = ExampleResult::fromArray($array);

        self::assertNotNull($restored);
        self::assertSame('02', $restored->exampleId);
        self::assertSame(self::sample()->fields, $restored->fields);
        self::assertSame(['Varování.'], $restored->warnings);
        self::assertSame('{"title":"SEO <b>"}', $restored->rawOutput);
        self::assertSame([100, 20, 3, 4], [$restored->usage->input, $restored->usage->output, $restored->usage->cacheWrite, $restored->usage->cacheRead]);
        self::assertSame(0.000512, $restored->costUsd);
        self::assertSame('claude-sonnet-5-5', $restored->model);
        self::assertSame('fake', $restored->provider);
        self::assertSame(2, $restored->calls);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function corruptedArrays(): iterable
    {
        // Názvy klíčů toArray() plán neurčuje – poškozené varianty se odvozují obecně.
        $valid = self::sample()->toArray();

        yield 'empty' => [[]];
        foreach (array_keys($valid) as $key) {
            $without = $valid;
            unset($without[$key]);
            yield 'missing ' . $key => [$without];

            $wrongType = $valid;
            $wrongType[$key] = is_array($valid[$key]) ? 'x' : ['x'];
            yield 'wrong type of ' . $key => [$wrongType];
        }
        $fieldsKey = array_search(self::sample()->fields, $valid, true);
        if (is_string($fieldsKey)) {
            $badField = $valid;
            $badField[$fieldsKey] = [['label' => 'A']];
            yield 'field without value' => [$badField];
        }
    }

    /** @param array<mixed> $data */
    #[DataProvider('corruptedArrays')]
    public function test_corrupted_data_gives_null(array $data): void
    {
        self::assertNull(ExampleResult::fromArray($data));
    }
}
