<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Ai\Examples\ExampleResult;
use App\Domain\Ai\TokenUsage;
use App\Http\Session\ExampleResultStash;
use App\Tests\Unit\Support\ArraySession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 006, §2 a AC 25: výsledek příkladu přežije jedno přesměrování (PRG) a zobrazí se jen jednou. */
final class ExampleResultStashTest extends TestCase
{
    private ArraySession $session;
    private ExampleResultStash $stash;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->stash = new ExampleResultStash($this->session);
    }

    private static function sample(string $exampleId = '01'): ExampleResult
    {
        return new ExampleResult(
            exampleId: $exampleId,
            fields: [['label' => 'Perex', 'value' => 'Krátký perex.']],
            warnings: [],
            rawOutput: 'Krátký perex.',
            usage: new TokenUsage(10, 5),
            costUsd: 0.00007,
            model: 'claude-sonnet-5-5',
            provider: 'fake',
            calls: 1,
        );
    }

    public function test_put_stores_json_under_ai_result_key(): void
    {
        $this->stash->put(self::sample());

        self::assertIsString($this->session->data['ai_result'] ?? null);
        self::assertIsArray(json_decode((string) $this->session->data['ai_result'], true));
    }

    public function test_pull_returns_result_once(): void
    {
        $this->stash->put(self::sample());

        $pulled = $this->stash->pull('01');

        self::assertNotNull($pulled);
        self::assertSame([['label' => 'Perex', 'value' => 'Krátký perex.']], $pulled->result->fields);
        self::assertSame(0.00007, $pulled->result->costUsd);
        self::assertNull($this->stash->pull('01'));
        self::assertArrayNotHasKey('ai_result', $this->session->data);
    }

    public function test_result_of_other_example_is_discarded(): void
    {
        $this->stash->put(self::sample('02'));

        self::assertNull($this->stash->pull('01'));
        self::assertArrayNotHasKey('ai_result', $this->session->data);
    }

    public function test_corrupted_data_is_ignored_and_removed(): void
    {
        $this->session->data['ai_result'] = '{"exampleId":"01","fields":"<script>"';

        self::assertNull($this->stash->pull('01'));
        self::assertArrayNotHasKey('ai_result', $this->session->data);
    }

    public function test_wrong_shape_is_ignored_and_removed(): void
    {
        $this->session->data['ai_result'] = '{"neco":"jineho"}';

        self::assertNull($this->stash->pull('01'));
        self::assertArrayNotHasKey('ai_result', $this->session->data);
    }

    public function test_form_choices_survive_redirect_with_result(): void
    {
        $this->stash->put(self::sample('05'), '5', 'claude-haiku-4-5-20251001');

        $pulled = $this->stash->pull('05');

        self::assertNotNull($pulled);
        self::assertSame('05', $pulled->result->exampleId);
        self::assertSame('5', $pulled->article);
        self::assertSame('claude-haiku-4-5-20251001', $pulled->model);
    }

    public function test_choices_default_to_empty_strings(): void
    {
        $this->stash->put(self::sample());

        $pulled = $this->stash->pull('01');

        self::assertNotNull($pulled);
        self::assertSame('', $pulled->article);
        self::assertSame('', $pulled->model);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEnvelopes(): iterable
    {
        $result = json_encode(self::sample()->toArray(), JSON_THROW_ON_ERROR);

        yield 'result without envelope' => [$result];
        yield 'article is not a string' => ['{"result":' . $result . ',"article":["5"],"model":""}'];
        yield 'model is not a string' => ['{"result":' . $result . ',"article":"demo","model":5}'];
        yield 'missing choices' => ['{"result":' . $result . '}'];
        yield 'result is not an object' => ['{"result":"<script>","article":"demo","model":""}'];
    }

    #[DataProvider('invalidEnvelopes')]
    public function test_invalid_envelope_is_ignored_and_removed(string $stored): void
    {
        $this->session->data['ai_result'] = $stored;

        self::assertNull($this->stash->pull('01'));
        self::assertArrayNotHasKey('ai_result', $this->session->data);
    }

    public function test_empty_session_gives_null(): void
    {
        self::assertNull($this->stash->pull('01'));
    }
}
