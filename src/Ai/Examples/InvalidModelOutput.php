<?php

declare(strict_types=1);

namespace App\Ai\Examples;

/** Model vrátil data, která neprošla validací (ani po opakování). Zpráva je určená k zobrazení. */
final class InvalidModelOutput extends \RuntimeException {}
