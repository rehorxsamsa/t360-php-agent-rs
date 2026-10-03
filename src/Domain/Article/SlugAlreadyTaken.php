<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Slug obsadil souběžně jiný článek (porušení unikátního indexu `uq_articles_slug`). */
final class SlugAlreadyTaken extends \RuntimeException {}
