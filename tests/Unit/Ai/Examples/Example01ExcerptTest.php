<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\InvalidModelOutput;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/** Plán 006, AC 14: příklad 01 – perex na jedno kliknutí. */
final class Example01ExcerptTest extends ExampleTestCase
{
    public function test_trimmed_text_becomes_excerpt_field(): void
    {
        $this->llm->push(AiFixtures::response('  Krátký perex.  ', input: 120, output: 8, costUsd: 0.00032));

        $result = $this->runExample('01');

        self::assertSame([['label' => 'Perex', 'value' => 'Krátký perex.']], $result->fields);
        self::assertSame([], $result->warnings);
        self::assertSame('01', $result->exampleId);
        self::assertStringContainsString('Krátký perex.', $result->rawOutput);
        self::assertSame(120, $result->usage->input);
        self::assertSame(8, $result->usage->output);
        self::assertSame(0.00032, $result->costUsd);
        self::assertSame('claude-sonnet-5-5', $result->model);
        self::assertSame('fake', $result->provider);
        self::assertSame(1, $result->calls);
        self::assertCount(1, $this->llm->requests);
    }

    public function test_long_excerpt_is_cut_at_word_boundary_with_ellipsis_and_warning(): void
    {
        $long = implode(' ', array_fill(0, 45, 'perexové')); // 45 × 8 + 44 mezer = 404 znaků
        self::assertSame(404, mb_strlen($long));
        $this->llm->pushText($long);

        $result = $this->runExample('01');
        $value = self::field($result, 'Perex') ?? '';

        self::assertLessThanOrEqual(300, mb_strlen($value));
        self::assertStringEndsWith('…', $value);
        $kept = rtrim(mb_substr($value, 0, -1));
        self::assertPrefix($kept, $long);
        self::assertGreaterThan(200, mb_strlen($kept), 'Zkrácení má zachovat většinu textu.');
        self::assertSame(' ', mb_substr($long, mb_strlen($kept), 1), 'Řez musí být na hranici slova.');
        self::assertSame(['Model vrátil delší perex, zkráceno na 300 znaků.'], $result->warnings);
    }

    public function test_excerpt_of_exactly_300_characters_is_kept(): void
    {
        $text = str_repeat('a', 300);
        $this->llm->pushText($text);

        $result = $this->runExample('01');

        self::assertSame($text, self::field($result, 'Perex'));
        self::assertSame([], $result->warnings);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidOutputs(): iterable
    {
        yield 'empty' => ['', 'end_turn'];
        yield 'whitespace' => ["  \n ", 'end_turn'];
        yield 'refusal' => ['Tohle neudělám.', 'refusal'];
    }

    #[DataProvider('invalidOutputs')]
    public function test_empty_or_refused_output_is_invalid(string $text, string $stopReason): void
    {
        $this->llm->push(AiFixtures::response($text, stopReason: $stopReason));

        $this->expectException(InvalidModelOutput::class);

        $this->runExample('01');
    }

    public function test_max_tokens_returns_result_with_warning(): void
    {
        $this->llm->push(AiFixtures::response('Useknutý perex', stopReason: 'max_tokens'));

        $result = $this->runExample('01');

        self::assertSame('Useknutý perex', self::field($result, 'Perex'));
        self::assertContains('Odpověď byla useknuta limitem max_tokens.', $result->warnings);
    }
}
