<?php

declare(strict_types=1);

namespace App\Application\Article;

use App\Domain\Article\ArticleAdminRepository;
use App\Domain\Article\Slug;
use App\Domain\Article\SlugAlreadyTaken;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\Time\Clock;
use App\Domain\User\User;

/**
 * Use-case: úprava článku. Slug se mění jen tehdy, když ho admin ručně změní nebo vymaže;
 * změna titulku ho nepřepíše (vlastní slug článku se nepočítá jako kolize).
 */
final readonly class UpdateArticle
{
    public function __construct(
        private ArticleInputValidator $validator,
        private ArticleAdminRepository $articles,
        private AuditLogRepository $audit,
        private Clock $clock,
    ) {}

    /**
     * @throws ArticleNotFound když článek neexistuje
     * @throws InvalidArticleInput při chybách formuláře nebo souběžné kolizi slugu
     */
    public function handle(int $id, ArticleInput $input, User $actor, ?string $ipAddress): void
    {
        if ($this->articles->findForEditing($id) === null) {
            throw new ArticleNotFound('Článek nebyl nalezen.');
        }

        $data = $this->validator->validate($input);
        $data = $data->withSlug(Slug::uniqueAmong($data->slug, $this->articles->takenSlugs($data->slug, $id)));

        try {
            $this->articles->update($id, $data, $actor->id, $this->clock->now());
        } catch (SlugAlreadyTaken $e) {
            throw new InvalidArticleInput([
                'slug' => 'Adresa (slug) je už obsazená, uložte formulář znovu.',
            ], $e);
        }

        $this->audit->add(new AuditEntry(
            AuditAction::ArticleUpdated,
            $actor->id,
            'article',
            $id,
            $data->title . ' [' . $data->slug . ']',
            $ipAddress,
        ));
    }
}
