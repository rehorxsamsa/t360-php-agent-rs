<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Editor;

use App\Ai\Editor\ArticleDraft;
use App\Ai\Editor\DraftProposal;
use App\Ai\Examples\ExampleResult;
use App\Domain\Ai\TokenUsage;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 010, AC 19 a §2: návrh ke schválení – převod do pole a zpět s kontrolou tvaru i pravidel konceptu. */
final class DraftProposalTest extends TestCase
{
    public const string TOPIC = 'Jak Docker usnadňuje práci malé redakce';

    public static function sample(string $exampleId = '09'): DraftProposal
    {
        return new DraftProposal(
            self::TOPIC,
            ArticleDraft::fromData(AiFixtures::editorDraft()),
            new ExampleResult(
                exampleId: $exampleId,
                fields: [['label' => 'Téma', 'value' => self::TOPIC], ['label' => 'Titulek', 'value' => 'Docker v malé redakci']],
                warnings: ['Sebekontrola našla závažný nález – projděte ho před uložením.'],
                rawOutput: '{"title":"Docker v malé redakci"}',
                usage: new TokenUsage(40, 20),
                costUsd: 0.0004,
                model: AiFixtures::SONNET,
                provider: 'fake',
                calls: 4,
            ),
        );
    }

    /**
     * Pole po cestě přes JSON (jako ze session); `draft` a `result` jako pole.
     *
     * @return array{topic: mixed, draft: array<mixed>, result: array<mixed>}
     */
    public static function sampleArray(): array
    {
        $data = json_decode(json_encode(self::sample()->toArray(), JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['draft'] ?? null);
        self::assertIsArray($data['result'] ?? null);

        return ['topic' => $data['topic'] ?? null, 'draft' => $data['draft'], 'result' => $data['result']] + $data;
    }

    /**
     * PŘEDPOKLAD (plán uvádí jen `toArray(): array<string, mixed>`): klíče kopírují vlastnosti – `topic`,
     * `draft` {title, excerpt, body} a `result` = `ExampleResult::toArray()`. Na tomto tvaru stojí testy níže.
     */
    public function test_to_array_mirrors_properties(): void
    {
        /** @var array<string, mixed> $data */
        $data = self::sample()->toArray();

        self::assertSame(self::TOPIC, $data['topic'] ?? null);
        self::assertEquals(AiFixtures::editorDraft(), $data['draft'] ?? null);
        self::assertEquals(self::sample()->result->toArray(), $data['result'] ?? null);
    }

    public function test_round_trip_through_json_gives_equal_proposal(): void
    {
        $restored = DraftProposal::fromArray(self::sampleArray());

        self::assertEquals(self::sample(), $restored);
    }

    public function test_proposal_has_no_state_and_is_final_readonly(): void
    {
        $class = new \ReflectionClass(DraftProposal::class);
        $properties = array_map(static fn(\ReflectionProperty $p): string => $p->getName(), $class->getProperties());
        sort($properties);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertSame(['draft', 'result', 'topic'], $properties);
    }

    /** @return iterable<string, array{\Closure(array{topic: mixed, draft: array<mixed>, result: array<mixed>}): array<mixed>}> */
    public static function invalidShapes(): iterable
    {
        yield 'other example' => [static fn(array $data): array => self::merged($data, 'result', ['exampleId' => '08'])];
        yield 'missing topic' => [static function (array $data): array {
            unset($data['topic']);

            return $data;
        }];
        yield 'topic is not string' => [static function (array $data): array {
            $data['topic'] = ['x'];

            return $data;
        }];
        yield 'draft is not array' => [static function (array $data): array {
            $data['draft'] = '<script>';

            return $data;
        }];
        yield 'draft body too short' => [static fn(array $data): array => self::merged($data, 'draft', ['body' => "## A\n\n## B\n\nKrátké."])];
        yield 'draft title too long' => [static fn(array $data): array => self::merged($data, 'draft', ['title' => str_repeat('t', 201)])];
        yield 'result is broken' => [static function (array $data): array {
            $data['result'] = ['exampleId' => '09'];

            return $data;
        }];
        yield 'empty' => [static fn(array $data): array => []];
    }

    /**
     * Kopie dat s přepsanými klíči ve vnořeném poli `$key`.
     *
     * @param array<mixed> $data
     * @param array<string, mixed> $values
     * @return array<mixed>
     */
    private static function merged(array $data, string $key, array $values): array
    {
        $part = $data[$key] ?? null;
        self::assertIsArray($part);
        $data[$key] = $values + $part;

        return $data;
    }

    /** @param \Closure(array{topic: mixed, draft: array<mixed>, result: array<mixed>}): array<mixed> $mutate */
    #[DataProvider('invalidShapes')]
    public function test_invalid_shape_gives_null(\Closure $mutate): void
    {
        self::assertNull(DraftProposal::fromArray($mutate(self::sampleArray())));
    }
}
