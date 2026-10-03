<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Article;

use App\Domain\Article\ArticleStatus;
use PHPUnit\Framework\TestCase;

/** Plán 005, AC 3. */
final class ArticleStatusTest extends TestCase
{
    public function test_labels_are_czech(): void
    {
        self::assertSame('Koncept', ArticleStatus::Draft->label());
        self::assertSame('Publikováno', ArticleStatus::Published->label());
        self::assertSame('Archiv', ArticleStatus::Archived->label());
    }
}
