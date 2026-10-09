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

/** Use-case: vytvoření článku administrátorem (validace, unikátní slug, uložení, audit). */
final readonly class CreateArticle
{
    public function __construct(
        private ArticleInputValidator $validator,
        private ArticleAdminRepository $articles,
        private AuditLogRepository $audit,
        private Clock $clock,
    ) {}

    /**
     * @param AuditAction $auditAction akce zapsaná do auditu (AI koncept má vlastní, plán 010)
     * @return int ID nového článku
     * @throws InvalidArticleInput při chybách formuláře nebo souběžné kolizi slugu
     */
    public function handle(
        ArticleInput $input,
        User $actor,
        ?string $ipAddress,
        AuditAction $auditAction = AuditAction::ArticleCreated,
    ): int {
        $data = $this->validator->validate($input);
        $data = $data->withSlug(Slug::uniqueAmong($data->slug, $this->articles->takenSlugs($data->slug, null)));

        try {
            $id = $this->articles->create($data, $actor->id, $this->clock->now());
        } catch (SlugAlreadyTaken $e) {
            throw new InvalidArticleInput([
                'slug' => 'Adresa (slug) je už obsazená, uložte formulář znovu.',
            ], $e);
        }

        // Audit až po úspěšném uložení, mimo transakci článku (plán 005, otázka 5).
        $this->audit->add(new AuditEntry(
            $auditAction,
            $actor->id,
            'article',
            $id,
            $data->title . ' [' . $data->slug . ']',
            $ipAddress,
        ));

        return $id;
    }
}
