<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\InvalidModelOutput;
use App\Domain\Category\Category;
use PHPUnit\Framework\Attributes\DataProvider;

/** Plán 006, AC 16: příklad 03 – štítky a rubrika (levný model, cache systémového promptu). */
final class Example03ClassificationTest extends ExampleTestCase
{
    /**
     * @param list<string> $tags
     */
    private static function classification(string $category = 'Technologie', array $tags = ['PHP', 'Kubernetes', 'docker']): string
    {
        return self::json(['category' => $category, 'tags' => $tags]);
    }

    public function test_schema_enum_lists_categories_in_repository_order(): void
    {
        $this->categories->categories = [
            new Category(3, 'Zprávy', 'zpravy'),
            new Category(1, 'Technologie', 'technologie'),
            new Category(2, 'Věda a výzkum', 'veda-a-vyzkum'),
        ];
        $this->llm->pushText(self::classification('Zprávy'));

        $this->runExample('03');

        $schema = $this->lastRequest()->jsonSchema;
        self::assertIsArray($schema);
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);
        $category = $properties['category'] ?? null;
        self::assertIsArray($category);
        self::assertSame(['Zprávy', 'Technologie', 'Věda a výzkum'], $category['enum'] ?? null);
    }

    public function test_request_uses_cheap_model_and_cached_system_prompt(): void
    {
        $this->llm->pushText(self::classification());

        $this->runExample('03');

        self::assertSame('claude-haiku-4-5-20251001', $this->lastRequest()->model);
        self::assertTrue($this->lastRequest()->cacheSystem);
    }

    public function test_fields_show_category_and_tags_marked_existing_or_new_case_insensitively(): void
    {
        $this->llm->pushText(self::classification('Věda a výzkum', ['PHP', 'Kubernetes', 'docker']));

        $result = $this->runExample('03');

        self::assertSame(['Rubrika', 'Štítky'], self::labels($result));
        self::assertSame('Věda a výzkum', self::field($result, 'Rubrika'));
        $tags = self::field($result, 'Štítky') ?? '';
        self::assertStringContainsString('PHP (existuje)', $tags);
        self::assertStringContainsString('Kubernetes (nový)', $tags);
        self::assertMatchesRegularExpression('~docker \(existuje\)~iu', $tags);
        self::assertSame(1, $result->calls);
    }

    public function test_duplicate_tags_are_merged_case_insensitively(): void
    {
        $this->llm->pushText(self::classification('Technologie', ['PHP', 'php', 'Docker', 'Kubernetes']));

        $result = $this->runExample('03');

        $tags = self::field($result, 'Štítky') ?? '';
        self::assertSame(1, preg_match_all('~php~iu', $tags), $tags);
        self::assertStringContainsString('Docker', $tags);
        self::assertStringContainsString('Kubernetes', $tags);
        self::assertSame(1, $result->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOutputs(): iterable
    {
        yield 'category outside enum' => [self::classification('Sport')];
        yield '2 tags' => [self::classification('Technologie', ['PHP', 'Docker'])];
        yield '7 tags' => [self::classification('Technologie', ['a', 'b', 'c', 'd', 'e', 'f', 'g'])];
        yield 'tag 51' => [self::classification('Technologie', ['PHP', 'Docker', str_repeat('t', 51)])];
        yield 'empty tag' => [self::classification('Technologie', ['PHP', 'Docker', ''])];
        yield 'invalid json' => ['nejde o JSON'];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_output_is_retried_once(string $invalid): void
    {
        $this->llm->pushText($invalid, self::classification());

        $result = $this->runExample('03');

        self::assertCount(2, $this->llm->requests);
        self::assertSame(2, $result->calls);
        self::assertSame('Technologie', self::field($result, 'Rubrika'));
    }

    #[DataProvider('invalidOutputs')]
    public function test_two_invalid_outputs_throw(string $invalid): void
    {
        $this->llm->pushText($invalid, $invalid);

        $this->expectException(InvalidModelOutput::class);
        $this->expectExceptionMessage('Model ani na druhý pokus nevrátil platná data: ');

        $this->runExample('03');
    }

    public function test_six_tags_are_valid(): void
    {
        $this->llm->pushText(self::classification('Technologie', ['a', 'b', 'c', 'd', 'e', str_repeat('f', 50)]));

        self::assertSame(1, $this->runExample('03')->calls);
    }
}
