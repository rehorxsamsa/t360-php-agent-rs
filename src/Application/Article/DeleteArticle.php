<?php

declare(strict_types=1);

namespace App\Application\Article;

use App\Domain\Article\ArticleAdminRepository;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\User\User;

/** Use-case: tvrdé smazání článku; v auditu zůstane kopie titulku a slugu. */
final readonly class DeleteArticle
{
    public function __construct(
        private ArticleAdminRepository $articles,
        private AuditLogRepository $audit,
    ) {}

    /**
     * @return string titulek smazaného článku (pro flash zprávu)
     * @throws ArticleNotFound když článek neexistuje
     */
    public function handle(int $id, User $actor, ?string $ipAddress): string
    {
        $article = $this->articles->findForEditing($id);
        if ($article === null) {
            throw new ArticleNotFound('Článek nebyl nalezen.');
        }

        $this->articles->delete($id);

        $this->audit->add(new AuditEntry(
            AuditAction::ArticleDeleted,
            $actor->id,
            'article',
            $id,
            $article->title . ' [' . $article->slug . ']',
            $ipAddress,
        ));

        return $article->title;
    }
}
