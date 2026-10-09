<?php

declare(strict_types=1);

namespace App\Application\Article;

use App\Domain\Article\ArticleStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\User\User;

/**
 * Use-case: uložení návrhu AI redaktora jako konceptu článku (plán 010, ADR-0010).
 *
 * Jediná cesta, kterou se výstup AI dostane do databáze, a to až po potvrzení adminem
 * (LLM06 – člověk ve smyčce). Stav se vždy vynutí na koncept bez data publikace; slug
 * se odvodí z titulku a štítky se nepřebírají – vše, co by článek zveřejnilo nebo
 * zařadilo, zůstává na běžné úpravě článku.
 */
final readonly class SaveAiDraft
{
    public function __construct(private CreateArticle $createArticle) {}

    /**
     * @return int ID nového konceptu
     * @throws InvalidArticleInput při chybách formuláře nebo souběžné kolizi slugu
     */
    public function handle(ArticleInput $input, User $actor, ?string $ipAddress): int
    {
        // Z návrhu se berou jen obsahová pole; stav, datum, slug a štítky se ignorují.
        $draft = new ArticleInput(
            $input->title,
            '',
            $input->excerpt,
            $input->body,
            $input->categoryId,
            ArticleStatus::Draft->value,
            '',
            [],
        );

        return $this->createArticle->handle($draft, $actor, $ipAddress, AuditAction::ArticleAiDraftSaved);
    }
}
