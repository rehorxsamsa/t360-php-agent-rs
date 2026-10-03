<?php

declare(strict_types=1);

namespace App\Domain\Article;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
