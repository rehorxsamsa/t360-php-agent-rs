<?php

declare(strict_types=1);

namespace App\Http;

/** Controller ji vyhodí, když požadovaný obsah neexistuje nebo není veřejný; výsledkem je stránka 404. */
final class PageNotFound extends \RuntimeException {}
