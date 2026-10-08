<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Article\ArticleDetail;
use App\Domain\Article\ArticleRepository;
use App\Domain\Article\ArticleStatus;
use App\Domain\Article\ArticleSummary;

final readonly class PdoArticleRepository implements ArticleRepository
{
    /** Podmínka „veřejně čitelný“ – jediné místo, kde se rozhoduje, co je publikované. */
    private const string PUBLISHED_CONDITION = 'a.status = :status AND a.published_at IS NOT NULL AND a.published_at <= :now';

    /**
     * Znak pro escapování v LIKE. Výslovně (ne výchozí `\`), aby hledání fungovalo stejně
     * i v režimu `NO_BACKSLASH_ESCAPES`.
     */
    private const string LIKE_ESCAPE = '!';

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
        return $now
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s.u');
    }
}
