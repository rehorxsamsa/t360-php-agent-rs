<?php

declare(strict_types=1);

namespace App\Ai\Editor;

use App\Ai\Examples\FieldRules;

/**
 * Sebekontrola konceptu (krok 3): verdikt a nálezy. Slouží jako vstup pro přepracování a jako informace
 * pro člověka – bezpečnost na ní nezávisí (model nemá žádný nástroj, který by nález mohl obejít).
 */
final readonly class SelfReview
{
    public const int SUMMARY_MIN = 1;
    public const int SUMMARY_MAX = 500;
    public const int ISSUES_MAX = 10;
    public const int NOTE_MIN = 1;
    public const int NOTE_MAX = 300;

    /** @param list<ReviewIssue> $issues */
    public function __construct(
        public ReviewVerdict $verdict,
        public string $summary,
        public array $issues,
    ) {}

    /**
     * Schéma pro `output_config.format` (počty a délky hlídá {@see errors()}).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => [ReviewVerdict::Ok->value, ReviewVerdict::Revise->value]],
                'summary' => ['type' => 'string', 'description' => 'Shrnutí sebekontroly, 1 až 500 znaků'],
                'issues' => [
                    'type' => 'array',
                    'description' => 'Nálezy (nejvýše 10); prázdný seznam, když nic nenajdeš',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ReviewIssueType::values()],
                            'severity' => ['type' => 'string', 'enum' => ReviewSeverity::values()],
                            'note' => ['type' => 'string', 'description' => 'Vysvětlení česky, 1 až 300 znaků'],
                        ],
                        'required' => ['type', 'severity', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['verdict', 'summary', 'issues'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<mixed> $data
     * @return list<string> české chyby, prázdný seznam = platné
     */
    public static function errors(array $data): array
    {
        $errors = array_values(array_filter([
            FieldRules::oneOf($data, 'verdict', [ReviewVerdict::Ok->value, ReviewVerdict::Revise->value]),
            FieldRules::string($data, 'summary', self::SUMMARY_MIN, self::SUMMARY_MAX),
        ]));

        $issues = $data['issues'] ?? null;
        if (!is_array($issues) || !array_is_list($issues)) {
            return [...$errors, 'issues: musí být seznam nálezů'];
        }

        if (count($issues) > self::ISSUES_MAX) {
            $errors[] = sprintf('issues: nejvýše %d nálezů (je %d)', self::ISSUES_MAX, count($issues));
        }

        foreach ($issues as $index => $issue) {
            if (!is_array($issue)) {
                $errors[] = sprintf('issues[%d]: musí být objekt', $index);
                continue;
            }

            foreach ([
                FieldRules::oneOf($issue, 'type', ReviewIssueType::values()),
                FieldRules::oneOf($issue, 'severity', ReviewSeverity::values()),
                FieldRules::string($issue, 'note', self::NOTE_MIN, self::NOTE_MAX),
            ] as $error) {
                if ($error !== null) {
                    $errors[] = sprintf('issues[%d].%s', $index, $error);
                }
            }
        }

        return $errors;
    }

    /**
     * Vytvoří sebekontrolu z dat, která prošla {@see errors()}.
     *
     * @param array<mixed> $data
     */
    public static function fromData(array $data): self
    {
        $issues = [];
        foreach (is_array($data['issues'] ?? null) ? $data['issues'] : [] as $issue) {
            $issue = is_array($issue) ? $issue : [];
            $type = ReviewIssueType::tryFrom(FieldRules::stringValue($issue, 'type'));
            $severity = ReviewSeverity::tryFrom(FieldRules::stringValue($issue, 'severity'));
            if ($type === null || $severity === null) {
                continue;
            }
            $issues[] = new ReviewIssue($type, $severity, self::line(FieldRules::stringValue($issue, 'note')));
        }

        return new self(
            ReviewVerdict::tryFrom(FieldRules::stringValue($data, 'verdict')) ?? ReviewVerdict::Revise,
            self::line(FieldRules::stringValue($data, 'summary')),
            $issues,
        );
    }

    public function needsRevision(): bool
    {
        return $this->verdict === ReviewVerdict::Revise;
    }

    public function hasSevereIssue(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === ReviewSeverity::High) {
                return true;
            }
        }

        return false;
    }

    /** Sebekontrola jako data pro přepracování (vkládá se do značky `<nalezy>` přes `PromptData::block`). */
    public function toPromptText(): string
    {
        $lines = ['Verdikt: ' . $this->verdict->value, 'Shrnutí: ' . $this->summary];
        foreach ($this->issues as $issue) {
            $lines[] = sprintf('- [%s, %s] %s', $issue->type->value, $issue->severity->value, $issue->note);
        }

        return implode("\n", $lines);
    }

    private static function line(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
