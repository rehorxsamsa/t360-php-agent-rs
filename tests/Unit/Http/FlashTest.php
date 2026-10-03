<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Session\Flash;
use App\Tests\Unit\Support\ArraySession;
use PHPUnit\Framework\TestCase;

/** Plán 005, AC 16: jednorázová zpráva po přesměrování. */
final class FlashTest extends TestCase
{
    public function test_message_is_pulled_only_once(): void
    {
        $flash = new Flash(new ArraySession());

        $flash->set('Uloženo.');

        self::assertSame('Uloženo.', $flash->pull());
        self::assertSame('', $flash->pull());
    }

    public function test_message_is_stored_under_shared_flash_key(): void
    {
        $session = new ArraySession();

        new Flash($session)->set('Uloženo.');

        self::assertSame(['flash' => 'Uloženo.'], $session->data);
    }

    public function test_pull_without_message_is_empty_string(): void
    {
        self::assertSame('', new Flash(new ArraySession())->pull());
    }
}
