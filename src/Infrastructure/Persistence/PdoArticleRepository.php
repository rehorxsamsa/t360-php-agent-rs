<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Article\ArticleDetail;
use App\Domain\Article\ArticleRepository;
use App\Domain\Article\ArticleStatus;
use App\Domain\Article\ArticleSummary;
use App\Domain\Article\NamedCount;
use App\Domain\Article\PublishedStatistics;

final readonly class PdoArticleRepository implements ArticleRepository
{
    /** Podmínka „veřejně čitelný“ – jediné místo, kde se rozhoduje, co je publikované. */
    private const string PUBLISHED_CONDITION = 'a.status = :status AND a.published_at IS NOT NULL AND a.published_at <= :now';

    /**
     * Znak pro escapování v LIKE. Výslovně (ne výchozí `\`), aby hledání fungovalo stejně
     * i v režimu `NO_BACKSLASH_ESCAPES`.
     */
    private const string LIKE_ESCAPE = '!';

    /** Období pro „publikováno v posledních 30 dnech“. */
    private const string RECENT_PERIOD = 'P30D';

    /** Pojistka proti neomezenému výstupu – rubrik je v redakci řádově jednotky. */
    private const int CATEGORY_LIMIT = 50;

    public function __construct(private \PDO $pdo) {}

    public function latestPublished(\DateTimeImmutable $now, int $limit, int $offset): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.title, a.slug, a.excerpt, a.published_at, c.name AS category_name'
            . ' FROM articles a JOIN categories c ON c.id = a.category_id'
            . ' WHERE ' . self::PUBLISHED_CONDITION
            . ' ORDER BY a.published_at DESC, a.id DESC'
            . ' LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('now', $this->formatNow($now));
        $statement->bindValue('limit', max(0, $limit), \PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $statement->execute();

        $summaries = [];
        foreach ($statement->fetchAll() as $row) {
            $summaries[] = $this->hydrateSummary($row);
        }

        return $summaries;
    }

    public function countPublished(\DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM articles a WHERE ' . self::PUBLISHED_CONDITION,
        );
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('now', $this->formatNow($now));
        $statement->execute();

        $total = $statement->fetchColumn();
        if (!is_numeric($total)) {
            throw new \UnexpectedValueException('Počet článků má neočekávaný tvar.');
        }

        return (int) $total;
    }

    public function findPublishedBySlug(string $slug, \DateTimeImmutable $now): ?ArticleDetail
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.title, a.slug, a.excerpt, a.body, a.published_at, c.name AS category_name'
            . ' FROM articles a JOIN categories c ON c.id = a.category_id'
            . ' WHERE a.slug = :slug AND ' . self::PUBLISHED_CONDITION
            . ' LIMIT 1',
        );
        $statement->bindValue('slug', $slug);
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('now', $this->formatNow($now));
        $statement->execute();

        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_string($row['body'] ?? null)) {
            throw new \UnexpectedValueException('Řádek tabulky articles má neočekávaný tvar.');
        }

        $summary = $this->hydrateSummary($row);

        return new ArticleDetail(
            $summary->title,
            $summary->slug,
            $summary->excerpt,
            $summary->publishedAt,
            $summary->categoryName,
            $row['body'],
            $this->tagNames((int) $row['id']),
        );
    }

    public function searchPublished(string $query, \DateTimeImmutable $now, int $limit): array
    {
        $query = trim($query);
        if ($query === '' || $limit <= 0) {
            return [];
        }

        // Tři různé názvy parametrů: se skutečnými prepared statements (EMULATE_PREPARES=false)
        // nejde jeden pojmenovaný parametr v dotazu použít víckrát.
        $like = ' LIKE :%s ESCAPE \'' . self::LIKE_ESCAPE . '\'';
        $statement = $this->pdo->prepare(
            'SELECT a.title, a.slug, a.excerpt, a.published_at, c.name AS category_name'
            . ' FROM articles a JOIN categories c ON c.id = a.category_id'
            . ' WHERE ' . self::PUBLISHED_CONDITION
            . ' AND (a.title' . sprintf($like, 'q_title')
            . ' OR a.excerpt' . sprintf($like, 'q_excerpt')
            . ' OR a.body' . sprintf($like, 'q_body') . ')'
            . ' ORDER BY a.published_at DESC, a.id DESC'
            . ' LIMIT :limit',
        );
        $pattern = '%' . $this->escapeLike($query) . '%';
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('now', $this->formatNow($now));
        $statement->bindValue('q_title', $pattern);
        $statement->bindValue('q_excerpt', $pattern);
        $statement->bindValue('q_body', $pattern);
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        $summaries = [];
        foreach ($statement->fetchAll() as $row) {
            $summaries[] = $this->hydrateSummary($row);
        }

        return $summaries;
    }

    /**
     * Tři dotazy: souhrn (počet, posledních 30 dní, nejnovější), rubriky a štítky.
     * Podmínka publikovanosti je ve všech třech stejná (`PUBLISHED_CONDITION`), čas jde
     * z argumentu, ne z `NOW()` databáze.
     */
    public function publishedStatistics(\DateTimeImmutable $now, int $tagLimit): PublishedStatistics
    {
        $summary = $this->publishedSummary($now);

        return new PublishedStatistics(
            publishedCount: $summary['count'],
            publishedLast30Days: $summary['last30Days'],
            latestPublishedAt: $summary['latest'],
            categories: $this->publishedCategoryCounts($now),
            tags: $tagLimit > 0 ? $this->publishedTagCounts($now, $tagLimit) : [],
        );
    }

    /** @return array{count: int, last30Days: int, latest: ?\DateTimeImmutable} */
    private function publishedSummary(\DateTimeImmutable $now): array
    {
        // `:since` je samostatný parametr – se skutečnými prepared statements nejde `:now` použít dvakrát.
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS published_count,'
            . ' COALESCE(SUM(a.published_at > :since), 0) AS last_30_days,'
            . ' MAX(a.published_at) AS latest_published_at'
            . ' FROM articles a WHERE ' . self::PUBLISHED_CONDITION,
        );
        $statement->bindValue('since', $this->formatNow($this->localTime($now)->sub(new \DateInterval(self::RECENT_PERIOD))));
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('now', $this->formatNow($now));
        $statement->execute();

        $row = $statement->fetch();
        if (!is_array($row)
            || !is_numeric($row['published_count'] ?? null)
            || !is_numeric($row['last_30_days'] ?? null)
            || !(is_string($row['latest_published_at'] ?? null) || ($row['latest_published_at'] ?? null) === null)
        ) {
            throw new \UnexpectedValueException('Statistiky článků mají neočekávaný tvar.');
        }

        return [
            'count' => (int) $row['published_count'],
            'last30Days' => (int) $row['last_30_days'],
            'latest' => is_string($row['latest_published_at']) ? new \DateTimeImmutable($row['latest_published_at']) : null,
        ];
    }

    /** @return list<NamedCount> */
    private function publishedCategoryCounts(\DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.name, COUNT(*) AS article_count'
            . ' FROM articles a JOIN categories c ON c.id = a.category_id'
            . ' WHERE ' . self::PUBLISHED_CONDITION
            . ' GROUP BY c.id, c.name'
            . ' ORDER BY article_count DESC, c.name, c.id'
            . ' LIMIT :limit',
        );
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('now', $this->formatNow($now));
        $statement->bindValue('limit', self::CATEGORY_LIMIT, \PDO::PARAM_INT);
        $statement->execute();

        return $this->hydrateNamedCounts($statement->fetchAll());
    }

    /** @return list<NamedCount> */
    private function publishedTagCounts(\DateTimeImmutable $now, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.name, COUNT(*) AS article_count'
            . ' FROM articles a'
            . ' JOIN article_tags at_link ON at_link.article_id = a.id'
            . ' JOIN tags t ON t.id = at_link.tag_id'
            . ' WHERE ' . self::PUBLISHED_CONDITION
            . ' GROUP BY t.id, t.name'
            . ' ORDER BY article_count DESC, t.name, t.id'
            . ' LIMIT :limit',
        );
        $statement->bindValue('status', ArticleStatus::Published->value);
        $statement->bindValue('now', $this->formatNow($now));
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $this->hydrateNamedCounts($statement->fetchAll());
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<NamedCount>
     */
    private function hydrateNamedCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || !is_numeric($row['article_count'] ?? null)) {
                throw new \UnexpectedValueException('Řádek statistik má neočekávaný tvar.');
            }
            $counts[] = new NamedCount($row['name'], (int) $row['article_count']);
        }

        return $counts;
    }

    /** Zástupné znaky LIKE (`%`, `_`) i samotný escapovací znak se hledají doslova. */
    private function escapeLike(string $value): string
    {
        $escape = self::LIKE_ESCAPE;

        return str_replace(
            [$escape, '%', '_'],
            [$escape . $escape, $escape . '%', $escape . '_'],
            $value,
        );
    }

    /** @return list<string> */
    private function tagNames(int $articleId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.name FROM article_tags at_link JOIN tags t ON t.id = at_link.tag_id'
            . ' WHERE at_link.article_id = :article_id ORDER BY t.name',
        );
        $statement->execute(['article_id' => $articleId]);

        $names = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null)) {
                throw new \UnexpectedValueException('Řádek tabulky tags má neočekávaný tvar.');
            }
            $names[] = $row['name'];
        }

        return $names;
    }

    private function hydrateSummary(mixed $row): ArticleSummary
    {
        if (!is_array($row)
            || !is_string($row['title'] ?? null)
            || !is_string($row['slug'] ?? null)
            || !is_string($row['excerpt'] ?? null)
            || !is_string($row['published_at'] ?? null)
            || !is_string($row['category_name'] ?? null)
        ) {
            throw new \UnexpectedValueException('Řádek tabulky articles má neočekávaný tvar.');
        }

        return new ArticleSummary(
            $row['title'],
            $row['slug'],
            $row['excerpt'],
            new \DateTimeImmutable($row['published_at']),
            $row['category_name'],
        );
    }

    /**
     * `published_at` je DATETIME bez zóny zapisovaný z PHP, proto se čas porovnává v zóně PHP
     * (ne přes NOW() v databázi, která běží v UTC).
     */
    private function formatNow(\DateTimeImmutable $now): string
    {
        return $this->localTime($now)->format('Y-m-d H:i:s.u');
    }

    /** Čas v zóně PHP, ve které se zapisuje `published_at` (odečítání dní pak drží místní čas i přes změnu SEČ/SELČ). */
    private function localTime(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
