<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Session;

use App\Infrastructure\Session\NativeSession;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Plán 008, §3: `release()` uloží session a uvolní zámek; po uvolnění se session už nečte
 * ani nezapisuje. Každý test ve vlastním procesu – nativní session je globální stav PHP.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NativeSessionTest extends TestCase
{
    private string $savePath;

    protected function setUp(): void
    {
        $this->savePath = sys_get_temp_dir() . '/redakce-session-test-' . bin2hex(random_bytes(6));
        if (!mkdir($this->savePath, 0o700) && !is_dir($this->savePath)) {
            throw new \RuntimeException('Adresář pro session nevznikl.');
        }
        session_save_path($this->savePath);
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        foreach (glob($this->savePath . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->savePath);
    }

    public function test_release_without_started_session_does_not_start_one(): void
    {
        $session = new NativeSession(secureCookie: false);

        $session->release();

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame([], glob($this->savePath . '/sess_*'));
    }

    public function test_release_writes_data_and_closes_session(): void
    {
        $session = new NativeSession(secureCookie: false);
        $session->set('flash', 'Uloženo');
        $id = session_id();
        self::assertNotFalse($id);
        self::assertSame(PHP_SESSION_ACTIVE, session_status());

        $session->release();

        self::assertSame(PHP_SESSION_NONE, session_status());
        $stored = file_get_contents($this->savePath . '/sess_' . $id);
        self::assertIsString($stored);
        self::assertStringContainsString('Uloženo', $stored);
    }

    public function test_session_cannot_be_used_after_release(): void
    {
        $session = new NativeSession(secureCookie: false);
        $session->set('a', 1);
        $session->release();

        foreach ([
            static fn() => $session->get('a'),
            static fn() => $session->set('a', 2),
            static fn() => $session->remove('a'),
            static fn() => $session->pull('a'),
            static fn() => $session->regenerateId(),
            static fn() => $session->invalidate(),
        ] as $index => $use) {
            try {
                $use();
                self::fail('Po uvolnění musí přístup k session selhat (případ ' . $index . ').');
            } catch (\LogicException $exception) {
                self::assertSame('Session už byla v tomto požadavku uvolněna.', $exception->getMessage());
            }
        }
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    public function test_release_is_idempotent(): void
    {
        $session = new NativeSession(secureCookie: false);
        $session->set('a', 1);

        $session->release();
        $session->release();

        self::assertSame(PHP_SESSION_NONE, session_status());
    }
}
