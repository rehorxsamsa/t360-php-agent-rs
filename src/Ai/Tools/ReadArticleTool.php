<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Domain\Article\ArticleRepository;
use App\Domain\Time\Clock;

/**
 * Nástroj `nacti_clanek`: vrátí text publikovaného článku podle slugu. Koncept, archivní, naplánovaný
 * i neexistující článek dávají stejnou chybu, takže model (ani útočník v textu článku) nezjistí, že existují.
 * Text článku je nedůvěryhodný: jde jen do `tool_result`, nikdy do systémového promptu.
 */
final readonly class ReadArticleTool implements AgentTool
{
    public const string NAME = 'nacti_clanek';
    public const int BODY_LIMIT = 6000;
    private const int MAX_SLUG = 200;

    public function __construct(
        private ArticleRepository $articles,
        private Clock $clock,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Načte publikovaný článek podle slugu (z výsledku nástroje hledej_clanky): titulek, perex, rubriku, '
                . 'štítky a text (Markdown, nejvýše 6 000 znaků). Při zkrácení je truncated true.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Slug článku, například „docker-pro-vyvojare“.',
                    ],
                ],
                'required' => ['slug'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function run(array $input): ToolResult
    {
        $slug = $input['slug'] ?? null;
        if (!is_string($slug) || mb_strlen($slug) > self::MAX_SLUG || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return ToolResult::failure('Neplatný slug.');
        }

        $article = $this->articles->findPublishedBySlug($slug, $this->clock->now());
        if ($article === null) {
            return ToolResult::failure('Článek neexistuje nebo není publikovaný.');
        }

        $url = '/clanek/' . $article->slug;
        $bodyLimit = self::BODY_LIMIT;
        do {
            $truncated = mb_strlen($article->body) > $bodyLimit;
            $content = json_encode(
                [
                    'slug' => $article->slug,
                    'title' => $article->title,
                    'excerpt' => $article->excerpt,
                    'category' => $article->categoryName,
                    'tags' => $article->tagNames,
                    'published_at' => $article->publishedAt->format('Y-m-d'),
                    'url' => $url,
                    'body' => mb_substr($article->body, 0, $bodyLimit),
                    'truncated' => $truncated,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            // Dlouhý titulek nebo perex by mohl obsah přetlačit přes limit: text se zkracuje, dokud se nevejde.
            $bodyLimit -= 500;
        } while (mb_strlen($content) > ToolResult::MAX_LENGTH && $bodyLimit > 0);

        return ToolResult::success($content, sprintf('Načten článek „%s“ (%s).', $article->title, $url), $url);
    }
}
