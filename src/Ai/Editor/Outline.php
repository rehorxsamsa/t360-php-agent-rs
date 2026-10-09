<?php

declare(strict_types=1);

namespace App\Ai\Editor;

use App\Ai\Examples\FieldRules;

/**
 * Osnova článku (krok 1 AI redaktoru). Výstup modelu je nedůvěryhodný: `errors()` ho kontroluje v PHP,
 * `fromData()` smí volat až kód, který chyby vyloučil. Do dalšího kroku jde osnova jen jako data ve značce.
 */
final readonly class Outline
{
    public const int TITLE_MIN = 10;
    public const int TITLE_MAX = 200;
    public const int ANGLE_MIN = 10;
    public const int ANGLE_MAX = 300;
    public const int SECTIONS_MIN = 3;
    public const int SECTIONS_MAX = 6;
    public const int HEADING_MIN = 3;
    public const int HEADING_MAX = 100;
    public const int POINTS_MIN = 1;
    public const int POINTS_MAX = 4;
    public const int POINT_MIN = 3;
    public const int POINT_MAX = 200;

    /** @param list<array{heading: string, points: list<string>}> $sections */
    public function __construct(
        public string $title,
        public string $angle,
        public array $sections,
    ) {}

    /**
     * Schéma pro `output_config.format` (API nepodporuje maxLength ani maxItems – délky hlídá {@see errors()}).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Pracovní titulek článku, 10 až 200 znaků'],
                'angle' => ['type' => 'string', 'description' => 'Úhel pohledu a pro koho článek je, 10 až 300 znaků'],
                'sections' => [
                    'type' => 'array',
                    'description' => '3 až 6 sekcí osnovy',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'heading' => ['type' => 'string', 'description' => 'Mezititulek sekce, 3 až 100 znaků'],
                            'points' => [
                                'type' => 'array',
                                'description' => '1 až 4 body sekce, každý 3 až 200 znaků',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['heading', 'points'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'angle', 'sections'],
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
            FieldRules::string($data, 'title', self::TITLE_MIN, self::TITLE_MAX),
            FieldRules::string($data, 'angle', self::ANGLE_MIN, self::ANGLE_MAX),
        ]));

        $sections = $data['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections)) {
            return [...$errors, 'sections: musí být seznam sekcí'];
        }

        if (count($sections) < self::SECTIONS_MIN || count($sections) > self::SECTIONS_MAX) {
            $errors[] = sprintf('sections: %d až %d položek (je %d)', self::SECTIONS_MIN, self::SECTIONS_MAX, count($sections));
        }

        foreach ($sections as $index => $section) {
            if (!is_array($section)) {
                $errors[] = sprintf('sections[%d]: musí být objekt', $index);
                continue;
            }

            $sectionErrors = [
                FieldRules::string($section, 'heading', self::HEADING_MIN, self::HEADING_MAX),
                ...FieldRules::stringList($section, 'points', self::POINTS_MIN, self::POINTS_MAX, self::POINT_MIN, self::POINT_MAX),
            ];
            foreach ($sectionErrors as $error) {
                if ($error !== null) {
                    $errors[] = sprintf('sections[%d].%s', $index, $error);
                }
            }
        }

        return $errors;
    }

    /**
     * Vytvoří osnovu z dat, která prošla {@see errors()} (řetězce se oříznou, bílé znaky sloučí na jednu mezeru).
     *
     * @param array<mixed> $data
     */
    public static function fromData(array $data): self
    {
        $sections = [];
        $rawSections = is_array($data['sections'] ?? null) ? $data['sections'] : [];
        foreach ($rawSections as $section) {
            $section = is_array($section) ? $section : [];
            $points = [];
            foreach (is_array($section['points'] ?? null) ? $section['points'] : [] as $point) {
                $points[] = self::line(is_string($point) ? $point : '');
            }
            $sections[] = ['heading' => self::line(FieldRules::stringValue($section, 'heading')), 'points' => $points];
        }

        return new self(
            self::line(FieldRules::stringValue($data, 'title')),
            self::line(FieldRules::stringValue($data, 'angle')),
            $sections,
        );
    }

    /** Osnova jako data pro další krok (vkládá se do značky `<osnova>` přes `PromptData::block`). */
    public function toPromptText(): string
    {
        $parts = [];
        foreach ($this->sections as $section) {
            $lines = ['## ' . $section['heading']];
            foreach ($section['points'] as $point) {
                $lines[] = '- ' . $point;
            }
            $parts[] = implode("\n", $lines);
        }

        return 'Titulek: ' . $this->title . "\nÚhel: " . $this->angle . "\n\n" . implode("\n\n", $parts);
    }

    /** Osnova pro zobrazení: titulek, úhel a očíslované sekce s body. */
    public function toDisplayText(): string
    {
        $lines = [$this->title, $this->angle];
        foreach ($this->sections as $index => $section) {
            $lines[] = sprintf('%d. %s – %s', $index + 1, $section['heading'], implode('; ', $section['points']));
        }

        return implode("\n", $lines);
    }

    private static function line(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
