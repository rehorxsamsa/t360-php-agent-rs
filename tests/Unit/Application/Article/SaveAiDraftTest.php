<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Article;

use App\Application\Article\ArticleInputValidator;
use App\Application\Article\CreateArticle;
use App\Application\Article\InvalidArticleInput;
use App\Application\Article\SaveAiDraft;
use App\Domain\Article\ArticleStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\User\Role;
use App\Domain\User\User;
use App\Tests\Unit\Support\ArticleInputs;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryCategoryRepository;
use App\Tests\Unit\Support\InMemoryTagRepository;
use PHPUnit\Framework\TestCase;

/**
 * Plán 010, AC 16: uložení AI návrhu vždy jako koncept – stav, datum publikace, slug ani štítky z formuláře
 * se nepoužijí; audit `article.ai_draft_saved` se jménem schvalovatele. Čas 2026-10-08 12:00 (Europe/Prague).
 */
final class SaveAiDraftTest extends TestCase
{
    private InMemoryArticleAdminRepository $articles;
    private InMemoryAuditLogRepository $audit;
    private SaveAiDraft $service;
    private User $admin;

    protected function setUp(): void
    {
        $this->articles = new InMemoryArticleAdminRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $clock = FixedClock::at('2026-10-08 12:00:00');
        $validator = new ArticleInputValidator(new InMemoryCategoryRepository(), new InMemoryTagRepository(), $clock);
        $this->service = new SaveAiDraft(new CreateArticle($validator, $this->articles, $this->audit, $clock));
        $this->admin = new User(7, 'admin@example.cz', 'Administrátor', 'hash', Role::Admin);
    }

    private static function publishedInput(): \App\Application\Article\ArticleInput
    {
        return ArticleInputs::valid([
            'title' => 'Docker v malé redakci',
            'slug' => 'vlastni-slug',
            'excerpt' => 'Jak kontejnery pomáhají malé redakci.',
            'body' => "## Proč Docker\n\nText.\n\n## Jak začít\n\nDalší text.",
            'categoryId' => '1',
            'status' => 'published',
            'publishedAt' => '2026-10-08T12:00',
            'tagIds' => ['1'],
        ]);
    }

    public function test_save_forces_draft_without_publication_date_slug_or_tags(): void
    {
        $id = $this->service->handle(self::publishedInput(), $this->admin, '127.0.0.1');

        self::assertCount(1, $this->articles->createCalls);
        $article = $this->articles->findForEditing($id);
        self::assertNotNull($article);
        self::assertSame(ArticleStatus::Draft, $article->status);
        self::assertNull($article->publishedAt);
        self::assertSame('docker-v-male-redakci', $article->slug);
        self::assertSame([], $article->tagIds);
        self::assertSame('Docker v malé redakci', $article->title);
        self::assertSame('Jak kontejnery pomáhají malé redakci.', $article->excerpt);
        self::assertSame("## Proč Docker\n\nText.\n\n## Jak začít\n\nDalší text.", $article->body);
        self::assertSame(1, $article->categoryId);
        self::assertSame(7, $this->articles->createCalls[0]['authorId']);
        self::assertSame(7, $this->articles->createdBy[$id]);
    }

    public function test_save_is_audited_as_ai_draft(): void
    {
        $id = $this->service->handle(self::publishedInput(), $this->admin, '127.0.0.1');

        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame(AuditAction::ArticleAiDraftSaved, $entry->action);
        self::assertSame('article.ai_draft_saved', $entry->action->value);
        self::assertSame(7, $entry->userId);
        self::assertSame('article', $entry->entityType);
        self::assertSame($id, $entry->entityId);
        self::assertSame('Docker v malé redakci [docker-v-male-redakci]', $entry->summary);
        self::assertSame('127.0.0.1', $entry->ipAddress);
        self::assertSame([], $this->audit->byAction(AuditAction::ArticleCreated));
    }

    public function test_slug_from_title_gets_free_suffix_on_collision(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(3, title: 'Docker v malé redakci', slug: 'docker-v-male-redakci'));

        $id = $this->service->handle(self::publishedInput(), $this->admin, null);

        self::assertSame('docker-v-male-redakci-2', $this->articles->findForEditing($id)?->slug);
        self::assertNull($this->audit->entries[0]->ipAddress);
    }

    public function test_invalid_input_saves_nothing_and_audits_nothing(): void
    {
        try {
            $this->service->handle(ArticleInputs::valid(['title' => '', 'categoryId' => '']), $this->admin, '127.0.0.1');
            self::fail('Očekávána výjimka InvalidArticleInput.');
        } catch (InvalidArticleInput $exception) {
            self::assertArrayHasKey('title', $exception->errors);
            self::assertArrayHasKey('category_id', $exception->errors);
            self::assertSame('Vyberte rubriku.', $exception->errors['category_id']);
        }

        self::assertSame([], $this->articles->createCalls);
        self::assertSame([], $this->articles->articles);
        self::assertSame([], $this->audit->entries);
    }

    public function test_invalid_status_or_date_in_input_cannot_cause_error(): void
    {
        $id = $this->service->handle(
            ArticleInputs::valid(['status' => 'smazano', 'publishedAt' => 'zitra', 'slug' => '<script>', 'tagIds' => ['999']]),
            $this->admin,
            null,
        );

        $article = $this->articles->findForEditing($id);
        self::assertNotNull($article);
        self::assertSame(ArticleStatus::Draft, $article->status);
        self::assertSame('novy-clanek', $article->slug);
        self::assertSame([], $article->tagIds);
    }

    public function test_service_is_final_readonly(): void
    {
        $class = new \ReflectionClass(SaveAiDraft::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }
}
