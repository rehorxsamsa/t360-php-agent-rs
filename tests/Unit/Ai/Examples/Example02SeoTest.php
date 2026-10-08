<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\InvalidModelOutput;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Tests\Unit\Support\MessageText;

/** Plán 006, AC 15: příklad 02 – SEO titulek, meta popis a klíčová slova (1 opakování). */
final class Example02SeoTest extends ExampleTestCase
{
    /**
     * @param list<string>|null $keywords
     * @return array<string, mixed>
     */
    private static function seo(?string $title = 'SEO titulek článku', ?string $meta = 'Krátký meta popis.', ?array $keywords = null): array
    {
        return ['title' => $title, 'meta_description' => $meta, 'keywords' => $keywords ?? ['php', 'docker', 'ai']];
    }

    public function test_valid_json_becomes_fields(): void
    {
        $this->llm->pushText(self::json(self::seo()));

        $result = $this->runExample('02');

        self::assertSame(
            [
                ['label' => 'Titulek', 'value' => 'SEO titulek článku'],
                ['label' => 'Meta popis', 'value' => 'Krátký meta popis.'],
                ['label' => 'Klíčová slova', 'value' => 'php, docker, ai'],
            ],
            $result->fields,
        );
        self::assertSame(1, $result->calls);
        self::assertCount(1, $this->llm->requests);
    }

    public function test_boundary_values_are_valid(): void
    {
        $this->llm->pushText(self::json(self::seo(
            str_repeat('č', 60),
            str_repeat('ř', 160),
            ['a', 'b', str_repeat('ž', 40), 'd', 'e', 'f', 'g', 'h'],
        )));

        $result = $this->runExample('02');

        self::assertSame(1, $result->calls);
        self::assertSame(str_repeat('č', 60), self::field($result, 'Titulek'));
    }

    public function test_too_long_title_is_retried_with_assistant_answer_and_errors(): void
    {
        $first = self::json(self::seo(str_repeat('a', 61)));
        $this->llm->push(
            AiFixtures::response($first, input: 100, output: 20, costUsd: 0.0004),
            AiFixtures::response(self::json(self::seo()), input: 150, output: 25, costUsd: 0.0005),
        );

        $result = $this->runExample('02');

        self::assertCount(2, $this->llm->requests);
        $initial = $this->llm->requests[0]->messages;
        $retry = $this->llm->requests[1]->messages;
        self::assertCount(1, $initial);
        self::assertCount(3, $retry);
        self::assertSame($initial[0], $retry[0]);
        self::assertSame(['role' => 'assistant', 'content' => $first], $retry[1]);
        self::assertSame('user', $retry[2]['role']);
        self::assertStringContainsString('title: nejvýše 60 znaků', MessageText::of($retry[2]['content']));
        self::assertSame($this->llm->requests[0]->system, $this->llm->requests[1]->system);

        self::assertSame(2, $result->calls);
        self::assertSame(250, $result->usage->input);
        self::assertSame(45, $result->usage->output);
        self::assertEqualsWithDelta(0.0009, $result->costUsd, 1e-9);
        self::assertSame('SEO titulek článku', self::field($result, 'Titulek'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOutputs(): iterable
    {
        yield 'title 61' => [self::json(self::seo(str_repeat('a', 61)))];
        yield 'empty title' => [self::json(self::seo(''))];
        yield 'meta 161' => [self::json(self::seo(meta: str_repeat('m', 161)))];
        yield 'empty meta' => [self::json(self::seo(meta: ''))];
        yield '2 keywords' => [self::json(self::seo(keywords: ['a', 'b']))];
        yield '9 keywords' => [self::json(self::seo(keywords: ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']))];
        yield 'keyword 41' => [self::json(self::seo(keywords: ['a', 'b', str_repeat('k', 41)]))];
        yield 'empty keyword' => [self::json(self::seo(keywords: ['a', 'b', '']))];
        yield 'missing field' => [self::json(['title' => 'T', 'keywords' => ['a', 'b', 'c']])];
        yield 'wrong type' => [self::json(['title' => 5, 'meta_description' => 'M', 'keywords' => ['a', 'b', 'c']])];
        yield 'invalid json' => ['{"title": "nedokončené'];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_output_is_retried_once(string $invalid): void
    {
        $this->llm->pushText($invalid, self::json(self::seo()));

        $result = $this->runExample('02');

        self::assertCount(2, $this->llm->requests);
        self::assertSame(2, $result->calls);
    }

    #[DataProvider('invalidOutputs')]
    public function test_two_invalid_outputs_throw_invalid_model_output(string $invalid): void
    {
        $this->llm->pushText($invalid, $invalid);

        try {
            $this->runExample('02');
            self::fail('Očekávána výjimka InvalidModelOutput.');
        } catch (InvalidModelOutput $exception) {
            self::assertStringStartsWith('Model ani na druhý pokus nevrátil platná data: ', $exception->getMessage());
        }
        self::assertCount(2, $this->llm->requests);
    }
}
