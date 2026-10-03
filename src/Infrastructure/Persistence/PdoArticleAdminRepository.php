<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Article\AdminArticleSummary;
use App\Domain\Article\ArticleAdminRepository;
use App\Domain\Article\ArticleData;
use App\Domain\Article\ArticleStatus;
use App\Domain\Article\EditableArticle;
use App\Domain\Article\SlugAlreadyTaken;

/**
 * Články pro administraci (všechny stavy). `created_at` a `updated_at` se zapisují explicitně
 * z hodin aplikace: výchozí hodnoty sloupců (CURRENT_TIMESTAMP) běží v UTC, aplikace v Europe/Prague.
 */
final readonly class PdoArticleAdminRepository implements ArticleAdminRepository
{
    private const int MYSQL_DUPLICATE_KEY = 1062;
    private const string SLUG_UNIQUE_INDEX = 'uq_articles_slug';

    public function __construct(private \PDO $pdo) {}

    public function list(int $limit, int $offset): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.title, a.slug, a.status, a.published_at, a.updated_at,'
            . ' c.name AS category_name, u.display_name AS updated_by_name'
            . ' FROM articles a'
            . ' JOIN categories c ON c.id = a.category_id'
            . ' LEFT JOIN users u ON u.id = a.updated_by'
            . ' ORDER BY a.updated_at DESC, a.id DESC'
            . ' LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue('limit', max(0, $limit), \PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $statement->execute();

        $summaries = [];
        foreach ($statement->fetchAll() as $row) {
            $summaries[] = $this->hydrateSummary($row);
        }

        return $summaries;
    }

    public function countAll(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM articles');
        $statement->execute();

        $total = $statement->fetchColumn();
        if (!is_numeric($total)) {
            throw new \UnexpectedValueException('Počet článků má neočekávaný tvar.');
        }

        return (int) $total;
    }

    public function findForEditing(int $id): ?EditableArticle
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.title, a.slug, a.excerpt, a.body, a.category_id, a.status,'
            . ' a.published_at, a.updated_at, u.display_name AS updated_by_name'
            . ' FROM articles a'
            . ' LEFT JOIN users u ON u.id = a.updated_by'
            . ' WHERE a.id = :id LIMIT 1',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        if (!is_array($row)
            || !is_numeric($row['id'] ?? null)
            || !is_string($row['title'] ?? null)
            || !is_string($row['slug'] ?? null)
            || !is_string($row['excerpt'] ?? null)
            || !is_string($row['body'] ?? null)
            || !is_numeric($row['category_id'] ?? null)
            || !is_string($row['status'] ?? null)
            || !is_string($row['updated_at'] ?? null)
        ) {
            throw new \UnexpectedValueException('Řádek tabulky articles má neočekávaný tvar.');
        }

        $articleId = (int) $row['id'];

        return new EditableArticle(
            $articleId,
            $row['title'],
            $row['slug'],
            $row['excerpt'],
            $row['body'],
            (int) $row['category_id'],
            $this->tagIds($articleId),
            ArticleStatus::from($row['status']),
            $this->nullableDate($row['published_at'] ?? null),
            new \DateTimeImmutable($row['updated_at']),
            $this->nullableString($row['updated_by_name'] ?? null),
        );
    }

    public function takenSlugs(string $base, ?int $exceptArticleId): array
    {
        // Binární kolace: czech_ci by porovnávala bez ohledu na velikost písmen a s kontrakcí „ch“.
        // Každý pojmenovaný parametr jen jednou – emulace prepared statements je vypnutá.
        $statement = $this->pdo->prepare(
            'SELECT slug FROM articles'
            . ' WHERE (slug COLLATE utf8mb4_bin = :base OR slug COLLATE utf8mb4_bin LIKE :prefix)'
            . ' AND id <> :except_id',
        );
        $statement->execute([
            'base' => $base,
            'prefix' => addcslashes($base, '\\%_') . '-%',
            'except_id' => $exceptArticleId ?? 0,
        ]);

        $slugs = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $slug) {
            if (!is_string($slug)) {
                throw new \UnexpectedValueException('Slug článku má neočekávaný tvar.');
            }
            $slugs[] = $slug;
        }

        return $slugs;
    }

    public function create(ArticleData $data, int $authorId, \DateTimeImmutable $now): int
    {
        return $this->transactional(function () use ($data, $authorId, $now): int {
            $statement = $this->pdo->prepare(
                'INSERT INTO articles'
                . ' (category_id, title, slug, excerpt, body, status, published_at,'
                . ' created_by, updated_by, created_at, updated_at)'
                . ' VALUES (:category_id, :title, :slug, :excerpt, :body, :status, :published_at,'
                . ' :created_by, :updated_by, :created_at, :updated_at)',
            );
            $timestamp = $this->formatDateTime($now);
            $statement->execute([
                ...$this->articleParameters($data),
                'created_by' => $authorId,
                'updated_by' => $authorId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $this->insertTags($id, $data->tagIds);

            return $id;
        });
    }

    public function update(int $id, ArticleData $data, int $editorId, \DateTimeImmutable $now): void
    {
        $this->transactional(function () use ($id, $data, $editorId, $now): void {
            // Explicitní updated_at přebíjí ON UPDATE CURRENT_TIMESTAMP (UTC) ve schématu.
            $statement = $this->pdo->prepare(
                'UPDATE articles SET category_id = :category_id, title = :title, slug = :slug,'
                . ' excerpt = :excerpt, body = :body, status = :status, published_at = :published_at,'
                . ' updated_by = :updated_by, updated_at = :updated_at'
                . ' WHERE id = :id',
            );
            $statement->execute([
                ...$this->articleParameters($data),
                'updated_by' => $editorId,
                'updated_at' => $this->formatDateTime($now),
                'id' => $id,
            ]);

            $this->pdo->prepare('DELETE FROM article_tags WHERE article_id = :article_id')
                ->execute(['article_id' => $id]);
            $this->insertTags($id, $data->tagIds);
        });
    }

    public function delete(int $id): void
    {
        // Vazby na štítky maže kaskáda (fk_article_tags_article_id).
        $this->pdo->prepare('DELETE FROM articles WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Spustí zápis v transakci; porušení unikátnosti slugu převede na doménovou výjimku.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function transactional(\Closure $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($e instanceof \PDOException && $this->isSlugCollision($e)) {
                throw new SlugAlreadyTaken('Slug článku je už obsazený.', 0, $e);
            }

            throw $e;
        }
    }

    private function isSlugCollision(\PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return $driverCode === self::MYSQL_DUPLICATE_KEY
            && str_contains($e->getMessage(), self::SLUG_UNIQUE_INDEX);
    }

    /** @return array<string, int|string|null> společné sloupce pro INSERT i UPDATE */
    private function articleParameters(ArticleData $data): array
    {
        return [
            'category_id' => $data->categoryId,
            'title' => $data->title,
            'slug' => $data->slug,
            'excerpt' => $data->excerpt,
            'body' => $data->body,
            'status' => $data->status->value,
            'published_at' => $data->publishedAt !== null ? $this->formatDateTime($data->publishedAt) : null,
        ];
    }

    /**
     * Vazby štítků jedním vícehodnotovým INSERT; zástupné znaky odpovídají počtu ověřených ID.
     *
     * @param list<int> $tagIds
     */
    private function insertTags(int $articleId, array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($tagIds), '(?, ?)'));
        $parameters = [];
        foreach ($tagIds as $tagId) {
            $parameters[] = $articleId;
            $parameters[] = $tagId;
        }

        $this->pdo->prepare('INSERT INTO article_tags (article_id, tag_id) VALUES ' . $placeholders)
            ->execute($parameters);
    }

    /** @return list<int> */
    private function tagIds(int $articleId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tag_id FROM article_tags WHERE article_id = :article_id ORDER BY tag_id',
        );
        $statement->execute(['article_id' => $articleId]);

        $ids = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $tagId) {
            if (!is_numeric($tagId)) {
                throw new \UnexpectedValueException('Řádek tabulky article_tags má neočekávaný tvar.');
            }
            $ids[] = (int) $tagId;
        }

        return $ids;
    }

    private function hydrateSummary(mixed $row): AdminArticleSummary
    {
        if (!is_array($row)
            || !is_numeric($row['id'] ?? null)
            || !is_string($row['title'] ?? null)
            || !is_string($row['slug'] ?? null)
            || !is_string($row['status'] ?? null)
            || !is_string($row['category_name'] ?? null)
            || !is_string($row['updated_at'] ?? null)
        ) {
            throw new \UnexpectedValueException('Řádek tabulky articles má neočekávaný tvar.');
        }

        return new AdminArticleSummary(
            (int) $row['id'],
            $row['title'],
            $row['slug'],
            ArticleStatus::from($row['status']),
            $row['category_name'],
            $this->nullableDate($row['published_at'] ?? null),
            new \DateTimeImmutable($row['updated_at']),
            $this->nullableString($row['updated_by_name'] ?? null),
        );
    }

    private function nullableDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \UnexpectedValueException('Datum článku má neočekávaný tvar.');
        }

        return new \DateTimeImmutable($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new \UnexpectedValueException('Jméno uživatele má neočekávaný tvar.');
        }

        return $value;
    }

    /** DATETIME bez zóny se zapisuje v zóně PHP (stejně jako PdoArticleRepository porovnává). */
    private function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s.u');
    }
}
