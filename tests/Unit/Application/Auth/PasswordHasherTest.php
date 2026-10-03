<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    private static string $hash;

    public static function setUpBeforeClass(): void
    {
        self::$hash = new PasswordHasher()->hash('tajne-heslo-123');
    }

    public function test_hash_is_argon2id_and_verifiable(): void
    {
        $hasher = new PasswordHasher();

        self::assertStringStartsWith('$argon2id$', self::$hash);
        self::assertTrue($hasher->verify('tajne-heslo-123', self::$hash));
        self::assertFalse($hasher->verify('jine', self::$hash));
    }

    public function test_needs_rehash_detects_legacy_algorithm_only(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->needsRehash(self::$hash));
        self::assertTrue($hasher->needsRehash(password_hash('x', PASSWORD_BCRYPT)));
    }

    public function test_verify_dummy_does_not_throw_and_returns_nothing(): void
    {
        new PasswordHasher()->verifyDummy('cokoliv');

        $this->addToAssertionCount(1);
    }
}
