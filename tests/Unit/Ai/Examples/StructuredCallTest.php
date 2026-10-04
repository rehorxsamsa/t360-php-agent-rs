<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\InvalidModelOutput;
use App\Ai\Examples\StructuredCall;
use App\Ai\Examples\StructuredOutcome;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\ScriptedLlmClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 006, §1 a AC 15: strukturované volání – dekódování, validace, nejvýše jedno opakování. */
final class StructuredCallTest extends TestCase
{
    private ScriptedLlmClient $llm;

    protected function setUp(): void
    {
        $this->llm = new ScriptedLlmClient();
    }

    /** @return \Closure(array<mixed>): list<string> */
    private static function validator(): \Closure
    {
        return static function (array $data): array {
            $title = $data['title'] ?? null;
            if (!is_string($title) || $title === '') {
                return ['title: povinné pole'];
            }

            return mb_strlen($title) > 10 ? ['title: nejvýše 10 znaků'] : [];
        };
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    private function call(): StructuredOutcome
    {
        return new StructuredCall($this->llm)->run(
            AiFixtures::request(jsonSchema: self::schema(), user: 'Úkol'),
            self::validator(),
        );
    }

    public function test_valid_first_answer_is_returned_without_retry(): void
    {
        $this->llm->push(AiFixtures::response('{"title":"Ahoj"}', input: 10, output: 4, costUsd: 0.0002));

        $outcome = $this->call();

        self::assertSame(['title' => 'Ahoj'], $outcome->data);
        self::assertCount(1, $outcome->responses);
        self::assertSame('{"title":"Ahoj"}', $outcome->rawOutput());
        self::assertSame(10, $outcome->usage()->input);
        self::assertSame(0.0002, $outcome->costUsd());
        self::assertCount(1, $this->llm->requests);
    }

    public function test_invalid_answer_is_retried_with_assistant_and_error_messages(): void
    {
        $this->llm->push(
            AiFixtures::response('{"title":"Příliš dlouhý titulek"}', input: 10, output: 4, costUsd: 0.0002),
            AiFixtures::response('{"title":"Krátký"}', input: 30, output: 3, costUsd: 0.0003),
        );

        $outcome = $this->call();

        self::assertSame(['title' => 'Krátký'], $outcome->data);
        self::assertCount(2, $outcome->responses);
        self::assertSame('{"title":"Krátký"}', $outcome->rawOutput());
        self::assertSame(40, $outcome->usage()->input);
        self::assertSame(7, $outcome->usage()->output);
        self::assertEqualsWithDelta(0.0005, $outcome->costUsd(), 1e-9);

        $retry = $this->llm->requests[1];
        self::assertSame(
            [
                ['role' => 'user', 'content' => 'Úkol'],
                ['role' => 'assistant', 'content' => '{"title":"Příliš dlouhý titulek"}'],
            ],
            array_slice($retry->messages, 0, 2),
        );
        self::assertCount(3, $retry->messages);
        self::assertSame('user', $retry->messages[2]['role']);
        self::assertStringContainsString('title: nejvýše 10 znaků', $retry->messages[2]['content']);
        $first = $this->llm->requests[0];
        self::assertSame($first->model, $retry->model);
        self::assertSame($first->system, $retry->system);
        self::assertSame($first->jsonSchema, $retry->jsonSchema);
        self::assertSame($first->maxTokens, $retry->maxTokens);
        self::assertSame($first->exampleId, $retry->exampleId);
        self::assertSame($first->userId, $retry->userId);
    }

    public function test_invalid_json_is_retried(): void
    {
        $this->llm->pushText('{"title": ', '{"title":"OK"}');

        self::assertSame(['title' => 'OK'], $this->call()->data);
        self::assertCount(2, $this->llm->requests);
    }

    public function test_json_that_is_not_object_is_retried(): void
    {
        $this->llm->pushText('"jen text"', '{"title":"OK"}');

        self::assertSame(['title' => 'OK'], $this->call()->data);
    }

    public function test_two_invalid_answers_throw_with_errors_in_message(): void
    {
        $this->llm->pushText('{"title":""}', '{"title":""}', '{"title":"OK"}');

        try {
            $this->call();
            self::fail('Očekávána výjimka InvalidModelOutput.');
        } catch (InvalidModelOutput $exception) {
            self::assertStringStartsWith('Model ani na druhý pokus nevrátil platná data: ', $exception->getMessage());
            self::assertStringContainsString('title: povinné pole', $exception->getMessage());
        }
        self::assertCount(2, $this->llm->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function stopReasons(): iterable
    {
        yield 'max_tokens' => ['max_tokens'];
        yield 'refusal' => ['refusal'];
    }

    #[DataProvider('stopReasons')]
    public function test_truncated_or_refused_answer_fails_without_retry(string $stopReason): void
    {
        $this->llm->push(AiFixtures::response('{"title":"OK"}', stopReason: $stopReason));
        $this->llm->pushText('{"title":"OK"}');

        $this->expectException(InvalidModelOutput::class);

        try {
            $this->call();
        } finally {
            self::assertCount(1, $this->llm->requests);
        }
    }
}
