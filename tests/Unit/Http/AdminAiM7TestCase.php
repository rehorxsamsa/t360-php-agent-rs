<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Ai\AiConfig;
use App\Ai\LlmClient;
use App\Container\Container;
use App\Domain\User\Role;
use App\Domain\User\User;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use App\Tests\Unit\Support\InMemoryArticleRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\TestCase;

/**
 * Společný základ HTTP testů plánu 008 (AC 24–31): skutečný Kernel, session, repozitáře a log volání v paměti,
 * články z kontraktu testovacích dat, čas 2026-10-04 12:00 (Europe/Prague), admin id 7. Výchozí LlmClient je
 * skutečný MeteredLlmClient nad FakeLlmClient (bez zpoždění mezi deltami).
 */
abstract class AdminAiM7TestCase extends TestCase
{
    protected const int ADMIN_ID = 7;

    protected ArraySession $session;
    protected InMemoryUserRepository $users;
    protected InMemoryAiCallRepository $aiCalls;
    protected InMemoryArticleRepository $articles;
    protected InMemoryArticleAdminRepository $adminArticles;
    protected Container $container;
    protected Kernel $kernel;
    private string|false $previousErrorLog = false;
    private string $errorLogFile = '';

    protected function setUp(): void
    {
        $this->errorLogFile = sys_get_temp_dir() . '/t360-admin-ai-m7-' . bin2hex(random_bytes(4)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->errorLogFile);

        $this->session = new ArraySession();
        $this->users = new InMemoryUserRepository();
        $this->users->users[self::ADMIN_ID] = new User(self::ADMIN_ID, 'admin@example.cz', 'Administrátor', 'hash', Role::Admin);
        $this->aiCalls = new InMemoryAiCallRepository();
        $this->articles = InMemoryArticleRepository::newsroomContract();
        $this->adminArticles = new InMemoryArticleAdminRepository();
        $this->boot();
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
    }

    protected function boot(?LlmClient $llm = null, ?AiConfig $config = null): void
    {
        $this->container = TestContainer::create(
            $this->session,
            $this->users,
            new InMemoryAuditLogRepository(),
            articles: $this->articles,
            clock: FixedClock::at('2026-10-04 12:00:00'),
            adminArticles: $this->adminArticles,
            aiCalls: $this->aiCalls,
            llmClient: $llm,
            aiConfig: $config,
        );
        $this->kernel = $this->container->get(Kernel::class);
    }

    protected function errorLog(): string
    {
        return is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
    }

    protected function signIn(): void
    {
        $this->session->set('user_id', self::ADMIN_ID);
    }

    protected function csrf(): string
    {
        return $this->container->get(CsrfToken::class)->token();
    }

    protected function get(string $path): Response
    {
        return $this->kernel->handle(new Request('GET', $path, clientIp: '172.18.0.1'));
    }

    /** @param array<string, string> $body */
    protected function post(string $path, array $body = [], ?string $token = null, bool $withToken = true): Response
    {
        if ($withToken) {
            $body['_csrf'] = $token ?? $this->csrf();
        }

        return $this->kernel->handle(new Request('POST', $path, body: $body, clientIp: '172.18.0.1'));
    }

    /** Text stránky bez značek, entity dekódované, bílé znaky sloučené. */
    protected static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('~[\s\x{00A0}\x{202F}]+~u', ' ', $text));
    }

    /**
     * Najde otevírací značku s danými atributy bez ohledu na jejich pořadí. Hodnota `null` = jen přítomnost
     * (boolean atribut, např. `disabled`).
     *
     * @param array<string, string|null> $attributes
     */
    protected static function findTag(string $html, string $name, array $attributes): ?string
    {
        preg_match_all('~<' . $name . '\b[^>]*>~u', $html, $matches);
        foreach ($matches[0] as $tag) {
            $all = true;
            foreach ($attributes as $attribute => $value) {
                $pattern = $value === null
                    ? '~\s' . preg_quote($attribute, '~') . '(?=[\s/>=])~u'
                    : '~\s' . preg_quote($attribute, '~') . '="' . preg_quote($value, '~') . '"~u';
                if (preg_match($pattern, $tag) !== 1) {
                    $all = false;

                    break;
                }
            }
            if ($all) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Celý element (od otevírací po zavírací značku) s danými atributy.
     *
     * @param array<string, string|null> $attributes
     */
    protected static function element(string $html, string $name, array $attributes): ?string
    {
        $tag = self::findTag($html, $name, $attributes);
        if ($tag === null) {
            return null;
        }
        $start = strpos($html, $tag);
        $end = strpos($html, '</' . $name . '>', (int) $start);

        return $start === false || $end === false ? null : substr($html, $start, $end - $start + strlen('</' . $name . '>'));
    }

    /** @return list<array{value: string, text: string, selected: bool}> */
    protected static function options(string $html, string $name): array
    {
        if (preg_match('~<select\b[^>]*\sname="' . preg_quote($name, '~') . '"[^>]*>(.*?)</select>~su', $html, $select) !== 1) {
            self::fail(sprintf('Chybí <select name="%s">.', $name));
        }
        preg_match_all('~<option\b([^>]*)>(.*?)</option>~su', $select[1], $matches, PREG_SET_ORDER);
        $options = [];
        foreach ($matches as $match) {
            preg_match('~\svalue="([^"]*)"~u', $match[1], $value);
            $options[] = [
                'value' => $value[1] ?? '',
                'text' => trim($match[2]),
                'selected' => preg_match('~\sselected(?=[\s/>=]|$)~u', $match[1]) === 1,
            ];
        }

        return $options;
    }

    protected static function assertAlert(string $html, string $message): void
    {
        self::assertMatchesRegularExpression(
            '~role="alert"[^>]*>(?:(?!</(?:div|p|section)>).)*?' . preg_quote($message, '~') . '~su',
            $html,
            'Chybí role="alert" s hláškou: ' . $message,
        );
    }

    protected static function assertRedirectsToLogin(Response $response, string $context = ''): void
    {
        self::assertSame(303, $response->status, $context);
        self::assertSame('/admin/prihlaseni', $response->headers['Location'] ?? null, $context);
    }
}
