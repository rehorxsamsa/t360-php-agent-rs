<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Article;

use App\Application\Article\ArticleInputValidator;
use App\Application\Article\ArticleNotFound;
use App\Application\Article\InvalidArticleInput;
use App\Application\Article\UpdateArticle;
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

/** Plán 005, AC 11–12. */
final class UpdateArticleTest extends TestCase
{
    private InMemoryArticleAdminRepository $articles;
    private InMemoryAuditLogRepository $audit;
    private FixedClock $clock;
    private UpdateArticle $service;
    private User $admin;

    protected function setUp(): void
    {
        $this->articles = new InMemoryArticleAdminRepository();
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, title: 'Starý titulek', slug: 'stary-slug'));
        $this->audit = new InMemoryAuditLogRepository();
        $this->clock = FixedClock::at('2026-10-03 12:00:00');
        $validator = new ArticleInputValidator(new InMemoryCategoryRepository(), new InMemoryTagRepository(), $this->clock);
        $this->service = new UpdateArticle($validator, $this->articles, $this->audit, $this->clock);
        $this->admin = new User(7, 'admin@example.cz', 'Administrátor', 'hash', Role::Admin);
    }

    public function test_title_change_keeps_existing_slug_and_own_slug_is_not_collision(): void
    {
        $input = ArticleInputs::valid(['title' => 'Nový titulek', 'slug' => 'stary-slug']);

        $this->service->handle(5, $input, $this->admin, '172.18.0.1');

        self::assertSame([['base' => 'stary-slug', 'exceptArticleId' => 5]], $this->articles->takenSlugsCalls);
        self::assertCount(1, $this->articles->updateCalls);
        $call = $this->articles->updateCalls[0];
        self::assertSame(5, $call['id']);
        self::assertSame('stary-slug', $call['data']->slug);
        self::assertSame('Nový titulek', $call['data']->title);
        self::assertSame(7, $call['editorId']);
        self::assertEquals($this->clock->now(), $call['now']);
    }

    public function test_update_is_audited(): void
    {
        $input = ArticleInputs::valid(['title' => 'Nový titulek', 'slug' => 'stary-slug']);

        $this->service->handle(5, $input, $this->admin, '172.18.0.1');

        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame(AuditAction::ArticleUpdated, $entry->action);
        self::assertSame(7, $entry->userId);
        self::assertSame('article', $entry->entityType);
        self::assertSame(5, $entry->entityId);
        self::assertSame('Nový titulek [stary-slug]', $entry->summary);
        self::assertSame('172.18.0.1', $entry->ipAddress);
    }

    public function test_empty_slug_is_generated_from_title(): void
    {
        $input = ArticleInputs::valid(['title' => 'Nový titulek', 'slug' => '']);

        $this->service->handle(5, $input, $this->admin, null);

        self::assertSame('novy-titulek', $this->articles->updateCalls[0]['data']->slug);
        self::assertSame([['base' => 'novy-titulek', 'exceptArticleId' => 5]], $this->articles->takenSlugsCalls);
    }

    public function test_slug_taken_by_other_article_gets_suffix(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(6, title: 'Jiný', slug: 'novy-titulek'));

        $this->service->handle(5, ArticleInputs::valid(['title' => 'Nový titulek']), $this->admin, null);

        self::assertSame('novy-titulek-2', $this->articles->updateCalls[0]['data']->slug);
    }

    public function test_missing_article_throws_not_found_without_writes(): void
    {
        try {
            $this->service->handle(404, ArticleInputs::valid(), $this->admin, null);
            self::fail('Očekávána výjimka ArticleNotFound.');
        } catch (ArticleNotFound) {
        }

        self::assertSame([], $this->articles->updateCalls);
        self::assertSame([], $this->audit->entries);
    }

    public function test_invalid_input_saves_nothing_and_audits_nothing(): void
    {
        try {
            $this->service->handle(5, ArticleInputs::valid(['categoryId' => '99']), $this->admin, null);
            self::fail('Očekávána výjimka InvalidArticleInput.');
        } catch (InvalidArticleInput $exception) {
            self::assertSame(['category_id' => 'Vyberte rubriku.'], $exception->errors);
        }

        self::assertSame([], $this->articles->updateCalls);
        self::assertSame([], $this->audit->entries);
    }

    public function test_concurrent_slug_collision_becomes_form_error_without_audit(): void
    {
        $this->articles->throwSlugTakenOnWrite = true;

        try {
            $this->service->handle(5, ArticleInputs::valid(), $this->admin, null);
            self::fail('Očekávána výjimka InvalidArticleInput.');
        } catch (InvalidArticleInput $exception) {
            self::assertSame(['slug' => 'Adresa (slug) je už obsazená, uložte formulář znovu.'], $exception->errors);
        }

        self::assertSame([], $this->audit->entries);
    }
}
