# 002 – Router, DI kontejner, middleware, šablony, chybové stránky, migrátor a schéma
Stav: hotovo

- **Milník:** M2 · **Režim:** výukový (viz `docs/plan/STAV.md`) — MVP, bez kola security review
- **Autor:** agent architekt · **Datum:** 2026-10-03
- **Souvisí:** [plán 001](001-docker-zaklad.md) (stav po M1), [ADR-0003](../adr/0003-anglicke-identifikatory.md),
  [ADR-0004 anglické názvy v DB](../adr/0004-anglicke-nazvy-v-databazi.md) (navrženo),
  [architektura](../architektura.md), skilly `php-oop-standardy`, `db-migrace`
- **Číslování:** plán 001 slíbil číslo 002 pro CI (`ci.yml`). CI dostane další volné číslo (003).

## Cíl
Návštěvník na `http://localhost:8080/` uvidí první HTML stránku v jednotném českém rozvržení
a místo holého textu dostane srozumitelné chybové stránky 404 / 405 / 500. Vývojář (agent)
přidá novou stránku jedním řádkem v `config/routes.php` a kontrolerem bez ručního skládání
závislostí. Jedním příkazem `make migrate` vytvoří v MariaDB základní schéma (uživatelé,
rubriky, štítky, články, audit), na kterém stojí M3–M5.

## Akceptační kritéria
Unit kritéria ověřuje PHPUnit, HTTP kritéria curl z hostitele, DB kritéria integrační testy nad
`redakce_test` a příkazy v kontejneru `app`.

### A. Router (`tests/Unit/Http/Routing/RouterTest.php`)
1. **Given** trasa `GET /zdravi`, **When** `match('GET', '/zdravi')`, **Then** vrátí `RouteMatch`
   s handlerem `[HealthController::class, '__invoke']` a prázdnými parametry.
2. **Given** trasa `GET /clanek/{slug}`, **When** `match('GET', '/clanek/prvni-clanek')`, **Then**
   parametry jsou `['slug' => 'prvni-clanek']`; **When** `match('GET', '/clanek/a/b')` nebo
   `match('GET', '/clanek/')`, **Then** výjimka `RouteNotFound`.
3. **Given** trasy `GET /x` a `POST /x`, **When** `match('PUT', '/x')`, **Then** výjimka
   `MethodNotAllowed` s `allowedMethods === ['GET', 'POST']`.
4. **Given** trasa `GET /zdravi`, **When** `match('GET', '/zdravi/')` nebo `match('GET', '/neexistuje')`,
   **Then** `RouteNotFound` (bez tolerance koncového lomítka).

### B. DI kontejner (`tests/Unit/Container/ContainerTest.php`)
5. **Given** třída s konstruktorem závislým na jiných konkrétních třídách, **When** `get(Třída::class)`,
   **Then** kontejner ji sestaví autowiringem a druhé `get` vrátí **stejnou instanci**.
6. **Given** `set(DatabaseHealth::class, fn (Container $c) => …)`, **When** `get(HealthController::class)`,
   **Then** controller dostane instanci z továrny (rozhraní se řeší jen přes `set`).
7. **Given** třída s parametrem `string $directory` bez výchozí hodnoty a bez továrny, **When** `get`,
   **Then** `ContainerException`, jejíž zpráva obsahuje název třídy i parametru `$directory`.
8. **Given** rozhraní bez továrny nebo třídy A → B → A, **When** `get`, **Then** `ContainerException`
   (u cyklu zpráva vypíše řetězec `A -> B -> A`), nikdy nekonečná rekurze.
9. **Given** kontejner, **When** `get(Container::class)`, **Then** vrátí sám sebe.

### C. Middleware a chybové stránky (`tests/Unit/Http/Middleware/*`, `tests/Unit/Http/KernelTest.php`)
10. **Given** pipeline s middleware A a B a koncovým handlerem, **When** `handle`, **Then** pořadí
    volání je `A před, B před, handler, B po, A po`; **And** middleware, které nevolá `$next`,
    vrátí vlastní odpověď a handler se nespustí.
11. **Given** Kernel (sestavený z `config/container.php` s náhradou `DatabaseHealth`), **When**
    `GET /neexistuje`, **Then** `404`, `Content-Type: text/html; charset=utf-8`, tělo obsahuje
    „Stránka nenalezena“ a odkaz na `/`, neobsahuje `Stack trace` ani `.php`.
12. **Given** Kernel, **When** `POST /zdravi`, **Then** `405`, hlavička `Allow: GET` a tělo obsahuje
    „Metoda není povolena“.
13. **Given** Kernel s testovací trasou, jejíž controller hodí `RuntimeException('tajny-detail')`,
    **When** požadavek, **Then** `500`, tělo obsahuje „Interní chyba serveru“ a **neobsahuje**
    `tajny-detail`; detail (třída, zpráva, soubor:řádek) jde do `error_log`.
14. **Given** Kernel, **When** `GET /zdravi` při dostupné / nedostupné DB, **Then** beze změny proti M1:
    `200 {"stav":"ok","db":"ok"}` / `503 {"stav":"chyba","db":"chyba"}` včetně hlaviček JSON
    (regrese — stávající testy KernelTest se přepíší na nové sestavení, ne smažou).

### D. Šablony (`tests/Unit/Http/View/*`)
15. **Given** `e('<script>alert("x")</script> & \'')`, **When** volání, **Then** výsledek je
    `&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;` (resp. `&apos;` podle zvolených
    příznaků — test fixuje jednu variantu); neplatné UTF-8 nevrátí prázdný řetězec (`ENT_SUBSTITUTE`).
16. **Given** šablona `home` a rozvržení `layout`, **When** `render('home', ['title' => '<b>Ahoj</b>'])`,
    **Then** výstup obsahuje `<html lang="cs">`, `<title>&lt;b&gt;Ahoj&lt;/b&gt; …</title>` a obsah
    šablony uvnitř `<main>`.
17. **Given** název šablony `../composer` nebo neexistující `nic`, **When** `render`, **Then**
    `TemplateNotFound`; **And** pokud šablona uprostřed hodí výjimku, `ob_get_level()` je po volání
    stejná jako před ním (žádný únik bufferu do odpovědi).

### E. HTTP z hostitele
18. **Given** `make up`, **When** `curl -s http://localhost:8080/ -D -`, **Then** `200`,
    `Content-Type: text/html; charset=utf-8`, tělo obsahuje `<html lang="cs">` a nadpis
    „Redakční systém“.
19. **Given** běžící prostředí, **When** `curl -s http://localhost:8080/neexistuje -w '\n%{http_code}\n'`,
    `curl -s http://localhost:8080/zdravi -X POST -D - -o /dev/null` a
    `curl -s http://localhost:8080/index.php/zdravi -w '\n%{http_code}\n'`, **Then** `404` (HTML stránka),
    `405` s `Allow: GET`, `404`; žádná odpověď není `500` a žádná neobsahuje `<?php`.
20. **Given** běžící prostředí, **When** `curl -s http://localhost:8080/zdravi -w '\n%{http_code}\n'`,
    **Then** přesně `{"stav":"ok","db":"ok"}` a `200` (healthcheck `web` zůstává zelený).

### F. Migrátor a `bin/konzole` (`tests/Unit/Console/*`, `tests/Integration/Migration/MigratorTest.php`)
21. **Given** prázdná `redakce_test` a adresář s fixturami `tests/Integration/Migration/fixtures/`
    (2 migrace), **When** `migrate()`, **Then** obě se provedou v pořadí podle názvu souboru, tabulka
    `migrations` má 2 řádky a druhé `migrate()` vrátí prázdný seznam.
22. **Given** 2 provedené migrace, **When** `rollback(1)`, **Then** vrátí se jen ta poslední
    (`down`, smazaný řádek v `migrations`); `rollback(5)` vrátí zbytek bez chyby.
23. **Given** fixtura, jejíž `up` hodí výjimku, **When** `migrate()`, **Then** předchozí migrace
    zůstanou zapsané, padající zapsaná **není** a výjimka se propaguje (konzole vrátí kód 1).
24. **Given** soubor s neplatným názvem (`moje.php`) nebo soubor, který nevrací `Migration`,
    **When** `migrate()` / `status()`, **Then** `InvalidMigration` se jménem souboru.
25. **Given** `docker compose exec -T app php bin/konzole`, **When** bez argumentu / `neznamy:prikaz`,
    **Then** vypíše seznam příkazů `migrace:spust`, `migrace:vrat`, `migrace:stav` a skončí `0` /
    `1` (chyba na stderr).
26. **Given** čistá dev DB `redakce`, **When** `make migrate`, pak znovu `make migrate`, pak
    `docker compose exec -T app php bin/konzole migrace:stav`, **Then** první běh vypíše
    „Spuštěno: …“ pro každou migraci, druhý „Žádné čekající migrace.“, stav označí všechny `[x]`
    s časem; všechny tři skončí `0`.

### G. Schéma (`tests/Integration/Migration/SchemaTest.php`, migrace z `database/migrations/`)
27. **Given** prázdná `redakce_test`, **When** všechny migrace `up`, **Then** existují tabulky
    `users`, `categories`, `tags`, `articles`, `article_tags`, `audit_log`, `migrations`, všechny
    `ENGINE=InnoDB` a `TABLE_COLLATION = 'utf8mb4_czech_ci'` (dotaz do `information_schema.TABLES`).
28. **Given** schéma a rubrika s článkem, **When** `DELETE` rubriky, **Then** chyba 1451 (FK RESTRICT —
    příběh 8); **When** `DELETE` článku, **Then** jeho řádky v `article_tags` zmizí (CASCADE);
    **When** `DELETE` uživatele, **Then** `articles.created_by/updated_by` a `audit_log.user_id` jsou `NULL`.
29. **Given** schéma, **When** vložení dvou článků se stejným `slug` nebo článku se `status = 'smazano'`,
    **Then** obojí selže (UNIQUE, ENUM ve strict režimu MariaDB).
30. **Given** schéma, **When** `rollback` všech migrací, **Then** zůstane jen prázdná tabulka
    `migrations`; **And** opětovné `migrate()` projde (každé `down` je přesným opakem `up`).

### H. Kvalita
31. **Given** běžící prostředí, **When** `make qa`, **Then** kód 0 (lint včetně `config/`, `templates/`,
    PHPStan max včetně `config/` a `bin/konzole`, PHPUnit Unit + Integration, `composer audit`).
32. **Given** `src/ tests/ config/ bin/ database/`, **When** grep z AC 16 plánu 001 (české znaky
    v identifikátorech), **Then** nic; SQL je jen v `*Repository` a v souborech `database/migrations/`.

## Návrh

### 1. Přehled a tok požadavku
```
public/index.php → config/container.php (Container) → Kernel::handle(Request)
  → MiddlewarePipeline [ErrorHandlerMiddleware, (M3: SecurityHeaders → Session → Csrf → Auth → Authz)]
  → Kernel::dispatch: Router::match → Container::get(controller) → controller->method(Request) → Response
```
`Kernel` je jediné místo (kromě `bin/konzole` a `index.php`), které smí sahat do kontejneru —
dispečer je součást kompoziční vrstvy, controllery dostávají závislosti konstruktorem.

### 2. Nové a změněné třídy
| Soubor | Typ | Odpovědnost |
|---|---|---|
| `src/Container/Container.php` | `final class` | `set(class-string $id, \Closure(Container): object $factory)`, `get(class-string<T>): T` (PHPDoc `@template`), `has()`. Autowiring přes `ReflectionClass`: parametr s typem třídy/rozhraní → `get()`, s výchozí hodnotou → výchozí hodnota, jinak výjimka. Union/intersection typy → výjimka. Instance sdílené (jedna na id). Detekce cyklu zásobníkem právě sestavovaných id. `get(Container::class)` vrací `$this`. |
| `src/Container/ContainerException.php` | `final class … extends \RuntimeException` | zpráva jmenuje třídu, parametr, případně řetězec cyklu |
| `src/Http/Routing/Router.php` | `final class` | `get(string $path, array{class-string, string} $handler)`, `post(…)`, `match(string $method, string $path): RouteMatch`. Vzor `{name}` (`[a-z_]+`) → regex `[^/]+`, hodnota přes `rawurldecode`. Pojmenované trasy a `url()` **ne** (M4). |
| `src/Http/Routing/RouteMatch.php` | `final readonly class` | `handler`, `array<string, string> parameters` |
| `src/Http/Routing/RouteNotFound.php` | `final class … extends \RuntimeException` | — |
| `src/Http/Routing/MethodNotAllowed.php` | `final class … extends \RuntimeException` | `public readonly array $allowedMethods` (`list<string>`, seřazené) |
| `src/Http/Middleware/Middleware.php` | `interface` | `process(Request $request, callable $next): Response` (`@param callable(Request): Response $next`) — reálná použití: `ErrorHandlerMiddleware` teď, 4 další v M3 |
| `src/Http/Middleware/MiddlewarePipeline.php` | `final readonly class` | `__construct(list<Middleware>)`, `handle(Request, callable(Request): Response $handler): Response` — první v seznamu = nejvnější |
| `src/Http/Middleware/ErrorHandlerMiddleware.php` | `final readonly class` | `RouteNotFound` → 404, `MethodNotAllowed` → 405 + `Allow: GET, POST`, jiný `\Throwable` → 500 + `error_log` (třída, zpráva, soubor:řádek). Stránku vykreslí přes `TemplateRenderer` (`error`); selže-li i to, `Response::text`. Detail výjimky **nikdy** do těla (ani při `APP_DEBUG=1` — otázka 5). |
| `src/Http/View/TemplateRenderer.php` | `final readonly class` | `__construct(string $directory)`, `render(string $template, array<string, mixed> $data = [], ?string $layout = 'layout'): string`. Název šablony jen `^[a-z0-9_-]+(/[a-z0-9_-]+)*$`, jinak `TemplateNotFound`. Šablona běží v izolovaném scope (statická closure + `extract($data, EXTR_SKIP)`), `ob_start` / `ob_get_clean`, při výjimce `ob_end_clean` a znovu vyhodit. Rozvržení dostane `$content` (HTML) + stejná data. |
| `src/Http/View/TemplateNotFound.php` | `final class … extends \RuntimeException` | — |
| `src/Http/View/helpers.php` | funkce (autoload `files`) | `e(string\|int\|float\|null $value): string` = `htmlspecialchars((string) $value, ENT_QUOTES \| ENT_SUBSTITUTE \| ENT_HTML5, 'UTF-8')`. `e_attr`, `url`, `csrf_field` až s prvním použitím (M3/M4). Obal `if (!function_exists('e'))`. |
| `src/Http/Request.php` | změna | + `array<string, string> $routeParameters = []`, `withRouteParameters(array): self`, `routeParameter(string $name): string` (chybí → `\LogicException`) |
| `src/Http/Response.php` | změna | + `html(string $body, int $status = 200, array $extraHeaders = [])` — `text/html; charset=utf-8`, `Cache-Control: no-store`, `nosniff` |
| `src/Http/Kernel.php` | přepis | `__construct(Router, Container, MiddlewarePipeline)`; `handle()` = pipeline + `dispatch()`; controller musí vrátit `Response`, jinak `\LogicException` (→ 500) |
| `src/Http/Controller/HealthController.php` | změna | `__invoke(Request $request): Response` (jednotná signatura controllerů), chování beze změny |
| `src/Http/Controller/HomeController.php` | nový | `index(Request): Response` — šablona `home` s titulkem „Redakční systém“ a textem „Články přibudou v dalším milníku.“ (M4 ji nahradí výpisem) |
| `src/Console/Command.php` | `interface` | `run(list<string> $arguments, Output $output): int` — 3 použití teď, `admin:vytvor` (M3), `ai:priklad` (M6) |
| `src/Console/Output.php` | `final readonly class` | `__construct(resource $stdout, resource $stderr)`, `line(string)`, `error(string)`; testy používají `php://memory` |
| `src/Console/ConsoleApplication.php` | `final readonly class` | mapa `array<string, class-string<Command>>` (název příkazu → třída), `run(list<string> $argv, Output): int`; bez argumentu nápověda (0), neznámý příkaz (1), výjimka z příkazu → `error()` se zprávou, kód 1 |
| `src/Console/Command/MigrateCommand.php` | `final readonly class` | `migrace:spust` → „Spuštěno: <název>“ / „Žádné čekající migrace.“ |
| `src/Console/Command/RollbackCommand.php` | `final readonly class` | `migrace:vrat [--kroky=N]` (výchozí 1, N ≥ 1, jinak chyba) → „Vráceno: <název>“ |
| `src/Console/Command/MigrationStatusCommand.php` | `final readonly class` | `migrace:stav` → řádky `[x] <název> (<executed_at>)` / `[ ] <název>` |
| `src/Infrastructure/Migration/Migration.php` | `interface` | `up(\PDO $pdo): void`, `down(\PDO $pdo): void` — implementuje každý soubor migrace |
| `src/Infrastructure/Migration/Migrator.php` | `final readonly class` | `__construct(PdoMigrationRepository, \PDO, string $directory)`; `migrate(): list<string>`, `rollback(int $steps = 1): list<string>`, `status(): list<MigrationStatus>`. Soubory `^\d{12}_[a-z0-9_]+\.php$` seřazené podle názvu; `require` musí vrátit `Migration`, jinak `InvalidMigration`. Bez transakcí (MariaDB DDL implicitně commituje → jedna tabulka = jedna migrace); záznam do `migrations` až po úspěšném `up`. Provedená migrace bez souboru při `rollback` → `InvalidMigration`. |
| `src/Infrastructure/Migration/PdoMigrationRepository.php` | `final readonly class` | jediné SQL migrátoru: `ensureTable()`, `executed(): array<string, string>` (název → čas), `markExecuted(string)`, `forget(string)`, `lastExecuted(int $limit): list<string>` (řazeno `executed_at DESC, name DESC`) |
| `src/Infrastructure/Migration/MigrationStatus.php` | `final readonly class` | `name`, `?string executedAt` |
| `src/Infrastructure/Migration/InvalidMigration.php` | `final class … extends \RuntimeException` | — |
| `src/Infrastructure/Config/DatabaseConfig.php` | změna | + `forMigrations(array $environment): self` — host/port/název jako `fromEnvironment`, uživatel `DB_MIGRACE_USER` (výchozí `redakce_migrace`), heslo `DB_MIGRACE_PASSWORD` (povinné) |

Rozhraní repozitáře pro migrace se **nezavádí** (jedna implementace, YAGNI); `Migrator` patří do
`Infrastructure`, protože pracuje s PDO a soubory.

### 3. Konfigurace (kompoziční kořen)
- `config/container.php` — vrací sestavený `Container`: továrny pro `DatabaseConfig` (z `getenv()`),
  `DatabaseHealth` → `PdoDatabaseHealthRepository`, `TemplateRenderer` (adresář `templates/`),
  `Router` (načte `config/routes.php`), `MiddlewarePipeline` (`[ErrorHandlerMiddleware]`),
  `ConsoleApplication` (mapa příkazů), `Migrator` (vlastní PDO z `DatabaseConfig::forMigrations`,
  sdílené s `PdoMigrationRepository`, adresář `database/migrations/`). Továrny jsou líné — web
  nikdy nesahá na migrační heslo.
- `config/routes.php` — `return static function (Router $router): void { … }` s trasami
  `GET /` → `HomeController::index`, `GET /zdravi` → `HealthController::__invoke`.
- `public/index.php` — `$container = require …/config/container.php;` →
  `$container->get(Kernel::class)->handle(Request::fromGlobals())->send()`; vnější `try/catch`
  z M1 zůstává jako poslední pojistka (chyba při sestavení kontejneru).
- `bin/konzole` — `#!/usr/bin/env php`, autoload, kontejner,
  `exit($container->get(ConsoleApplication::class)->run(array_values(array_slice($argv, 1)), new Output(STDOUT, STDERR)));`
  (`exit` je povolen jen v tomto vstupním bodu — výjimka z `.claude/rules/php.md`).

### 4. Šablony
- `templates/layout.php` — `<!doctype html>`, `<html lang="cs">`, `<meta charset="utf-8">`,
  `<meta name="viewport" …>`, `<title><?= e($title ?? '') ?> · Redakční systém</title>`,
  odkaz na `/assets/app.css`, `<header>` s odkazem „Redakční systém“ na `/`, `<main>` s `$content`
  (jediný neescapovaný výpis — už vykreslené HTML, komentář v šabloně), `<footer>`.
- `templates/home.php` — nadpis a zástupný text.
- `templates/error.php` — `$status`, `$title`, `$message`; odkaz „Zpět na titulní stránku“.
  Texty: 404 „Stránka nenalezena“, 405 „Metoda není povolena“, 500 „Interní chyba serveru“
  + „Omlouváme se, něco se pokazilo. Zkuste to prosím později.“
- `public/assets/app.css` — minimální čitelné a responzivní styly (systémové písmo, max. šířka,
  kontrast AA). Žádný inline `<style>`/`<script>` (příprava na CSP v M3).

### 5. Změny DB (migrace píše databazista, názvy dle ADR-0004)
Všechny tabulky `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci`, PK
`id BIGINT UNSIGNED AUTO_INCREMENT`, časy `DATETIME(6)`, `created_at … DEFAULT CURRENT_TIMESTAMP(6)`,
`updated_at … DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)`.
Soubory `database/migrations/YYYYMMDDHHMM_<popis>.php`, každý `return new class implements Migration { … };`.

| Migrace | Tabulka | Sloupce a omezení |
|---|---|---|
| `…_create_users_table` | `users` | `email VARCHAR(190) NOT NULL` (`uq_users_email`), `display_name VARCHAR(100) NOT NULL`, `password_hash VARCHAR(255) NOT NULL`, `role ENUM('admin') NOT NULL DEFAULT 'admin'`, `last_login_at DATETIME(6) NULL`, `created_at`, `updated_at`. Brute-force evidence až M3 (vlastní migrace). |
| `…_create_categories_table` | `categories` | `name VARCHAR(100) NOT NULL`, `slug VARCHAR(120) NOT NULL` (`uq_categories_slug`), `created_at`, `updated_at` |
| `…_create_tags_table` | `tags` | `name VARCHAR(60) NOT NULL`, `slug VARCHAR(80) NOT NULL` (`uq_tags_slug`), `created_at` |
| `…_create_articles_table` | `articles` | `category_id BIGINT UNSIGNED NOT NULL` (FK → `categories` **ON DELETE RESTRICT**), `title VARCHAR(200) NOT NULL`, `slug VARCHAR(220) NOT NULL` (`uq_articles_slug`), `excerpt VARCHAR(500) NOT NULL DEFAULT ''`, `body MEDIUMTEXT NOT NULL` (Markdown), `status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft'`, `published_at DATETIME(6) NULL`, `created_by`/`updated_by BIGINT UNSIGNED NULL` (FK → `users` ON DELETE SET NULL), `created_at`, `updated_at`; indexy `idx_articles_status_published_at (status, published_at)`, `idx_articles_category_status_published_at (category_id, status, published_at)` |
| `…_create_article_tags_table` | `article_tags` | `article_id`, `tag_id` (FK ON DELETE CASCADE obě), `PRIMARY KEY (article_id, tag_id)`, `idx_article_tags_tag_id (tag_id)` |
| `…_create_audit_log_table` | `audit_log` | `user_id BIGINT UNSIGNED NULL` (FK → `users` ON DELETE SET NULL), `action VARCHAR(50) NOT NULL` (např. `article.deleted`), `entity_type VARCHAR(50) NULL`, `entity_id BIGINT UNSIGNED NULL` (bez FK — entita může být smazaná), `summary VARCHAR(255) NOT NULL DEFAULT ''` (kopie titulku), `ip_address VARCHAR(45) NULL`, `created_at`; indexy `idx_audit_log_created_at`, `idx_audit_log_action_created_at` |
| (migrátor) | `migrations` | `name VARCHAR(190) PRIMARY KEY`, `executed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` — vytváří `PdoMigrationRepository::ensureTable()` |

Pořadí migrací respektuje FK (users → categories → tags → articles → article_tags → audit_log);
`down` = `DROP TABLE` v opačném pořadí. FULLTEXT pro `/hledat` přidá M4 vlastní migrací.
Práva: `redakce_app` (DML) a `redakce_cteni` (SELECT) mají granty na úrovni databáze → nové tabulky
pokryté bez změny init skriptu.

### 6. Prostředí a nástroje
- `compose.yaml`, služba `app`, `environment:` + `DB_MIGRACE_USER: redakce_migrace`,
  `DB_MIGRACE_PASSWORD: ${DB_MIGRACE_PASSWORD:?chybí …}` (vrací rozhodnutí z revize M1 S4 —
  **otázka 1**; integrační testy migrátoru potřebují DDL v `redakce_test`).
- `Makefile`: cíl `migrate` = `$(COMPOSE) exec -T app php bin/konzole migrace:spust`
  (+ do `.PHONY` a `help`). Ostatní příkazy přes `docker compose exec -T app php bin/konzole …`.
- `composer.json`: `autoload.files: ["src/Http/View/helpers.php"]`; skript `lint` nad
  `src tests public config templates`; `bin/konzole` lintovat explicitně. Žádná nová závislost.
- `phpstan.neon.dist`: `paths` + `config`, `bin/konzole` (šablony ne — proměnné z `extract`).
- `.php-cs-fixer.dist.php`: adresáře + `config` (a `database` pro migrace).

### 7. Testy (píše tester; názvy anglicky)
- Unit: `Routing/RouterTest`, `Container/ContainerTest` (s malými testovacími třídami
  v `tests/Unit/Container/Fixtures/`), `Middleware/MiddlewarePipelineTest`,
  `Middleware/ErrorHandlerMiddlewareTest`, `View/TemplateRendererTest` (šablony z
  `tests/Unit/Http/View/templates/`), `View/EscapeTest`, `Console/ConsoleApplicationTest`,
  přepsaný `Http/KernelTest` (sestavení přes `config/container.php` + `set(DatabaseHealth::class, …)`;
  test `test_root_path_returns_404_in_milestone_one` nahradí „`/` vrací 200 HTML“),
  `Infrastructure/Config/DatabaseConfigTest` (+ `forMigrations`).
- Integrační: `tests/Integration/Migration/MigratorTest` (fixtury), `SchemaTest` (skutečné migrace).
  Společný pomocník `tests/Integration/TestDatabase::reset()` — připojí se jako migrační uživatel,
  **ověří, že název DB je `redakce_test`** (jinak výjimka), a smaže všechny tabulky
  (`FOREIGN_KEY_CHECKS=0`). Volá se v `setUp` každé třídy, která mění schéma.
- `tests/E2E-scenare.md`: Z4 doplnit o HTML chybové stránky; nový scénář „Titulní stránka“
  (curl AC 18 + Playwright `http://web/` → snímek `tests/_artefakty/titulni-m2.png`).

## Dotčené soubory
**Nové:** `src/Container/{Container,ContainerException}.php`,
`src/Http/Routing/{Router,RouteMatch,RouteNotFound,MethodNotAllowed}.php`,
`src/Http/Middleware/{Middleware,MiddlewarePipeline,ErrorHandlerMiddleware}.php`,
`src/Http/View/{TemplateRenderer,TemplateNotFound,helpers}.php`, `src/Http/Controller/HomeController.php`,
`src/Console/{Command,Output,ConsoleApplication}.php`,
`src/Console/Command/{MigrateCommand,RollbackCommand,MigrationStatusCommand}.php`,
`src/Infrastructure/Migration/{Migration,Migrator,PdoMigrationRepository,MigrationStatus,InvalidMigration}.php`,
`config/{container,routes}.php`, `bin/konzole`, `templates/{layout,home,error}.php`, `public/assets/app.css`,
`database/migrations/*_create_{users,categories,tags,articles,article_tags,audit_log}_table.php`,
testy dle §7, `docs/adr/0004-anglicke-nazvy-v-databazi.md`.

**Změněné:** `src/Http/{Kernel,Request,Response}.php`, `src/Http/Controller/HealthController.php`,
`src/Infrastructure/Config/DatabaseConfig.php`, `public/index.php`, `composer.json` (jen autoload/skripty),
`phpstan.neon.dist`, `.php-cs-fixer.dist.php`, `compose.yaml` (chráněný), `Makefile` (chráněný),
`tests/Unit/Http/KernelTest.php`, `tests/Unit/Infrastructure/Config/DatabaseConfigTest.php`,
`tests/E2E-scenare.md`, `docs/architektura.md`, `docs/tutorial.html` (kapitola M2, řádek se
zástupným textem „po milníku M2“), se souhlasem: `.claude/skills/db-migrace/SKILL.md`
(anglické názvy, `migrations(name, executed_at)`, příkazy bez `db:seed`).

## Úkoly pro agenty
Brána 1 (člověk) schvaluje: tento plán, ADR-0004 a otázku 1 (migrační heslo v `app`).

| # | Fáze | Agent | Úkol | Výstup | Souběh |
|---|---|---|---|---|---|
| T1 | 1 | `tester` (režim A) | testy z §7 pro AC 1–17, 21–25, 27–30 (unit + integrační + fixtury migrací); E2E scénáře | soubory testů; po T3 doložit RED ze správného důvodu (chybí třídy, ne překlep) | ∥ T2, T3 |
| T2 | 1 | `databazista` | 6 migrací dle §5 (rozhraní `Migration` je dané plánem, soubory lze psát předem); úprava skillu `db-migrace` dle ADR-0004 (**souhlas člověka**, `.claude/`) | migrace + krátké zdůvodnění indexů; ověření po T4 přes AC 27–30 a `make migrate` | ∥ T1, T3 |
| T3 | 1 | `devops` (**souhlas**, chráněné soubory) | §6: `compose.yaml` (migrační proměnné v `app`), `Makefile` cíl `migrate` | diff; `docker compose config --quiet` OK; `make up` zelené | ∥ T1, T2 |
| T4 | 2 | `programator` | §2–§4 a §6 (composer/phpstan/cs-fixer) až do GREEN; nejdřív kontejner + router + kernel (AC 1–20), pak migrátor + konzole (AC 21–26) | `make qa` zelené; výstup curl AC 18–20 a `make migrate` AC 26 | po T1+T3; s T2 se potká v AC 27–30 |
| T5 | 3 | `tester` (režim B) | `make qa`, AC 18–20, 26, 31–32, E2E scénáře (curl + Playwright snímek titulní stránky) | PASS/FAIL po kritériích; FAIL vrací T4 (kód) nebo T2 (schéma) | po T4 |
| T6 | 3 | `technicky-spisovatel` | kapitola M2 v `docs/tutorial.html` (router, kontejner, middleware, šablony a `e()`, migrátor, schéma — ER diagram Mermaid), README: `make migrate` | ověřené příkazy | ∥ T5 |
| — | 4 | vedoucí | report → **brána 2** → commity | — | — |

Security review se v tomto milníku nespouští (výukový režim, STAV.md).

Návrh commitů (každý projde `make up` + `make qa`):
1. `feat(http): router, DI kontejner, middleware a chybové stránky` (T1 část A–E + T4 část 1)
2. `feat(db): migrátor, bin/konzole a make migrate` (T3 + T1 část F + T4 část 2)
3. `feat(db): základní schéma – uživatelé, rubriky, štítky, články, audit` (T2 + `SchemaTest`)
4. `docs: plán 002, ADR-0004, architektura a kapitola M2` (T6 + tento plán)

## Rizika a bezpečnost
- **Integrační testy mažou tabulky.** Pojistka v `TestDatabase::reset()` (název DB musí být
  `redakce_test`) + `force="true"` v `phpunit.xml.dist`. Bez ní by test smazal dev data (`redakce`).
- **Migrační heslo v kontejneru `app`** (otázka 1): aplikace by technicky mohla DDL. Web ho
  nepoužívá (líná továrna jen pro konzoli). V produkci kontrakt stejně spouští migrace přes
  `exec app` — řešení pro M9 (oddělená služba / jednorázový kontejner) zapsat do STAV.md.
- **DDL bez transakcí:** padající migrace může nechat tabulku napůl — proto jedna tabulka na
  migraci a `CREATE TABLE` bez dalších kroků. Souběžné spuštění migrátoru (dva deploye) se
  neřeší (`GET_LOCK` je mimo MVP).
- **XSS:** jediné escapování je `e()`; neescapovaný výpis smí být jen `$content` v layoutu.
  Tester přidá test, že titulek s `<script>` vyjde escapovaný (AC 16). Revize šablon v M4/M5
  hlídá, že nikdo nepíše `<?= $x ?>` bez `e()`.
- **Únik informací:** chybové stránky bez detailu výjimky, detail jen do `error_log`
  (`docker compose logs app`). Path traversal v názvu šablony blokuje regex (AC 17).
- **Autowiring přes reflexi** sestaví libovolnou třídu, jejíž název dostane — kontejner se volá
  jen s názvy z kódu (trasy, konfigurace), nikdy se vstupem uživatele.
- **`redakce_cteni` čte `users.password_hash`** (MCP pro agenty). Sloupcová práva až M3 (plán 001).
- **Produkční obraz (M9):** `prod` stage musí kopírovat i `config/`, `templates/`, `database/`, `bin/`.
  Teď to `make up` neověří (dev mount `.:/app:ro`) — zapsat do úkolu M9.
- **Změna testů z M1:** přepis `KernelTest` nesmí ztratit regresní kontrolu kontraktu `/zdravi` (AC 14).
- **LLM rizika:** M2 neobsahuje AI. Jediný styk: data v DB jsou pro agenty (MCP) nedůvěryhodná.

## Mimo rozsah
- Session, CSRF, bezpečnostní hlavičky/CSP, autentizace, autorizace, `admin:vytvor` (M3).
- Pojmenované trasy, `url()`, `e_attr()`, `csrf_field()`, flash zprávy, query/POST data v `Request` (M3/M4).
- Middleware jen pro některé trasy (M3 — admin), `HEAD` požadavky, konfigurovatelné koncové lomítko.
- `db:seed`, `make seed` a ukázková data (M4 — otázka 3); FULLTEXT index (M4); vektory (M7).
- Zámek migrátoru, `migrace:vrat` na konkrétní verzi, generátor nových migrací.
- Kešování kontejneru/tras, detail chyb v debug režimu (otázka 5), logger (PSR-3) — zatím `error_log`.

## Otázky pro člověka
1. **Migrační heslo v kontejneru `app`:** přidat `DB_MIGRACE_USER`/`DB_MIGRACE_PASSWORD` do
   `environment` služby `app` (vrací rozhodnutí z revize M1 „app nevidí migrační heslo“)?
   Doporučuji **ano** (výukový režim; `make migrate` i integrační testy pak fungují bez triků;
   produkční kontrakt spouští migrace stejně v `app`). Alternativa: migrace jako `redakce_app`
   s přidaným DDL právem (horší — DDL by měl i web).
2. **ADR-0004:** potvrdit anglické tabulky/sloupce/ENUM hodnoty vč. `excerpt` (perex) a `body`
   (text článku)? Doporučuji ano.
3. **Seed data:** přesunout `db:seed` a ukázkové články do M4 (kde se poprvé zobrazují)?
   Plán 001 je sliboval do M2. Doporučuji M4.
4. **Rubrika povinná:** článek musí mít rubriku (`category_id NOT NULL` + RESTRICT podle příběhu 8)?
   Doporučuji ano.
5. **Detail chyby při `APP_DEBUG=1`:** ukazovat na stránce 500 zprávu výjimky (pohodlnější výuka),
   nebo jen do logu? Doporučuji jen do logu (jednodušší, žádná větev navíc).
6. **Úprava skillu `db-migrace`** (`.claude/` = souhlas) na anglické názvy a bez `db:seed` v M2?
   Doporučuji ano, jinak budou agenti dostávat protichůdné pokyny.
