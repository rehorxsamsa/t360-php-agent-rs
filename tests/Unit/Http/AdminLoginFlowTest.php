<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Application\Auth\PasswordHasher;
use App\Container\Container;
use App\Domain\Audit\AuditAction;
use App\Domain\User\Role;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AC 10–15 plánu 003: celý tok přes Kernel z config/container.php s náhradou Session a repozitářů. */
final class AdminLoginFlowTest extends TestCase
{
    private const string EMAIL = 'admin@example.cz';
    private const string PASSWORD = 'spravne-heslo-123';
    private static string $hash;

    private ArraySession $session;
    private InMemoryUserRepository $users;
    private InMemoryAuditLogRepository $audit;
    private Container $container;
    private Kernel $kernel;
    private int $adminId;

    public static function setUpBeforeClass(): void
    {
        self::$hash = new PasswordHasher()->hash(self::PASSWORD);
    }

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->users = new InMemoryUserRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->container = TestContainer::create($this->session, $this->users, $this->audit);
        $this->kernel = $this->container->get(Kernel::class);
        $this->adminId = $this->users->add(self::EMAIL, 'Admin', self::$hash, Role::Admin);
    }

    private function csrf(): string
    {
        return $this->container->get(CsrfToken::class)->token();
    }

    /** @param array<string, string> $body */
    private function post(string $path, array $body, bool $withToken = true): Response
    {
        if ($withToken) {
            $body += ['_csrf' => $this->csrf()];
        }

        return $this->kernel->handle(new Request('POST', $path, body: $body, clientIp: '172.18.0.1'));
    }

    private function get(string $path): Response
    {
        return $this->kernel->handle(new Request('GET', $path, clientIp: '172.18.0.1'));
    }

    private function signIn(): void
    {
        $this->session->set('user_id', $this->adminId);
    }

    public function test_login_page_renders_form_for_anonymous(): void
    {
        $response = $this->get('/admin/prihlaseni');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Přihlášení do administrace', $response->body);
        self::assertStringContainsString('<form method="post" action="/admin/prihlaseni">', $response->body);
        self::assertMatchesRegularExpression('/<input[^>]*name="email"[^>]*type="email"|<input[^>]*type="email"[^>]*name="email"/', $response->body);
        self::assertStringContainsString('autocomplete="username"', $response->body);
        self::assertMatchesRegularExpression('/<input[^>]*name="password"[^>]*type="password"|<input[^>]*type="password"[^>]*name="password"/', $response->body);
        self::assertStringContainsString('autocomplete="current-password"', $response->body);
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $response->body);
        self::assertStringContainsString('Přihlásit se', $response->body);
    }

    public function test_login_page_redirects_logged_in_user_to_admin(): void
    {
        $this->signIn();

        $response = $this->get('/admin/prihlaseni');

        self::assertSame(303, $response->status);
        self::assertSame('/admin', $response->headers['Location'] ?? null);
    }

    public function test_successful_login_redirects_regenerates_session_and_rotates_csrf(): void
    {
        $before = $this->csrf();

        $response = $this->post('/admin/prihlaseni', ['email' => '  ADMIN@Example.cz ', 'password' => self::PASSWORD]);

        self::assertSame(303, $response->status);
        self::assertSame('/admin', $response->headers['Location'] ?? null);
        self::assertGreaterThanOrEqual(1, $this->session->regenerateCount);
        self::assertSame($this->adminId, $this->session->get('user_id'));
        self::assertNotSame($before, $this->csrf());
    }

    public function test_successful_login_writes_audit_and_touches_last_login(): void
    {
        $this->post('/admin/prihlaseni', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $entries = $this->audit->byAction(AuditAction::LoginSucceeded);
        self::assertCount(1, $entries);
        self::assertSame($this->adminId, $entries[0]->userId);
        self::assertSame('172.18.0.1', $entries[0]->ipAddress);
        self::assertSame([$this->adminId], $this->users->touchedLogins);
    }

    /** @return array<string, array{string, string}> */
    public static function badCredentials(): array
    {
        return [
            'wrong password' => [self::EMAIL, 'spatne-heslo'],
            'unknown email' => ['nikdo@example.cz', self::PASSWORD],
            'empty fields' => ['', ''],
            'password too long' => [self::EMAIL, str_repeat('a', 5000)],
        ];
    }

    #[DataProvider('badCredentials')]
    public function test_bad_credentials_return_422_with_same_message_and_no_session_user(string $email, string $password): void
    {
        $response = $this->post('/admin/prihlaseni', ['email' => $email, 'password' => $password]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Neplatné přihlašovací údaje.', $response->body);
        self::assertStringContainsString('Přihlášení do administrace', $response->body);
        self::assertNull($this->session->get('user_id'));
        $failed = $this->audit->byAction(AuditAction::LoginFailed);
        self::assertCount(1, $failed);
        self::assertSame([], $this->audit->byAction(AuditAction::LoginSucceeded));
        self::assertStringNotContainsString($password === '' ? "\0" : $password, $response->body);
    }

    public function test_failed_login_audit_links_known_account_and_records_email(): void
    {
        $this->post('/admin/prihlaseni', ['email' => self::EMAIL, 'password' => 'spatne']);
        $this->post('/admin/prihlaseni', ['email' => 'nikdo@example.cz', 'password' => 'spatne']);

        $failed = $this->audit->byAction(AuditAction::LoginFailed);
        self::assertSame($this->adminId, $failed[0]->userId);
        self::assertStringContainsString(self::EMAIL, $failed[0]->summary);
        self::assertNull($failed[1]->userId);
        self::assertStringContainsString('nikdo@example.cz', $failed[1]->summary);
    }

    public function test_failed_login_prefills_escaped_email_but_never_password(): void
    {
        $response = $this->post('/admin/prihlaseni', ['email' => '"><b>x@y.cz', 'password' => 'tajne-heslo-xyz']);

        self::assertStringContainsString('&quot;&gt;&lt;b&gt;x@y.cz', $response->body);
        self::assertStringNotContainsString('<b>x@y.cz', $response->body);
        self::assertStringNotContainsString('tajne-heslo-xyz', $response->body);
    }

    public function test_login_post_without_csrf_token_is_403_and_does_not_authenticate(): void
    {
        $response = $this->post('/admin/prihlaseni', ['email' => self::EMAIL, 'password' => self::PASSWORD], withToken: false);

        self::assertSame(403, $response->status);
        self::assertNull($this->session->get('user_id'));
        self::assertSame([], $this->audit->entries);
    }

    public function test_bcrypt_hash_is_upgraded_through_login_flow(): void
    {
        $this->users->updatePasswordHash($this->adminId, password_hash(self::PASSWORD, PASSWORD_BCRYPT));

        $this->post('/admin/prihlaseni', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        self::assertStringStartsWith('$argon2id$', $this->users->findById($this->adminId)->passwordHash ?? '');
    }

    public function test_dashboard_shows_escaped_name_and_logout_form(): void
    {
        $id = $this->users->add('jana@example.cz', 'Jana <b>', self::$hash, Role::Admin);
        $this->session->set('user_id', $id);

        $response = $this->get('/admin');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Přihlášen(a) jako Jana &lt;b&gt;', $response->body);
        self::assertStringNotContainsString('Jana <b>', $response->body);
        self::assertStringContainsString('action="/admin/odhlaseni"', $response->body);
        self::assertStringContainsString('name="_csrf"', $response->body);
        self::assertStringContainsString('Odhlásit se', $response->body);
    }

    public function test_anonymous_admin_request_redirects_to_login(): void
    {
        $response = $this->get('/admin');

        self::assertSame(303, $response->status);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null);
    }

    public function test_logout_audits_invalidates_session_and_redirects_with_one_time_flash(): void
    {
        $this->signIn();

        $response = $this->post('/admin/odhlaseni', []);

        self::assertSame(303, $response->status);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null);
        $entries = $this->audit->byAction(AuditAction::Logout);
        self::assertCount(1, $entries);
        self::assertSame($this->adminId, $entries[0]->userId);
        self::assertNull($this->session->get('user_id'));
        self::assertGreaterThanOrEqual(1, $this->session->invalidateCount);

        self::assertStringContainsString('Byli jste odhlášeni.', $this->get('/admin/prihlaseni')->body);
        self::assertStringNotContainsString('Byli jste odhlášeni.', $this->get('/admin/prihlaseni')->body);
    }

    public function test_logout_without_csrf_token_is_403_and_keeps_user_signed_in(): void
    {
        $this->signIn();

        $response = $this->post('/admin/odhlaseni', [], withToken: false);

        self::assertSame(403, $response->status);
        self::assertSame($this->adminId, $this->session->get('user_id'));
        self::assertSame([], $this->audit->byAction(AuditAction::Logout));
    }

    public function test_get_logout_is_405(): void
    {
        $this->signIn();

        self::assertSame(405, $this->get('/admin/odhlaseni')->status);
    }
}
