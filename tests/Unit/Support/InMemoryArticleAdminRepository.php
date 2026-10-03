<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Article\AdminArticleSummary;
use App\Domain\Article\ArticleAdminRepository;
use App\Domain\Article\ArticleData;
use App\Domain\Article\ArticleStatus;
use App\Domain\Article\EditableArticle;
use App\Domain\Article\SlugAlreadyTaken;

/**
 * Repozitář administrace článků v paměti (plán 005, §4).
 *
 * Ukládá články jako EditableArticle, přiděluje ID, zaznamenává volání zápisových metod
 * i takenSlugs() s argumenty a umí nasimulovat souběžnou kolizi slugu (SlugAlreadyTaken).
 */
final class InMemoryArticleAdminRepository implements ArticleAdminRepository
{
    /** @var array<int, EditableArticle> */
    public array $articles = [];

    /** @var list<AdminArticleSummary>|null když je nastaveno, list()/countAll() vrací tyto souhrny v daném pořadí */
    public ?array $summaries = null;

    /** @var array<int, int> id článku => id autora */
    public array $createdBy = [];
    /** @var array<int, int> id článku => id posledního editora */
    public array $updatedBy = [];

    /** @var array<int, string> id uživatele => jméno (pro updatedByName) */
    public array $userNames = [7 => 'Administrátor'];
    /** @var array<int, string> id rubriky => název (pro souhrny odvozené z článků) */
    public array $categoryNames = [1 => 'Technologie', 2 => 'Věda a výzkum', 3 => 'Zprávy'];

    /** @var list<array{data: ArticleData, authorId: int, now: \DateTimeImmutable}> */
    public array $createCalls = [];
    /** @var list<array{id: int, data: ArticleData, editorId: int, now: \DateTimeImmutable}> */
    public array $updateCalls = [];
    /** @var list<int> */
    public array $deleteCalls = [];
    /** @var list<array{base: string, exceptArticleId: ?int}> */
    public array $takenSlugsCalls = [];
    /** @var list<array{limit: int, offset: int}> */
    public array $listCalls = [];

    /** Simulace souběhu: create/update vyhodí SlugAlreadyTaken (jako chyba 1062 z DB). */
    public bool $throwSlugTakenOnWrite = false;

    private int $nextId = 1;

    public function add(EditableArticle $article): void
    {
        $this->articles[$article->id] = $article;
        $this->nextId = max($this->nextId, $article->id + 1);
    }

    /** Počet zápisových volání (create + update + delete). */
    public function writeCount(): int
    {
        return count($this->createCalls) + count($this->updateCalls) + count($this->deleteCalls);
    }

    public function list(int $limit, int $offset): array
    {
        $this->listCalls[] = ['limit' => $limit, 'offset' => $offset];

        return array_slice($this->allSummaries(), $offset, $limit);
    }

    public function countAll(): int
    {
        return count($this->allSummaries());
    }

    public function findForEditing(int $id): ?EditableArticle
    {
        return $this->articles[$id] ?? null;
    }

    public function takenSlugs(string $base, ?int $exceptArticleId): array
    {
        $this->takenSlugsCalls[] = ['base' => $base, 'exceptArticleId' => $exceptArticleId];

        $taken = [];
        foreach ($this->articles as $article) {
            if ($article->id === $exceptArticleId) {
                continue;
            }
            if ($article->slug === $base || str_starts_with($article->slug, $base . '-')) {
                $taken[] = $article->slug;
            }
        }

        return $taken;
    }

    public function create(ArticleData $data, int $authorId, \DateTimeImmutable $now): int
    {
        $this->createCalls[] = ['data' => $data, 'authorId' => $authorId, 'now' => $now];
        $this->guardSlug($data->slug, null);

        $id = $this->nextId++;
        $this->articles[$id] = self::fromData($id, $data, $now, $this->userNames[$authorId] ?? null);
        $this->createdBy[$id] = $authorId;
        $this->updatedBy[$id] = $authorId;

        return $id;
    }

    public function update(int $id, ArticleData $data, int $editorId, \DateTimeImmutable $now): void
    {
        $this->updateCalls[] = ['id' => $id, 'data' => $data, 'editorId' => $editorId, 'now' => $now];
        if (!isset($this->articles[$id])) {
            throw new \LogicException(sprintf('Článek %d v dvojníku neexistuje.', $id));
        }
        $this->guardSlug($data->slug, $id);

        $this->articles[$id] = self::fromData($id, $data, $now, $this->userNames[$editorId] ?? null);
        $this->updatedBy[$id] = $editorId;
    }

    public function delete(int $id): void
    {
        $this->deleteCalls[] = $id;
        unset($this->articles[$id], $this->createdBy[$id], $this->updatedBy[$id]);
    }

    /**
     * Pomocník pro testy: článek pro formulář s rozumnými výchozími hodnotami.
     *
     * @param list<int> $tagIds
     */
    public static function editable(
        int $id,
        string $title = 'Starý článek',
        string $slug = 'stary-clanek',
        string $excerpt = '',
        string $body = '',
        int $categoryId = 1,
        array $tagIds = [],
        ArticleStatus $status = ArticleStatus::Draft,
        ?string $publishedAt = null,
        string $updatedAt = '2026-10-02 14:05:00',
        ?string $updatedByName = null,
    ): EditableArticle {
        $zone = new \DateTimeZone('Europe/Prague');

        return new EditableArticle(
            id: $id,
            title: $title,
            slug: $slug,
            excerpt: $excerpt,
            body: $body,
            categoryId: $categoryId,
            tagIds: $tagIds,
            status: $status,
            publishedAt: $publishedAt === null ? null : new \DateTimeImmutable($publishedAt, $zone),
            updatedAt: new \DateTimeImmutable($updatedAt, $zone),
            updatedByName: $updatedByName,
        );
    }

    private function guardSlug(string $slug, ?int $exceptId): void
    {
        if ($this->throwSlugTakenOnWrite) {
            throw new SlugAlreadyTaken();
        }
        foreach ($this->articles as $article) {
            if ($article->id !== $exceptId && $article->slug === $slug) {
                throw new SlugAlreadyTaken();
            }
        }
    }

    private static function fromData(int $id, ArticleData $data, \DateTimeImmutable $now, ?string $updatedByName): EditableArticle
    {
        return new EditableArticle(
            id: $id,
            title: $data->title,
            slug: $data->slug,
            excerpt: $data->excerpt,
            body: $data->body,
            categoryId: $data->categoryId,
            tagIds: $data->tagIds,
            status: $data->status,
            publishedAt: $data->publishedAt,
            updatedAt: $now,
            updatedByName: $updatedByName,
        );
    }

    /** @return list<AdminArticleSummary> */
    private function allSummaries(): array
    {
        if ($this->summaries !== null) {
            return $this->summaries;
        }

        $articles = array_values($this->articles);
        usort(
            $articles,
            static fn(EditableArticle $a, EditableArticle $b): int => [$b->updatedAt, $b->id] <=> [$a->updatedAt, $a->id],
        );

        return array_map(
            fn(EditableArticle $article): AdminArticleSummary => new AdminArticleSummary(
                id: $article->id,
                title: $article->title,
                slug: $article->slug,
                status: $article->status,
                categoryName: $this->categoryNames[$article->categoryId] ?? '',
                publishedAt: $article->publishedAt,
                updatedAt: $article->updatedAt,
                updatedByName: $article->updatedByName,
            ),
            $articles,
        );
    }
}
