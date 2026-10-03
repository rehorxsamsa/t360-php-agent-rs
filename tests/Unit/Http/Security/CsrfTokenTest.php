<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Security;

use App\Http\Security\CsrfToken;
use App\Tests\Unit\Support\ArraySession;
use PHPUnit\Framework\TestCase;

final class CsrfTokenTest extends TestCase
{
    public function test_token_is_64_hex_chars_and_stable_within_session(): void
    {
        $csrf = new CsrfToken(new ArraySession());

        $first = $csrf->token();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertSame($first, $csrf->token());
    }

    public function test_token_is_stored_in_session(): void
    {
        $session = new ArraySession();

        $token = new CsrfToken($session)->token();

        self::assertSame($token, $session->get('_csrf'));
    }

    public function test_empty_and_wrong_values_are_invalid_and_correct_is_valid(): void
    {
        $csrf = new CsrfToken(new ArraySession());
        $token = $csrf->token();

        self::assertFalse($csrf->isValid(''));
        self::assertFalse($csrf->isValid('x'));
        self::assertFalse($csrf->isValid(strrev($token)));
        self::assertTrue($csrf->isValid($token));
    }

    public function test_empty_submission_is_invalid_even_without_token_in_session(): void
    {
        self::assertFalse(new CsrfToken(new ArraySession())->isValid(''));
    }

    public function test_rotate_forgets_token_so_next_one_differs(): void
    {
        $csrf = new CsrfToken(new ArraySession());
        $before = $csrf->token();

        $csrf->rotate();

        self::assertNotSame($before, $csrf->token());
        self::assertFalse($csrf->isValid($before));
    }
}
