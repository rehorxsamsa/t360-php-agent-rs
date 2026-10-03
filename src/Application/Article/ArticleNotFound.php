<?php

declare(strict_types=1);

namespace App\Application\Article;

/** Článek s daným ID neexistuje (controller ho převede na 404). */
final class ArticleNotFound extends \RuntimeException {}
