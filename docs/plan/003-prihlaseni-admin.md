# 003 – Přihlášení admina, ochrana /admin, CSRF, bezpečnostní hlavičky a audit přihlášení
Stav: hotovo

- **Milník:** M3 · **Režim:** výukový (viz `docs/plan/STAV.md`) — MVP, bez kola security review
- **Autor:** agent architekt · **Datum:** 2026-10-03
- **Souvisí:** [plán 002](002-router-di-migrator.md) (stav po M2), [ADR-0003](../adr/0003-anglicke-identifikatory.md),
  [ADR-0004](../adr/0004-anglicke-nazvy-v-databazi.md), [architektura](../architektura.md),
  skilly `php-oop-standardy`, `bezpecnost-owasp`
- **Číslování:** plán 001 sliboval číslo 002 pro CI (`ci.yml`), plán 002 ho posunul na 003. Číslo 003
  teď dostal M3; CI dostane **004 nebo další volné číslo** v době, kdy se bude plánovat.
- **Schéma DB se nemění:** tabulky `users` a `audit_log` z M2 stačí → bez úkolu pro `databazista`.
- **ADR se nepíše:** rozhodnutí (nativní PHP session, ochrana podle prefixu cesty, routing před CSRF)
  jdou snadno vrátit, zdůvodnění je v §1.

## Cíl
Administrátor se přihlásí e-mailem a heslem na `http://localhost:8080/admin/prihlaseni`, dostane se
na úvodní stránku administrace `/admin` a odhlásí se. Nepřihlášený návštěvník se do `/admin/*`
nedostane (přesměrování na přihlášení). Každý formulář je chráněn CSRF tokenem, každá odpověď nese
základní bezpečnostní hlavičky a přihlášení i odhlášení se zapíší do `audit_log`. První admin účet
vznikne příkazem `bin/konzole admin:vytvor`.

## Akceptační kritéria
Unit kritéria ověřuje PHPUnit (session přes testovací `ArraySession`, repozitáře v paměti),
integrační PHPUnit nad `redakce_test`, HTTP kritéria curl z hostitele (povolené volby hooku:
`-s -S -i -I -D -d -H -X -w -m -o /dev/null`, bez cookie jar — cookie se předává `-H 'Cookie: …'`)
a Playwright MCP proti `http://web/`.

### A. Request, Response, hlavičky, pořadí middleware
1. **Given** `$_POST = ['email' => 'a@b.cz', 'x' => ['pole']]` a `$_SERVER['REMOTE_ADDR'] = '172.18.0.1'`,
   **When** `Request::fromGlobals()`, **Then** `input('email') === 'a@b.cz'`, `input('x') === ''`
   (ne-řetězce se zahodí), `input('chybi') === ''`, `clientIp === '172.18.0.1'`; **And** `withRoute()`
   tělo i IP zachová.
2. **Given** `Response::redirect('/admin')`, **Then** `303`, `Location: /admin`, `Cache-Control: no-store`,
   prázdné tělo; **When** `redirect('https://zlo.cz')`, `redirect('//zlo.cz')` nebo `redirect('admin')`,
   **Then** `\InvalidArgumentException` (jen interní cesty začínající jedním `/`).
3. **Given** Kernel z `config/container.php`, **When** `GET /`, `GET /neexistuje` (404) i `GET /zdravi`
   (JSON), **Then** každá odpověď má `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
   `Referrer-Policy: strict-origin-when-cross-origin`,
   `Permissions-Policy: camera=(), microphone=(), geolocation=()`, `Cross-Origin-Opener-Policy: same-origin`
   a `Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'`
   (CSP podmíněně — **otázka 2**).
4. **Given** Kernel, **When** `POST /zdravi` bez CSRF tokenu, **Then** stále `405` + `Allow: GET`;
   **When** `POST /neexistuje`, **Then** `404` (CSRF se kontroluje až u existující trasy — regrese AC 12
   a 19 plánu 002).

### B. CSRF (`tests/Unit/Http/Security/CsrfTokenTest.php`, `…/Middleware/CsrfMiddlewareTest.php`)
5. **Given** prázdná session, **When** `CsrfToken::token()` dvakrát, **Then** obě hodnoty jsou stejný
   řetězec 64 hex znaků; **When** `isValid('')`, `isValid('x')`, **Then** `false`; `isValid(token)` → `true`.
6. **Given** `CsrfMiddleware`, **When** `GET` bez tokenu, **Then** projde k handleru; **When** `POST` bez
   `_csrf` nebo se špatným `_csrf`, **Then** `403` HTML s nadpisem „Neplatný formulář“ a handler se
   **nespustí**; **When** `POST` se správným `_csrf`, **Then** handler se spustí.
7. **Given** `csrf_field('ab"<')`, **Then** výsledek je přesně
   `<input type="hidden" name="_csrf" value="ab&quot;&lt;">`; **And** `e_attr($x) === e($x)`.

### C. Přístup do administrace (`…/Middleware/AdminAccessMiddlewareTest.php`)
8. **Given** session bez přihlášení, **When** `GET /admin` nebo `GET /admin/cokoliv`, **Then** `303`
   `Location: /admin/prihlaseni`; **When** `GET /admin/prihlaseni`, `GET /`, `GET /adminx`, **Then**
   projde k handleru (prefix se porovnává po segmentech, `/adminx` není administrace).
9. **Given** session s `user_id` existujícího uživatele, **When** `GET /admin`, **Then** projde;
   **Given** `user_id` uživatele, který už v DB není, **Then** `303` na přihlášení a `user_id` je ze
   session odstraněno.

### D. Přihlášení a odhlášení (`tests/Unit/Http/AdminLoginFlowTest.php` přes Kernel, `…/Application/Auth/AdminAuthenticatorTest.php`)
10. **Given** nepřihlášený, **When** `GET /admin/prihlaseni`, **Then** `200` HTML s nadpisem
    „Přihlášení do administrace“, `<form method="post" action="/admin/prihlaseni">`, poli `email`
    (`type="email"`, `autocomplete="username"`), `password` (`type="password"`,
    `autocomplete="current-password"`), skrytým `_csrf` a tlačítkem „Přihlásit se“; **Given** přihlášený,
    **Then** `303` na `/admin`.
11. **Given** admin `admin@example.cz` s heslem `spravne-heslo-123`, **When** `POST /admin/prihlaseni`
    s platným `_csrf` a správnými údaji (e-mail i s velkými písmeny a mezerami okolo), **Then** `303`
    `Location: /admin`; session ID se obnovilo (`regenerateId` zavoláno), session má `user_id`, CSRF token
    je **jiný** než před přihlášením; `audit_log` dostal `auth.login` s `user_id` a IP; `last_login_at`
    je vyplněné.
12. **Given** špatné heslo, neexistující e-mail nebo prázdná pole, **When** `POST /admin/prihlaseni`,
    **Then** `422`, znovu formulář s hláškou „Neplatné přihlašovací údaje.“ (u všech případů **stejnou**),
    e-mail předvyplněný a escapovaný, heslo nepředvyplněné; session nemá `user_id`; `audit_log` dostal
    `auth.login_failed` se zadaným e-mailem v `summary` (`user_id` = id u známého účtu, jinak `NULL`)
    — **otázka 3**. Heslo delší než 4096 znaků se neověřuje (rovnou neúspěch).
13. **Given** uložený hash `PASSWORD_BCRYPT` a správné heslo, **When** přihlášení, **Then** projde
    a uložený hash nově začíná `$argon2id$` (`password_needs_rehash`).
14. **Given** přihlášený admin „Jana <b>“, **When** `GET /admin`, **Then** `200`, text
    „Přihlášen(a) jako Jana &lt;b&gt;“ a formulář `POST /admin/odhlaseni` se skrytým `_csrf`
    a tlačítkem „Odhlásit se“.
15. **Given** přihlášený, **When** `POST /admin/odhlaseni` s platným `_csrf`, **Then** `audit_log` dostal
    `auth.logout` s `user_id`; session je zneplatněná (žádné `user_id`, nové ID); `303` na
    `/admin/prihlaseni`, kde se **jednou** zobrazí „Byli jste odhlášeni.“ (druhé `GET` už ne).
    **When** `POST /admin/odhlaseni` bez tokenu, **Then** `403` a admin zůstává přihlášen;
    **When** `GET /admin/odhlaseni`, **Then** `405`.

### E. Persistence (`tests/Integration/Persistence/PdoUserRepositoryTest.php`, `…/PdoAuditLogRepositoryTest.php`)
16. **Given** `redakce_test` po `TestDatabase::reset()` + migracích, **When** `add(…)`, pak
    `findByEmail('ADMIN@example.cz')` a `findById(id)`, **Then** obě vrátí `User` s rolí `Role::Admin`;
    `updatePasswordHash` a `touchLastLogin` změní řádek (`last_login_at IS NOT NULL`);
    neexistující e-mail/ID → `null`.
17. **Given** schéma, **When** `PdoAuditLogRepository::add(new AuditEntry(AuditAction::LoginFailed, null, summary: <300 znaků>, ipAddress: '172.18.0.1'))`,
    **Then** řádek má `action = 'auth.login_failed'`, `user_id IS NULL`, `summary` zkrácené na 255 znaků,
    IP a `created_at`.

### F. Příkaz `admin:vytvor` (`…/Application/User/CreateAdminTest.php`, `…/Console/CreateAdminCommandTest.php`)
18. **Given** prázdný repozitář, **When** `CreateAdmin::handle('Admin@Example.cz ', 'Administrátor', 'dlouhe-heslo-12')`,
    **Then** vrátí ID, e-mail je uložen jako `admin@example.cz`, hash začíná `$argon2id$`
    a `password_verify` projde; audit `user.created` (`entity_type = 'user'`, `entity_id` = ID,
    `summary` = e-mail, `user_id NULL`). **When** neplatný e-mail, jméno prázdné/delší než 100 znaků,
    heslo kratší než 12 znaků nebo už existující e-mail, **Then** `CreateAdminFailed` s českou zprávou
    (např. „Uživatel s e-mailem admin@example.cz už existuje.“) a nic se neuloží.
19. **Given** `ConsoleApplication`, **When** `admin:vytvor --email=… --jmeno=… --heslo=…`, **Then** kód `0`
    a „Vytvořen administrátor admin@example.cz (ID 1).“; **When** bez `--heslo`, **Then** navíc
    „Vygenerované heslo: <≥ 20 znaků> – uložte si ho, znovu se nezobrazí.“; **When** chybí `--email`
    nebo `--jmeno`, **Then** kód `1` a nápověda na stderr; `admin:vytvor` je v seznamu příkazů.
20. **Given** dev DB po `make migrate`, **When** `docker compose exec -T app php bin/konzole admin:vytvor --email=admin@example.cz --jmeno=Administrátor --heslo=…`
    a znovu totéž, **Then** `0`, pak `1` s hláškou „už existuje“; MCP dotaz
    `SELECT LEFT(password_hash, 10) FROM users` vrátí `$argon2id$`.

### G. HTTP z hostitele a E2E (`tests/E2E-scenare.md`)
21. **Given** `make up` a admin z AC 20, **When** `curl -s http://localhost:8080/admin -D - -o /dev/null`,
    **Then** `303` + `Location: /admin/prihlaseni`; **When** `curl -s http://localhost:8080/admin/prihlaseni -D -`,
    **Then** `200`, `Set-Cookie: redakce_session=…; path=/; HttpOnly; SameSite=Strict`, hlavičky z AC 3
    a v těle `name="_csrf" value="…"`.
22. **Given** cookie a token z AC 21, **When** `curl -s … -X POST -d 'email=…&password=…' -D - -o /dev/null`
    bez cookie/tokenu, **Then** `403`; **When** s `-H 'Cookie: redakce_session=…'` a `_csrf=…`, **Then**
    `303 Location: /admin` a nová `Set-Cookie`; **When** `GET /admin` s novou cookie, **Then** `200`
    s „Přihlášen(a) jako“.
23. **Given** Playwright, **When** `http://web/admin` → přesměrování → vyplnit formulář → odeslat,
    **Then** stránka administrace (snímek `tests/_artefakty/admin-m3.png`); **When** „Odhlásit se“,
    **Then** přihlašovací stránka s „Byli jste odhlášeni.“; MCP dotaz na posledních řádků `audit_log`
    ukáže `auth.login` a `auth.logout`.
24. **Regrese:** `GET /` → `200` **bez** `Set-Cookie` (session se zakládá líně), `/zdravi` → `200`
    `{"stav":"ok","db":"ok"}`, `curl -X POST /zdravi` → `405` + `Allow: GET` (AC 18–20 plánu 002).

### H. Kvalita
25. **Given** běžící prostředí, **When** `make qa`, **Then** kód 0; **And** grep: `$_SESSION` jen
    v `src/Infrastructure/Session/`, `$_POST`/`$_SERVER` jen v `src/Http/Request.php`, SQL jen
    v `*Repository` a `database/migrations/`, žádné české znaky v identifikátorech (AC 32 plánu 002).

## Návrh

### 1. Tok požadavku a klíčová rozhodnutí
```
public/index.php → Kernel::handle → MiddlewarePipeline:
  SecurityHeadersMiddleware   (nejvnější: hlavičky i na chybové stránky)
  → ErrorHandlerMiddleware    (404 / 405 / 500 jako v M2)
  → RoutingMiddleware         (Router::match → Request::withRoute; RouteNotFound/MethodNotAllowed letí ven)
  → CsrfMiddleware            (POST/PUT/PATCH/DELETE: _csrf proti session)
  → AdminAccessMiddleware     (/admin a /admin/… kromě /admin/prihlaseni: přihlášený uživatel, jinak 303)
  → Kernel::dispatch          (controller z kontejneru podle $request->route)
```
- **Routing před CSRF a autorizací.** Kdyby CSRF běželo před routerem, `POST /zdravi` by vrátilo
  403 místo 405 a `POST /neexistuje` 403 místo 404 (porušení kontraktu M2). Proto se párování trasy
  přesouvá z `Kernel::dispatch` do `RoutingMiddleware`; `Kernel` už router nepotřebuje.
- **Session není middleware, ale líná služba.** `NativeSession` zavolá `session_start()` až při prvním
  čtení/zápisu → veřejné stránky nedostanou cookie (AC 24, příprava na kešování v M4). Proti
  architektonické zásadě „bezp. hlavičky → session → CSRF → autentizace → autorizace“ tedy chybí
  samostatná vrstva session.
- **Autentizace a autorizace v jednom middleware.** Role je jediná (`ENUM('admin')`), takže
  „přihlášen = admin“; porovnání role by PHPStan označil jako vždy pravdivé. Samostatná kontrola role
  a odpověď 403 přibudou s druhou rolí (mimo rozsah zadání).
- **Ochrana podle prefixu cesty**, ne per-trasa: jednodušší než skupiny tras v routeru; seznam
  veřejných admin cest je jedna konstanta (`['/admin/prihlaseni']`).
- Odchylky od zásad se zapíší do `docs/architektura.md` (architekt aktualizuje diagram vrstev).

### 2. Nové a změněné třídy
| Soubor | Typ | Odpovědnost |
|---|---|---|
| `src/Domain/User/User.php` | `final readonly class` | `int $id`, `string $email`, `string $displayName`, `string $passwordHash`, `Role $role` |
| `src/Domain/User/Role.php` | `enum Role: string` | `case Admin = 'admin';` (mapuje ENUM sloupce, `Role::from()` validuje data z DB) |
| `src/Domain/User/UserRepository.php` | `interface` | `findByEmail(string): ?User`, `findById(int): ?User`, `add(string $email, string $displayName, string $passwordHash, Role $role): int`, `updatePasswordHash(int $id, string $hash): void`, `touchLastLogin(int $id): void` |
| `src/Domain/Audit/AuditAction.php` | `enum AuditAction: string` | `LoginSucceeded = 'auth.login'`, `LoginFailed = 'auth.login_failed'`, `Logout = 'auth.logout'`, `UserCreated = 'user.created'` (M5 doplní akce článků) |
| `src/Domain/Audit/AuditEntry.php` | `final readonly class` | `AuditAction $action`, `?int $userId = null`, `?string $entityType = null`, `?int $entityId = null`, `string $summary = ''`, `?string $ipAddress = null`; konstruktor zkrátí `summary` na 255 znaků (`mb_substr`) |
| `src/Domain/Audit/AuditLogRepository.php` | `interface` | `add(AuditEntry $entry): void` (výpis až M8) |
| `src/Application/Auth/PasswordHasher.php` | `final readonly class` | `hash(string): string` (`PASSWORD_ARGON2ID`, výchozí parametry PHP), `verify(string $plain, string $hash): bool`, `needsRehash(string): bool`, `verifyDummy(string $plain): void` (vyrovná čas u neexistujícího účtu; ověřuje proti konstantě s předem spočítaným argon2id hashem náhodného řetězce). Bez rozhraní — jedna implementace, 2 uživatelé. |
| `src/Application/Auth/AdminAuthenticator.php` | `final readonly class` | `attempt(string $email, string $password, ?string $ipAddress): ?User` — normalizace e-mailu (`trim` + `mb_strtolower`), délkové limity, `verify`/`verifyDummy`, rehash, `touchLastLogin`, audit `auth.login` / `auth.login_failed`. `recordLogout(User $user, ?string $ipAddress): void` — audit `auth.logout`. Session nezná. |
| `src/Application/User/CreateAdmin.php` | `final readonly class` | `handle(string $email, string $displayName, string $plainPassword): int` — validace (e-mail `FILTER_VALIDATE_EMAIL`, max 190; jméno 1–100 znaků po `trim`; heslo 12–4096 znaků), kontrola duplicity přes `findByEmail`, hash, `add`, audit `user.created` |
| `src/Application/User/CreateAdminFailed.php` | `final class … extends \RuntimeException` | česká zpráva pro konzoli |
| `src/Infrastructure/Persistence/PdoUserRepository.php` | `final readonly class implements UserRepository` | `__construct(\PDO)`, prepared statements |
| `src/Infrastructure/Persistence/PdoAuditLogRepository.php` | `final readonly class implements AuditLogRepository` | `__construct(\PDO)`, `INSERT INTO audit_log …` |
| `src/Http/Session/Session.php` | `interface` | `get(string $key): string\|int\|null`, `set(string $key, string\|int $value): void`, `remove(string $key): void`, `pull(string $key): string\|int\|null` (přečte a smaže — flash), `regenerateId(): void`, `invalidate(): void` (vymaže data + nové ID). Dvě implementace: `NativeSession`, testovací `ArraySession`. |
| `src/Infrastructure/Session/NativeSession.php` | `final class implements Session` | jediné místo s `$_SESSION`. `__construct(bool $secureCookie, string $name = 'redakce_session')`. Líný start: `session_start(['name' => …, 'cookie_lifetime' => 0, 'cookie_path' => '/', 'cookie_secure' => $secureCookie, 'cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true, 'use_only_cookies' => true])`. **Nenastavovat** `sid_length`/`sid_bits_per_character` (v PHP 8.4 deprecated). `regenerateId` = `session_regenerate_id(true)`. Unit testem se nepokrývá (hlavičky v CLI) — ověřují ho AC 21–23. |
| `src/Http/Security/CsrfToken.php` | `final readonly class` | `__construct(Session)`; `token(): string` (pokud v session `_csrf` není, uloží `bin2hex(random_bytes(32))`), `isValid(string $submitted): bool` (`hash_equals`, prázdný → false), `rotate(): void` (smaže `_csrf`) |
| `src/Http/Auth/AuthSession.php` | `final readonly class` | `__construct(Session, UserRepository, CsrfToken)`; `user(): ?User` (z `user_id` v session, smazaného uživatele ze session odebere), `signIn(User)` (`regenerateId` + `set('user_id')` + `CsrfToken::rotate`), `signOut()` (`invalidate`). Používá middleware i controllery. |
| `src/Http/Middleware/SecurityHeadersMiddleware.php` | `final readonly class implements Middleware` | přidá hlavičky z AC 3 přes `Response::withHeaders` |
| `src/Http/Middleware/RoutingMiddleware.php` | `final readonly class implements Middleware` | `__construct(Router)`; `match` → `$next($request->withRoute($match))` |
| `src/Http/Middleware/CsrfMiddleware.php` | `final readonly class implements Middleware` | metody mimo `GET`/`HEAD` vyžadují `isValid($request->input('_csrf'))`, jinak `403` přes šablonu `error` (nadpis „Neplatný formulář“, text „Platnost formuláře vypršela. Vraťte se, obnovte stránku a zkuste to znovu.“) |
| `src/Http/Middleware/AdminAccessMiddleware.php` | `final readonly class implements Middleware` | cesta `/admin` nebo začíná `/admin/` a není v seznamu veřejných → `AuthSession::user() === null` ⇒ `Response::redirect('/admin/prihlaseni')` |
| `src/Http/Controller/Admin/LoginController.php` | `final readonly class` | `show(Request)` (přihlášený → 303 `/admin`; jinak formulář + `pull('flash')`), `login(Request)` (`AdminAuthenticator::attempt` → `signIn` + 303 `/admin`, jinak 422), `logout(Request)` (`recordLogout`, `signOut`, `set('flash', 'Byli jste odhlášeni.')`, 303 `/admin/prihlaseni`) |
| `src/Http/Controller/Admin/DashboardController.php` | `final readonly class` | `index(Request)` — šablona `admin/dashboard` (jméno uživatele, formulář odhlášení); M5 ji rozšíří |
| `src/Console/Command/CreateAdminCommand.php` | `final readonly class implements Command` | `admin:vytvor --email=… --jmeno=… [--heslo=…]`; bez `--heslo` vygeneruje `bin2hex(random_bytes(10))` a vypíše ho jednou (**otázka 5**) |
| `src/Http/Request.php` | změna | + `array<string, string> $body = []` (jen řetězcové hodnoty z `$_POST`), `?string $clientIp = null` (`REMOTE_ADDR`), `?RouteMatch $route = null`; `input(string $name): string` (chybí → `''`); `withRoute(RouteMatch)` (nastaví `route` i `routeParameters`, zachová ostatní). `withRouteParameters` zůstává (testy M2). |
| `src/Http/Response.php` | změna | + `redirect(string $location, int $status = 303)` (jen `^/(?!/)`, jinak `\InvalidArgumentException`), `withHeaders(array<string,string>): self` (nové hodnoty přepíší stávající) |
| `src/Http/Kernel.php` | změna | `__construct(Container, MiddlewarePipeline)` — `dispatch` bere `$request->route` (chybí → `\LogicException`); router se volá v `RoutingMiddleware` |
| `src/Http/View/helpers.php` | změna | + `e_attr()` (alias `e()` pro hodnoty atributů v uvozovkách — vyžaduje `.claude/rules/sablony.md`), `csrf_field(string $token): string` (**otázka 4**) |

### 3. Konfigurace a šablony
- `config/container.php`: továrny `\PDO::class` (líná, sdílená, `ConnectionFactory` + `DatabaseConfig`),
  `UserRepository` → `PdoUserRepository`, `AuditLogRepository` → `PdoAuditLogRepository`,
  `Session` → `new NativeSession(getenv('SESSION_COOKIE_SECURE') === '1')` (**otázka 1**),
  `MiddlewarePipeline` se seznamem z §1, `ConsoleApplication` + `'admin:vytvor' => CreateAdminCommand::class`.
  `PdoDatabaseHealthRepository` zůstává na `ConnectionFactory` (vlastní timeout a ošetření chyby).
- `config/routes.php`: `GET /admin/prihlaseni` → `LoginController::show`, `POST /admin/prihlaseni` →
  `login`, `POST /admin/odhlaseni` → `logout`, `GET /admin` → `DashboardController::index`.
- `templates/admin/login.php` — `<h1>Přihlášení do administrace</h1>`, flash a chyba v `<p role="alert">`,
  `<label>` u každého pole, `<?= csrf_field($csrfToken) ?>`, hodnota e-mailu přes `e_attr()`.
- `templates/admin/dashboard.php` — „Administrace“, „Přihlášen(a) jako …“, formulář odhlášení.
- `public/assets/app.css` — styl formuláře a hlášky (bez inline stylů, kvůli CSP).
- Názvy formulářových polí jsou identifikátory → anglicky (`email`, `password`, `_csrf`); URL a texty česky.
- `compose.yaml` se **nemění**: bez `SESSION_COOKIE_SECURE` je cookie bez `Secure` (dev přes HTTP);
  produkce (M9) proměnnou nastaví na `1` — zapsat do STAV.md k úkolům M9.

### 4. Testy (píše tester; názvy anglicky)
- Testovací dvojníci `tests/Unit/Support/{ArraySession,InMemoryUserRepository,InMemoryAuditLogRepository}.php`
  (`ArraySession` počítá volání `regenerateId`).
- Unit: `Http/RequestTest` (+ tělo, IP, `withRoute`), `Http/ResponseRedirectTest`,
  `Http/Middleware/{SecurityHeaders,Routing,Csrf,AdminAccess}MiddlewareTest`,
  `Http/Security/CsrfTokenTest`, `Http/View/EscapeTest` (+ `e_attr`, `csrf_field`),
  `Application/Auth/AdminAuthenticatorTest`, `Application/User/CreateAdminTest`,
  `Console/CreateAdminCommandTest`, `Http/AdminLoginFlowTest` (Kernel z `config/container.php`
  s náhradou `Session`, `UserRepository`, `AuditLogRepository`), úprava `Http/KernelTest`
  (nová signatura Kernelu, AC 3–4; v kontejneru vždy `ArraySession`, aby se nespouštěla nativní session).
- Integrační: `Persistence/PdoUserRepositoryTest`, `Persistence/PdoAuditLogRepositoryTest`
  (`TestDatabase::reset()` + `Migrator::migrate()` nad `database/migrations/`).
- Hesla v testech: argon2id s výchozími parametry trvá desítky ms — v unit testech hashovat jednou
  v `setUpBeforeClass`, ne v každém testu.
- `tests/E2E-scenare.md`: nový scénář „Přihlášení a odhlášení admina“ (AC 21–23), regrese AC 24.

## Dotčené soubory
**Nové:** `src/Domain/User/{User,Role,UserRepository}.php`,
`src/Domain/Audit/{AuditAction,AuditEntry,AuditLogRepository}.php`,
`src/Application/Auth/{PasswordHasher,AdminAuthenticator}.php`, `src/Application/User/{CreateAdmin,CreateAdminFailed}.php`,
`src/Infrastructure/Persistence/{PdoUserRepository,PdoAuditLogRepository}.php`,
`src/Infrastructure/Session/NativeSession.php`, `src/Http/Session/Session.php`,
`src/Http/Security/CsrfToken.php`, `src/Http/Auth/AuthSession.php`,
`src/Http/Middleware/{SecurityHeaders,Routing,Csrf,AdminAccess}Middleware.php`,
`src/Http/Controller/Admin/{LoginController,DashboardController}.php`,
`src/Console/Command/CreateAdminCommand.php`, `templates/admin/{login,dashboard}.php`, testy dle §4.

**Změněné:** `src/Http/{Request,Response,Kernel}.php`, `src/Http/View/helpers.php`, `config/container.php`,
`config/routes.php`, `public/assets/app.css`, `tests/Unit/Http/KernelTest.php`, `tests/Unit/Http/RequestTest.php`,
`tests/Unit/Http/View/EscapeTest.php`, `tests/E2E-scenare.md`, `docs/architektura.md`, `docs/plan/STAV.md`
(úkoly M9 + backlog), `docs/tutorial.html` + `README.md` (kapitola M3, `admin:vytvor`),
se souhlasem: `.claude/rules/sablony.md` (`csrf_field($csrfToken)`).

**Beze změny:** schéma DB a migrace, `compose.yaml`, `Makefile`, `composer.json` (žádná nová závislost).

## Úkoly pro agenty
Brána 1 (člověk) schvaluje: tento plán a otázky 1–6.

| # | Fáze | Agent | Úkol | Výstup | Souběh |
|---|---|---|---|---|---|
| T1 | 1 | `tester` (režim A) | testy z §4 pro AC 1–19 + dvojníci v `tests/Unit/Support/`; E2E scénář AC 21–24 | soubory testů; doložit RED ze správného důvodu (chybí třídy/metody) | ∥ T2a |
| T2a | 1 | `programator` | vrstva bez HTTP: `Domain/User`, `Domain/Audit`, `PasswordHasher`, `CreateAdmin`, `Pdo*Repository`, `CreateAdminCommand`, továrny v kontejneru (signatury jsou dané §2, nečeká na testy) | kód; `make check` zelené | ∥ T1 |
| T2b | 2 | `programator` | HTTP část: `Request`/`Response`/`Kernel`, middleware, `Session`/`NativeSession`, `CsrfToken`, `AuthSession`, controllery, šablony, trasy; dotáhnout T2a do GREEN | `make qa` zelené; výstup AC 20–22 (curl) | po T1 + T2a |
| T3 | 3 | `tester` (režim B) | `make qa`, AC 20–25, E2E Playwright (snímek `admin-m3.png`), MCP dotaz do `audit_log` | PASS/FAIL po kritériích; FAIL vrací T2b | po T2b |
| T4 | 3 | `technicky-spisovatel` | kapitola M3 v `docs/tutorial.html` (tok middleware, líná session, CSRF, argon2id + rehash, audit), README: `admin:vytvor` a první přihlášení | ověřené příkazy | ∥ T3 |
| T5 | 3 | vedoucí (**souhlas**, `.claude/`) | `.claude/rules/sablony.md`: `csrf_field($csrfToken)`; STAV.md: M9 `SESSION_COOKIE_SECURE=1`, backlog z otázky 6 | diff | ∥ T3 |
| — | 4 | vedoucí | report → **brána 2** → commity | — | — |

`databazista` se nespouští (schéma beze změny). `devops` se nespouští (`compose.yaml`/`Makefile` beze změny).
Security review se v tomto milníku nespouští (výukový režim, STAV.md); rizika jsou jen vyjmenována níže.

Návrh commitů (každý projde `make up` + `make qa`):
1. `refactor(http): párování tras v RoutingMiddleware, redirect a POST data v Request` (AC 1, 2, 4)
2. `feat(http): základní bezpečnostní hlavičky` (AC 3)
3. `feat(admin): uživatelé, audit log a příkaz admin:vytvor` (AC 16–20)
4. `feat(admin): přihlášení a odhlášení, CSRF a ochrana /admin` (AC 5–15, 21–24)
5. `docs: plán 003, architektura a kapitola M3` (T4 + T5 + tento plán)

## Rizika a bezpečnost
- **Brute force (zadání, příběh 4) se vědomě neřeší** — výukový režim; audit `auth.login_failed`
  (otázka 3) je podklad pro pozdější omezení pokusů. Bez timeoutu nečinnosti a absolutního limitu
  session (skill: 30 min / 8 h) — session žije do zavření prohlížeče nebo GC.
- **Cookie bez `Secure` a bez prefixu `__Host-`** v dev (otázka 1). Pokud by M9 proměnnou nenastavila,
  produkce pošle session cookie i po HTTP → úkol do STAV.md.
- **Session fixation:** `use_strict_mode` + `regenerateId` při přihlášení, `invalidate` při odhlášení,
  rotace CSRF tokenu po přihlášení (AC 11, 15).
- **CSRF:** token per session, `hash_equals`, kontrola u všech metod mimo GET/HEAD; `SameSite=Strict`
  jako druhá vrstva. GET nesmí nic měnit — odhlášení je jen POST (AC 15).
- **Výčet účtů:** stejná hláška i stavový kód pro špatné heslo i neznámý e-mail, `verifyDummy` vyrovná
  čas. Audit ale do DB zapisuje i neexistující e-maily (data od útočníka → v M8 escapovat při výpisu).
- **DoS přes argon2id:** limit 4096 znaků hesla; hashování ~64 MiB paměti na pokus — bez rate limitu
  lze FPM zahltit (přijato, lokální prostředí).
- **Open redirect:** `Response::redirect` přijímá jen interní cesty; po přihlášení vždy pevně `/admin`
  (žádné `?next=`).
- **IP v auditu nezkrácená** (skill chce zkrácenou) — v Dockeru je to stejně adresa brány sítě;
  odchylka zapsána v otázce 6.
- **Únik informací:** chybová/403 stránka bez detailu; heslo se nikdy neloguje ani nepředvyplňuje;
  `redakce_cteni` (MCP) dál čte `users.password_hash` (riziko z plánu 002, sloupcová práva mimo rozsah).
- **Smazaný uživatel s živou session** je při dalším požadavku odhlášen (AC 9).
- **Prefix `/admin`:** porovnání po segmentech (`/adminx` není chráněno ani blokováno — AC 8); nová
  admin trasa mimo `/admin/…` by chráněná nebyla → pravidlo do tutoriálu.
- **Změna Kernelu** (router do middleware) může rozbít testy M2 — `KernelTest` se upravuje, ne maže;
  AC 4 a 24 hlídají kontrakt 404/405 a `/zdravi`.
- **Hlavičky odesílané mimo `Response`:** `session_start()` posílá `Set-Cookie` a `Cache-Control`
  přímo přes PHP; nesmí se před ním nic vypsat (šablony se renderují do bufferu — v pořádku).
- **LLM rizika:** M3 neobsahuje AI.

## Mimo rozsah
- Omezení pokusů o přihlášení (tabulka pokusů), timeouty session, 2FA, zapomenuté heslo / reset hesla,
  změna hesla a správa vlastního účtu (backlog, otázka 6).
- CSP s nonce, HSTS (M9 za TLS), zkrácení IP v auditu.
- Další role a 403 pro přihlášeného bez oprávnění; skupiny tras / middleware per trasa.
- Výpis audit logu (M8), CRUD (M5), flash zprávy obecně (zde jen jedna hláška po odhlášení — M5 zobecní).
- Úprava `compose.yaml` (`SESSION_COOKIE_SECURE`) — až prod compose v M9.

## Otázky pro člověka
1. **Session cookie v dev bez `Secure` a s názvem `redakce_session`** (místo `__Host-redakce` ze skillu)?
   `Secure` řídí proměnná `SESSION_COOKIE_SECURE` (výchozí vypnuto, M9 zapne). Doporučuji **ano** —
   Playwright jde na `http://web/` (ne `localhost`), kde by prohlížeč `Secure` cookie zahodil
   a přihlášení v E2E by nefungovalo; `__Host-` prefix `Secure` vyžaduje.
2. **CSP ano/ne?** Navrhuji statickou CSP bez nonce (jedna hlavička, M2 už nemá inline styly/skripty).
   Doporučuji **ano** — práci neprodlužuje; kdyby později překážela (např. Markdown s obrázky z cizích
   domén), upraví se jeden řádek.
3. **Auditovat i neúspěšná přihlášení** (`auth.login_failed`, zadaný e-mail v `summary`)? Doporučuji
   **ano** — jeden řádek kódu navíc a základ pro budoucí ochranu proti brute force.
4. **Úprava pravidla `.claude/rules/sablony.md`** z `csrf_field()` na `csrf_field($csrfToken)`
   (token předává controller; bezparametrová varianta by potřebovala globální stav, který standardy
   zakazují)? Doporučuji **ano**.
5. **Heslo pro `admin:vytvor`:** volba `--heslo=` (zůstane v historii shellu) a bez ní vygenerované
   heslo vypsané jednou? Doporučuji **ano, obojí** — interaktivní skryté zadání by vyžadovalo
   `stty`/`shell_exec` (zakázané) nebo změnu rozhraní `Command` o vstup.
6. **Odchylky od zadání a skillu `bezpecnost-owasp` přesunout do backlogu** (zapsat do STAV.md):
   omezení pokusů o přihlášení (příběh 4), timeouty session, zkrácená IP v auditu, `__Host-` cookie,
   CSP s nonce. Doporučuji **ano, do M8** (audit a opravy), kromě `Secure`/`__Host-` cookie do M9.
