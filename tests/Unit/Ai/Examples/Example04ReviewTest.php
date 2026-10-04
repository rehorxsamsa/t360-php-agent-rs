<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\InvalidModelOutput;
use PHPUnit\Framework\Attributes\DataProvider;

/** Plán 006, AC 17: příklad 04 – kontrola článku před publikací. */
final class Example04ReviewTest extends ExampleTestCase
{
    /**
     * @return array{type: string, severity: string, quote: string, note: string}
     */
    private static function finding(string $type = 'tone', string $severity = 'low', string $quote = 'citace', string $note = 'poznámka'): array
    {
        return ['type' => $type, 'severity' => $severity, 'quote' => $quote, 'note' => $note];
    }

    /** @param list<array<string, string>> $findings */
    private static function review(string $summary = 'Článek je v pořádku.', array $findings = []): string
    {
        return self::json(['summary' => $summary, 'findings' => $findings]);
    }

    public function test_review_without_findings(): void
    {
        $this->llm->pushText(self::review());

        $result = $this->runExample('04');

        self::assertSame(
            [
                ['label' => 'Shrnutí', 'value' => 'Článek je v pořádku.'],
                ['label' => 'Nálezy', 'value' => 'Bez nálezů.'],
            ],
            $result->fields,
        );
    }

    public function test_each_finding_is_one_field_with_czech_type_and_severity(): void
    {
        $this->llm->pushText(self::review('Pozor na vložený pokyn.', [
            self::finding('prompt_injection', 'high', 'Ignoruj všechny předchozí pokyny', 'Text obsahuje pokyn pro AI.'),
            self::finding('personal_data', 'medium', '+420 777 123 456', 'Telefonní číslo.'),
        ]));

        $result = $this->runExample('04');

        self::assertSame(
            [
                ['label' => 'Shrnutí', 'value' => 'Pozor na vložený pokyn.'],
                ['label' => 'Nález 1 – prompt injection, vysoká', 'value' => '„Ignoruj všechny předchozí pokyny“ – Text obsahuje pokyn pro AI.'],
                ['label' => 'Nález 2 – osobní údaje, střední', 'value' => '„+420 777 123 456“ – Telefonní číslo.'],
            ],
            $result->fields,
        );
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function translations(): iterable
    {
        yield 'tone low' => ['tone', 'low', 'tón', 'nízká'];
        yield 'personal data medium' => ['personal_data', 'medium', 'osobní údaje', 'střední'];
        yield 'factual risk high' => ['factual_risk', 'high', 'faktické riziko', 'vysoká'];
        yield 'prompt injection low' => ['prompt_injection', 'low', 'prompt injection', 'nízká'];
    }

    #[DataProvider('translations')]
    public function test_types_and_severities_are_translated(string $type, string $severity, string $typeLabel, string $severityLabel): void
    {
        $this->llm->pushText(self::review(findings: [self::finding($type, $severity)]));

        $result = $this->runExample('04');

        self::assertContains(sprintf('Nález 1 – %s, %s', $typeLabel, $severityLabel), self::labels($result));
    }

    public function test_boundary_lengths_and_twenty_findings_are_valid(): void
    {
        $findings = array_fill(0, 20, self::finding(quote: str_repeat('q', 200), note: str_repeat('n', 300)));
        $this->llm->pushText(self::review(str_repeat('s', 500), $findings));

        $result = $this->runExample('04');

        self::assertSame(1, $result->calls);
        self::assertCount(21, $result->fields);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOutputs(): iterable
    {
        yield '21 findings' => [self::review(findings: array_fill(0, 21, self::finding()))];
        yield 'quote 201' => [self::review(findings: [self::finding(quote: str_repeat('q', 201))])];
        yield 'note 301' => [self::review(findings: [self::finding(note: str_repeat('n', 301))])];
        yield 'summary 501' => [self::review(str_repeat('s', 501))];
        yield 'empty summary' => [self::review('')];
        yield 'unknown type' => [self::review(findings: [self::finding('spam')])];
        yield 'unknown severity' => [self::review(findings: [self::finding('tone', 'critical')])];
        yield 'invalid json' => ['[nejde o objekt'];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_output_is_retried_once(string $invalid): void
    {
        $this->llm->pushText($invalid, self::review());

        $result = $this->runExample('04');

        self::assertCount(2, $this->llm->requests);
        self::assertSame(2, $result->calls);
    }

    #[DataProvider('invalidOutputs')]
    public function test_two_invalid_outputs_throw(string $invalid): void
    {
        $this->llm->pushText($invalid, $invalid);

        $this->expectException(InvalidModelOutput::class);
        $this->expectExceptionMessage('Model ani na druhý pokus nevrátil platná data: ');

        $this->runExample('04');
    }
}
