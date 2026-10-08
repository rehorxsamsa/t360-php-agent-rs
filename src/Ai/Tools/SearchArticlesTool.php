<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Domain\Article\ArticleRepository;
use App\Domain\Time\Clock;

/**
 * Nástroj `hledej_clanky`: hledá v publikovaných článcích. Má jen veřejné čtecí rozhraní
 * `ArticleRepository` (koncepty, archiv ani naplánované články nevidí) a nic nezapisuje.
 */
final readonly class SearchArticlesTool implements AgentTool
{
    public const string NAME = 'hledej_clanky';
    public const int LIMIT = 5;
    private const int MIN_QUERY = 2;
    private const int MAX_QUERY = 100;

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
            'description' => 'Vyhledá publikované články redakce podle slova nebo fráze v titulku, perexu a textu. '
                . 'Vrátí nejvýše 5 nejnovějších článků (slug, titulek, perex, rubrika, datum). '
                . 'Celý text článku získáš nástrojem nacti_clanek.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Hledaný výraz (2 až 100 znaků), například „docker“.',
                    ],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function run(array $input): ToolResult
    {
        $query = $input['query'] ?? null;
        $query = is_string($query) ? trim($query) : '';
        $length = mb_strlen($query);
        if ($length < self::MIN_QUERY || $length > self::MAX_QUERY) {
            return ToolResult::failure('Dotaz musí mít 2–100 znaků.');
        }

        $found = $this->articles->searchPublished($query, $this->clock->now(), self::LIMIT);

        $results = [];
        foreach ($found as $article) {
            $results[] = [
                'slug' => $article->slug,
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'category' => $article->categoryName,
                'published_at' => $article->publishedAt->format('Y-m-d'),
            ];
        }

        // Obsah se vejde do limitu: při přetečení se zahazují nejstarší (poslední) články.
        do {
            $content = json_encode(
                ['query' => $query, 'results' => $results],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } while (mb_strlen($content) > ToolResult::MAX_LENGTH && array_pop($results) !== null);

        return ToolResult::success(
            $content,
            sprintf('Hledání „%s“: nalezeno článků %d.', $query, count($results)),
        );
    }
}
