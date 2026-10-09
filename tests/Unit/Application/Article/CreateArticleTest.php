<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Article;

use App\Application\Article\ArticleInputValidator;
use App\Application\Article\CreateArticle;
use App\Application\Article\InvalidArticleInput;
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

/** Plán 005, AC 8–10. */
final class CreateArticleTest extends TestCase
{
    private InMemoryArticleAdminRepository $articles;
    private InMemoryAuditLogRepository $audit;
    private FixedClock $clock;
    private CreateArticle $service;
    private User $admin;

    protected function setUp(): void
    {
        $this->articles = new InMemoryArticleAdminRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->clock = FixedClock::at('2026-10-03 12:00:00');
        $validator = new ArticleInputValidator(new InMemoryCategoryRepository(), new InMemoryTagRepository(), $this->clock);
        $this->service = new CreateArticle($validator, $this->articles, $this->audit, $this->clock);
        $this->admin = new User(7, 'admin@example.cz', 'Administrátor', 'hash', Role::Admin);
    }

    public function test_article_audit_actions_have_stable_values(): void
    {
        self::assertSame('article.created', AuditAction::ArticleCreated->value);
        self::assertSame('article.updated', AuditAction::ArticleUpdated->value);
        self::assertSame('article.deleted', AuditAction::ArticleDeleted->value);
    }

    public function test_creates_article_with_slug_from_title_and_returns_new_id(): void
    {
        $id = $this->service->handle(ArticleInputs::valid(), $this->admin, '172.18.0.1');

        self::assertCount(1, $this->articles->createCalls);
        $call = $this->articles->createCalls[0];
        self::assertSame('novy-clanek', $call['data']->slug);
        self::assertSame('Nový článek', $call['data']->title);
        self::assertSame([2, 3], $call['data']->tagIds);
        self::assertSame(7, $call['authorId']);
        self::assertEquals($this->clock->now(), $call['now']);
        self::assertArrayHasKey($id, $this->articles->articles);
    }

    public function test_creation_is_audited(): void
    {
        $id = $this->service->handle(ArticleInputs::valid(), $this->admin, '172.18.0.1');

        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame(AuditAction::ArticleCreated, $entry->action);
        self::assertSame(7, $entry->userId);
        self::assertSame('article', $entry->entityType);
        self::assertSame($id, $entry->entityId);
        self::assertSame('Nový článek [novy-clanek]', $entry->summary);
        self::assertSame('172.18.0.1', $entry->ipAddress);
    }

    public function test_slug_collision_gets_first_free_suffix_with_single_lookup(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(1, title: 'Nový článek', slug: 'novy-clanek'));
        $this->articles->add(InMemoryArticleAdminRepository::editable(2, title: 'Nový článek', slug: 'novy-clanek-2'));

        $this->service->handle(ArticleInputs::valid(), $this->admin, null);

        self::assertSame('novy-clanek-3', $this->articles->createCalls[0]['data']->slug);
        self::assertSame([['base' => 'novy-clanek', 'exceptArticleId' => null]], $this->articles->takenSlugsCalls);
        self::assertSame('Nový článek [novy-clanek-3]', $this->audit->entries[0]->summary);
    }

    public function test_manual_slug_is_normalized_before_saving(): void
    {
        $this->service->handle(ArticleInputs::valid(['slug' => 'Můj Vlastní Slug!']), $this->admin, null);

        self::assertSame('muj-vlastni-slug', $this->articles->createCalls[0]['data']->slug);
    }

    public function test_invalid_input_saves_nothing_and_audits_nothing(): void
    {
        try {
            $this->service->handle(ArticleInputs::valid(['title' => '']), $this->admin, null);
            self::fail('Očekávána výjimka InvalidArticleInput.');
        } catch (InvalidArticleInput $exception) {
            self::assertArrayHasKey('title', $exception->errors);
        }

        self::assertSame([], $this->articles->createCalls);
        self::assertSame([], $this->audit->entries);
    }

    public function test_concurrent_slug_collision_becomes_form_error_without_audit(): void
    {
        $this->articles->throwSlugTakenOnWrite = true;

        try {
            $this->service->handle(ArticleInputs::valid(), $this->admin, null);
            self::fail('Očekávána výjimka InvalidArticleInput.');
        } catch (InvalidArticleInput $exception) {
            self::assertSame(['slug' => 'Adresa (slug) je už obsazená, uložte formulář znovu.'], $exception->errors);
        }

        self::assertSame([], $this->audit->entries);
    }

    public function test_null_ip_address_is_allowed(): void
    {
        $this->service->handle(ArticleInputs::valid(), $this->admin, null);

        self::assertNull($this->audit->entries[0]->ipAddress);
    }

    // ---------------------------------------------------------------- plán 010, AC 17

    public function test_audit_action_can_be_overridden_for_ai_draft(): void
    {
        $id = $this->service->handle(ArticleInputs::valid(), $this->admin, '172.18.0.1', AuditAction::ArticleAiDraftSaved);

        self::assertCount(1, $this->audit->entries);
        self::assertSame(AuditAction::ArticleAiDraftSaved, $this->audit->entries[0]->action);
        self::assertSame($id, $this->audit->entries[0]->entityId);
        self::assertSame('Nový článek [novy-clanek]', $this->audit->entries[0]->summary);
    }

    public function test_audit_action_parameter_defaults_to_article_created(): void
    {
        $parameter = new \ReflectionMethod(CreateArticle::class, 'handle')->getParameters()[3] ?? null;

        self::assertNotNull($parameter, 'handle() má 4. parametr s akcí auditu.');
        self::assertTrue($parameter->isDefaultValueAvailable());
        self::assertSame(AuditAction::ArticleCreated, $parameter->getDefaultValue());
    }
}
