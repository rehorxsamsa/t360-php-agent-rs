<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Article;

use App\Application\Article\ArticleNotFound;
use App\Application\Article\DeleteArticle;
use App\Domain\Audit\AuditAction;
use App\Domain\User\Role;
use App\Domain\User\User;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use PHPUnit\Framework\TestCase;

/** Plán 005, AC 12–13. */
final class DeleteArticleTest extends TestCase
{
    private InMemoryArticleAdminRepository $articles;
    private InMemoryAuditLogRepository $audit;
    private DeleteArticle $service;
    private User $admin;

    protected function setUp(): void
    {
        $this->articles = new InMemoryArticleAdminRepository();
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, title: 'Starý článek', slug: 'stary-clanek'));
        $this->audit = new InMemoryAuditLogRepository();
        $this->service = new DeleteArticle($this->articles, $this->audit);
        $this->admin = new User(7, 'admin@example.cz', 'Administrátor', 'hash', Role::Admin);
    }

    public function test_deletes_article_and_returns_its_title(): void
    {
        $title = $this->service->handle(5, $this->admin, '172.18.0.1');

        self::assertSame('Starý článek', $title);
        self::assertSame([5], $this->articles->deleteCalls);
        self::assertArrayNotHasKey(5, $this->articles->articles);
    }

    public function test_deletion_is_audited_with_copy_of_title_and_slug(): void
    {
        $this->service->handle(5, $this->admin, '172.18.0.1');

        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame(AuditAction::ArticleDeleted, $entry->action);
        self::assertSame(7, $entry->userId);
        self::assertSame('article', $entry->entityType);
        self::assertSame(5, $entry->entityId);
        self::assertSame('Starý článek [stary-clanek]', $entry->summary);
        self::assertSame('172.18.0.1', $entry->ipAddress);
    }

    public function test_missing_article_throws_not_found_without_writes(): void
    {
        try {
            $this->service->handle(404, $this->admin, null);
            self::fail('Očekávána výjimka ArticleNotFound.');
        } catch (ArticleNotFound) {
        }

        self::assertSame([], $this->articles->deleteCalls);
        self::assertSame([], $this->audit->entries);
    }
}
