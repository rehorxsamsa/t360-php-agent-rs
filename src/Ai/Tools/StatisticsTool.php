<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Domain\Article\ArticleRepository;
use App\Domain\Article\NamedCount;
use App\Domain\Time\Clock;

/**
 * Nástroj `statistiky`: souhrn publikovaných článků (počet, počet za 30 dní, poslední datum, rubriky, štítky).
 * Má jen veřejné čtecí rozhraní `ArticleRepository`, takže koncepty, archivní ani naplánované články
 * nezahrnuje a neprozradí ani rubriku či štítek, které publikovaný článek nemají. Nemá parametry, vstup ignoruje.
 */
final readonly class StatisticsTool implements AgentTool
{
    public const string NAME = 'statistiky';
    public const int TAG_LIMIT = 10;
    /** Stejný strop jako v repozitáři; výstup nástroje je tím omezený i při výjimečně mnoha rubrikách. */
    public const int CATEGORY_LIMIT = 50;

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
            'description' => 'Vrátí souhrnné statistiky publikovaných článků redakce: celkový počet, počet za posledních 30 dní, '
                . 'datum nejnovějšího článku, počty článků podle rubrik a nejčastější štítky. '
                . 'Počítají se jen publikované články (koncepty ani nepublikované se nezapočítávají). Nástroj nemá parametry.',
            // Prázdný objekt, ne pole: `[]` by Claude Code odmítl jako neplatné schéma (PHP past, ADR-0008).
            'input_schema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'additionalProperties' => false,
            ],
        ];
    }

    public function run(array $input): ToolResult
    {
        // Vstup od modelu se nepoužívá: nástroj nemá parametry, takže nemá co zneužít.
        $now = $this->clock->now();
        $statistics = $this->articles->publishedStatistics($now, self::TAG_LIMIT);

        $categories = self::counts(array_slice($statistics->categories, 0, self::CATEGORY_LIMIT));
        $tags = self::counts(array_slice($statistics->tags, 0, self::TAG_LIMIT));

        // Obsah se musí vejít do limitu: při přetečení se zahazují nejméně časté položky (konec seznamů).
        do {
            $content = json_encode(
                [
                    'published_articles' => $statistics->publishedCount,
                    'published_last_30_days' => $statistics->publishedLast30Days,
                    'latest_published_at' => $statistics->latestPublishedAt?->format('Y-m-d'),
                    'categories' => $categories,
                    'top_tags' => $tags,
                    'generated_at' => $now->format('Y-m-d'),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            $fits = mb_strlen($content) <= ToolResult::MAX_LENGTH;
            if (!$fits) {
                $categories !== [] ? array_pop($categories) : array_pop($tags);
            }
        } while (!$fits && ($categories !== [] || $tags !== []));

        return ToolResult::success($content, sprintf('Statistiky: publikovaných článků %d.', $statistics->publishedCount));
    }

    /**
     * @param list<NamedCount> $items
     * @return list<array{name: string, articles: int}>
     */
    private static function counts(array $items): array
    {
        return array_map(
            static fn(NamedCount $item): array => ['name' => $item->name, 'articles' => $item->articles],
            $items,
        );
    }
}
