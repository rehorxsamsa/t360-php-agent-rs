<?php

declare(strict_types=1);

namespace App\Container;

/**
 * Kontejner nedokázal službu sestavit. Zpráva jmenuje třídu, parametr nebo řetězec zacyklení.
 */
final class ContainerException extends \RuntimeException {}
