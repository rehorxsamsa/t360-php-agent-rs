<?php

declare(strict_types=1);

namespace App\Ai\Client;

/** Spojení se nezdařilo (DNS, TLS, odpojení) nebo vypršel čas. Zpráva neobsahuje URL, hlavičky ani tělo. */
final class TransportFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false)
    {
        parent::__construct($message);
    }
}
