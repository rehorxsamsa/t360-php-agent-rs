<?php

declare(strict_types=1);

namespace App\Domain\Article;

/**
 * Čtení a zápis článků pro administraci – na rozdíl od veřejného ArticleRepository
 * pracuje se všemi stavy (koncepty, archiv, naplánované).
 */
interface ArticleAdminRepository
{
    /** @return list<AdminArticleSummary> naposledy upravené první */
    public function list(int $limit, int $offset): array;

    public function countAll(): int;

    public function findForEditing(int $id): ?EditableArticle;

    /**
     * Obsazené slugy, které se rovnají `$base` nebo začínají `$base-` (jedním dotazem).
     *
     * @param int|null $exceptArticleId článek, jehož vlastní slug se nepočítá jako kolize
     * @return list<string>
     */
    public function takenSlugs(string $base, ?int $exceptArticleId): array;

    /**
     * @return int ID nového článku
     * @throws SlugAlreadyTaken když slug mezitím obsadil jiný článek
     */
    public function create(ArticleData $data, int $authorId, \DateTimeImmutable $now): int;

    /** @throws SlugAlreadyTaken když slug mezitím obsadil jiný článek */
    public function update(int $id, ArticleData $data, int $editorId, \DateTimeImmutable $now): void;

    public function delete(int $id): void;
}
