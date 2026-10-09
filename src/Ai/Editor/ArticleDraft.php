<?php

declare(strict_types=1);

namespace App\Ai\Editor;

use App\Ai\Examples\FieldRules;

/**
 * Koncept článku (titulek, perex, text v Markdownu). Nenese žádný stav publikace ani slug – i kdyby model
 * v odpovědi vrátil `status` nebo `publish`, `fromData()` takové klíče ignoruje (LLM06).
 */
final readonly class ArticleDraft
{
    public const int TITLE_MIN = 10;
    public const int TITLE_MAX = 200;
    public const int EXCERPT_MIN = 50;
    public const int EXCERPT_MAX = 300;
    public const int BODY_MIN = 600;
    public const int BODY_MAX = 4000;
    public const int MIN_SUBHEADINGS = 2;

    public function __construct(
        public string $title,
        public string $excerpt,
        public string $body,
    ) {}

    /**
     * Schéma pro `output_config.format` (délky hlídá {@see errors()}).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Titulek článku, 10 až 200 znaků'],
                'excerpt' => ['type' => 'string', 'description' => 'Perex, 50 až 300 znaků'],
                'body' => ['type' => 'string', 'description' => 'Text článku v Markdownu, mezititulky ##, 600–4 000 znaků'],
            ],
            'required' => ['title', 'excerpt', 'body'],
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
            FieldRules::string($data, 'excerpt', self::EXCERPT_MIN, self::EXCERPT_MAX),
            FieldRules::string($data, 'body', self::BODY_MIN, self::BODY_MAX),
        ]));

        $body = $data['body'] ?? null;
        if (is_string($body) && preg_match_all('/^## \S/m', self::normalizeNewlines($body)) < self::MIN_SUBHEADINGS) {
            $errors[] = 'body: alespoň ' . self::MIN_SUBHEADINGS . ' mezititulky ## ';
        }

        return $errors;
    }

    /**
     * Vytvoří koncept z dat, která prošla {@see errors()}. Cizí klíče (např. `status`) se ignorují.
     *
     * @param array<mixed> $data
     */
    public static function fromData(array $data): self
    {
        return new self(
            trim(preg_replace('/\s+/u', ' ', FieldRules::stringValue($data, 'title')) ?? ''),
            FieldRules::stringValue($data, 'excerpt'),
            self::normalizeNewlines(FieldRules::stringValue($data, 'body')),
        );
    }

    /** Koncept jako data pro další krok (vkládá se do značky `<koncept>` přes `PromptData::block`). */
    public function toPromptText(): string
    {
        return 'Titulek: ' . self::oneLine($this->title) . "\nPerex: " . self::oneLine($this->excerpt) . "\n\n" . $this->body;
    }

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
