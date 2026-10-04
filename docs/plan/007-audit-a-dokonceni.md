# 007 – Audit log v administraci, opravy z backlogu a dokončení tutoriálu
Stav: hotovo

- **Milník:** M8 (zadání: „Audit (`/audit`), opravy, dokončení tutoriálu“; uživatelský příběh 9) ·
  **Režim:** výukový (viz `docs/plan/STAV.md`) — MVP, bez kola security review
- **Autor:** agent architekt · **Datum:** 2026-10-04
- **Souvisí:** [plán 003](003-prihlaseni-admin.md) (`audit_log`, `AuditEntry`, `AdminAccessMiddleware`),
  [plán 005](005-sprava-clanku.md) (stránkování v administraci, čas z `Clock`), [plán 006](006-ai-jadro.md)
  (stav po M6), **[ADR-0007](../adr/0007-casy-v-databazi-utc-vs-praha.md) (nové, navrženo)**,
  [architektura](../architektura.md), skilly `php-oop-standardy`, `bezpecnost-owasp`, `tutorial-kapitola`, `db-migrace`
- **Číslování:** číslo 007 dostává M8 (M7 – AI příklady 06–10 – zatím nemá plán). CI (`ci.yml`), slibované od plánu
  001, se přečísluje na **008 nebo pozdější**; M7 dostane číslo, až se bude plánovat (otázka 11). `docs/plan/STAV.md`
  je upravený; plán 006 (hotovo) se zpětně nemění.
- **`/audit` není tato stránka.** Skill `.claude/skills/audit/SKILL.md` je **příkaz pro člověka** (`disable-model-invocation`),
  který spustí paralelní revizi repozitáře týmem agentů (security, testy, architektura, DB, dokumentace) s výstupem do
  `docs/audit/`. Uživatelský příběh 9 je **stránka audit logu v aplikaci** (`/admin/audit`, data z tabulky `audit_log`).
  Plán 007 řeší jen příběh 9; spuštění `/audit` je samostatný krok člověka (otázka 1).
- **Schéma DB se nemění** (indexy `idx_audit_log_created_at` a `idx_audit_log_action_created_at` z migrace
  `202610030006` pokryjí oba filtry) → úkol pro `databazista` není. Žádná migrace.
- **Nová composer závislost žádná.** Žádná změna `compose.yaml`, nginx ani `.github/`.

## Cíl
Přihlášený admin otevře z rozcestníku „Audit log“ (`http://localhost:8080/admin/audit`) a vidí, kdo a kdy se přihlásil,
odhlásil nebo měnil články — nejnovější nahoře, po 50 na stránku, se jménem uživatele a časem v pražském čase.
Výpis zúží podle akce a rozsahu dat (od–do) a stránkování filtr zachová. Zároveň se uzavřou drobné dluhy z M3–M6
(text u stavového řádku 422, vnořená pole formuláře, ikona webu, časy seedovaných článků, nepravdivá věta v README
o `SESSION_COOKIE_SECURE`) a tutoriál dostane skutečné kapitoly „Architektura aplikace“, „Bezpečnost v kódu“ a kapitolu M8.

## Akceptační kritéria
Unit kritéria ověřuje PHPUnit přes Kernel (`TestContainer`, `ArraySession`, `FixedClock` na `2026-10-04 12:00`
`Europe/Prague`, přihlášení jako v `AdminLoginFlowTest`, admin `id 7` „Administrátor“), integrační PHPUnit nad
`redakce_test`, HTTP kritéria curl z hostitele (povolené volby hooku, cookie jen `-H 'Cookie: …'`, URL bez uvozovek)
a Playwright MCP (URL podle hlavičky `tests/E2E-scenare.md`, viz AC 24).

**Kontrakt testovacích dat (unit):** `InMemoryAuditLogRepository` dostane seznam `records` (`AuditLogRecord`) a metody
`count`/`search` se **stejnou sémantikou jako SQL** (filtr, řazení `createdAt DESC, id DESC`, limit/offset). Výchozí
sada: `#1 2026-10-02 23:59:59 auth.login_failed (user null, summary 'x@example.cz', IP 172.19.0.1)`,
`#2 2026-10-03 00:00:00 auth.login (7, Administrátor)`, `#3 2026-10-03 23:59:59 article.created (7, article #5,
'Titulek [titulek]')`, `#4 2026-10-04 00:00:00 article.deleted (7, article #5)`, `#5 2026-10-04 10:15:30 auth.logout (7)`
— všechny časy v `Europe/Prague`.

### A. Stránka audit logu – HTTP (unit, `tests/Unit/Http/AdminAuditLogTest.php`)
1. **Přístup:** nepřihlášený `GET /admin/audit` → `303` `Location: /admin/prihlaseni`; `POST /admin/audit` (přihlášeně
   i s platným `_csrf`) → `405` s `Allow: GET`; `GET /admin/audit/1` → `404`. Repozitář se v těchto případech nevolá.
2. **Výpis:** přihlášený `GET /admin/audit` → `200`, `<h1>Audit log</h1>`, text „Počet záznamů: 5“, tabulka se
   záhlavím `Čas | Akce | Uživatel | Objekt | Shrnutí | IP adresa` a řádky v pořadí #5, #4, #3, #2, #1. Řádek #5 má
   `<time datetime="2026-10-04T10:15:30+02:00">4. října 2026 10:15:30</time>`, akci „Odhlášení“, uživatele
   „Administrátor“; řádek #3 objekt „článek #5“; řádek #1 uživatele „—“, shrnutí `x@example.cz`, IP `172.19.0.1`.
   Popisky akcí (`AuditAction::label()`): `auth.login` „Přihlášení“, `auth.login_failed` „Neúspěšné přihlášení“,
   `auth.logout` „Odhlášení“, `user.created` „Vytvoření účtu“, `article.created` „Vytvoření článku“,
   `article.updated` „Úprava článku“, `article.deleted` „Smazání článku“. Objekt: `article` → „článek #N“,
   `user` → „uživatel #N“, jiný typ → „{typ} #N“, bez typu „—“. Akce mimo výčet (např. `legacy.x` z budoucí verze)
   se zobrazí doslova, bez výjimky.
3. **Formulář filtru:** stránka obsahuje `<form method="get" action="/admin/audit">` **bez** `_csrf` (GET nic nemění),
   `<label for="akce">Akce</label>` + `<select name="akce" id="akce">` s volbou `value=""` „Všechny akce“ a sedmi akcemi
   v pořadí výčtu `AuditAction` (hodnota = kód akce, text = popisek), `<label for="od">Od</label>` +
   `<input type="date" name="od" id="od">`, `<label for="do">Do</label>` + `<input type="date" name="do" id="do">`,
   tlačítko „Filtrovat“ a odkaz „Zrušit filtr“ (`href="/admin/audit"`). Po odeslání formulář ukazuje aktuální filtr
   (vybraná volba `selected`, `value` u dat).
4. **Filtr akce:** `?akce=article.deleted` → `200`, „Počet záznamů: 1“, jen řádek #4.
5. **Filtr data (hranice dne v pražském čase):** `?od=2026-10-03&do=2026-10-03` → řádky #3, #2 (ne #1 z 23:59:59
   předchozího dne, ne #4 z půlnoci dalšího dne); jen `?od=2026-10-04` → #5, #4; jen `?do=2026-10-02` → #1;
   `?akce=auth.login&od=2026-10-03` → #2. `od` je včetně (00:00:00 toho dne), `do` je včetně celého dne
   (repozitář dostane horní mez „< následující den 00:00:00“).
6. **Prázdné výsledky:** bez záznamů a bez filtru → „Audit log je zatím prázdný.“; s filtrem bez shody →
   „Filtru neodpovídá žádný záznam.“ (obojí `200`, bez tabulky, formulář zůstává).
7. **Neplatný filtr → `422`** s formulářem a souhrnem chyb v `role="alert"`, bez tabulky, `count`/`search` repozitáře
   se **nevolají**: `akce=xyz` → „Vyberte akci ze seznamu.“; `od` = `2026-13-01`, `2026-02-30`, `3.10.2026`, `2026-1-1`
   → „Zadejte datum od ve tvaru RRRR-MM-DD.“; totéž pro `do` („… datum do …“); `od=2026-10-04&do=2026-10-03` →
   „Datum od nesmí být pozdější než datum do.“ Pole s chybou má `aria-invalid="true"`. Parametr poslaný jako pole
   (`akce[]=x`) se ignoruje stejně jako jinde v aplikaci (`Request` zahazuje pole z query) → bez filtru, `200`.
8. **Stránkování (50 na stránku) se zachováním filtru:** 120 záznamů `auth.login` + 3 jiné; `?akce=auth.login` →
   50 řádků, „Strana 1 z 3“, odkaz „Další strana“ `href="/admin/audit?akce=auth.login&amp;strana=2"`, žádná
   „Předchozí strana“; `?akce=auth.login&strana=3` → 20 řádků, „Předchozí strana“ → `…?akce=auth.login&amp;strana=2`;
   ze strany 2 vede „Předchozí strana“ na `/admin/audit?akce=auth.login` (bez `strana`). Pořadí parametrů v odkazu:
   `akce`, `od`, `do`, `strana`; prázdné parametry se vynechají. `strana=4` → `404`; `strana` = `0`, `abc`, `01` → `404`
   (`PageNumber`). Prázdný výsledek je platná strana 1.
9. **Escapování:** záznam se shrnutím `<script>alert(1)</script>` (typicky `auth.login_failed` – e-mail zadal útočník)
   a uživatel se jménem `<b>Eva</b>` → stránka obsahuje jen `&lt;script&gt;` a `&lt;b&gt;`; `?od="><script>` → `422`
   a hodnota se v `value` objeví jen escapovaná (`&quot;&gt;&lt;script&gt;`), nikdy `<script>`.
10. **Rozcestník:** `GET /admin` obsahuje `<a href="/admin/audit">Audit log</a>`.

### B. Aplikační vrstva (unit, `tests/Unit/Application/Audit/AuditLogSearchTest.php`)
11. `AuditLogSearch::filter('', '', '')` → prázdný filtr (`isEmpty() = true`); `filter('auth.login', '2026-10-03', '2026-10-03')`
    → `action = AuditAction::LoginSucceeded`, `from = 2026-10-03 00:00:00 Europe/Prague`, `until = 2026-10-04 00:00:00
    Europe/Prague`; přes přechod času `filter('', '2026-10-25', '2026-10-25')` → `until − from = 25 h`. Neplatné vstupy
    z AC 7 → `InvalidAuditLogFilter` s polem `errors` (klíče `akce`, `od`, `do`; víc chyb najednou možné).
12. `page(filter, 1)` volá repozitář přesně dvakrát (`count`, `search(filter, 50, 0)`); `page(filter, 3)` při 120 záznamech
    → `search(filter, 50, 100)`, `totalPages 3`; stránka mimo rozsah → `null`; 0 záznamů → strana 1 s prázdným seznamem.

### C. Persistence a časová pásma (integrační, `tests/Integration/Persistence/PdoAuditLogRepositoryTest.php`)
13. **Zóna spojení:** spojení z `ConnectionFactory` vrací `SELECT @@session.time_zone` = `+00:00`. `add()` se nemění;
    po `add()` je `created_at` nového řádku v UTC (rozdíl od `UTC_TIMESTAMP(6)` < 5 s).
14. **Převod při čtení:** řádky vložené SQL s `created_at` v UTC `2026-10-02 21:59:59` (= 23:59:59 Praha), `2026-10-02 22:00:00`
    (= 00:00 Praha), `2026-12-01 10:00:00` (zimní čas): `search(filter od 2026-10-03 Praha, do bez omezení)` vrátí druhý
    a třetí, **ne** první; `createdAt->format('Y-m-d H:i:s P')` = `2026-10-03 00:00:00 +02:00`, resp. `2026-12-01 11:00:00 +01:00`;
    `count()` se stejným filtrem = 2.
15. **JOIN a pořadí:** jméno uživatele z `users.display_name` přes `LEFT JOIN` (záznam s `user_id NULL` má `userName null`);
    řazení `created_at DESC, id DESC` (dva záznamy se stejným časem → vyšší `id` první); `search` s limitem 2 a offsetem 1
    vrací správné řádky; filtr akce se váže parametrem. Každá metoda = jeden SQL dotaz (žádné N+1 – kontrola v revizi
    kódu a v `EXPLAIN`, AC 27).

### D. Opravy z backlogu
16. **Stavový řádek s textem (unit `tests/Unit/Http/ResponseReasonPhraseTest.php` + curl):** `Response::reasonPhrase()` vrací
    `200 OK`, `303 See Other`, `403 Forbidden`, `404 Not Found`, `405 Method Not Allowed`, `422 Unprocessable Content`,
    `429 Too Many Requests`, `500 Internal Server Error`, `502 Bad Gateway`, `503 Service Unavailable`; neznámý kód → `''`.
    **Z hostitele:** neúspěšné přihlášení s platným tokenem (postup P2–P3 z `tests/E2E-scenare.md`, špatné heslo) →
    první řádek odpovědi `HTTP/1.1 422 Unprocessable Content`; `curl -s -I http://localhost:8080/neexistuje` →
    `HTTP/1.1 404 Not Found`; `/zdravi` dál `HTTP/1.1 200 OK`.
17. **Vnořená pole formuláře (unit `tests/Unit/Http/RequestListTest.php`):** `$_POST = ['model' => [['x']]]` →
    `Request::fromGlobals()->bodyLists['model'] === []` (pole bylo odesláno, ale nemá platnou hodnotu), `input('model') === ''`,
    `inputList('model') === []`; stávající testy (`tags[]`, klíče, smíšené seznamy) beze změny.
    **Z hostitele:** přihlášeně s platným `_csrf` `POST /admin/ai/05` s `-d article=demo -d model%5B%5D%5B%5D=x` → `422`
    „Vyberte model ze seznamu.“, v `ai_calls` nepřibyl řádek (dnes projde jako výchozí model a spustí volání).
18. **Ikona webu:** `templates/layout.php` obsahuje `<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">`;
    `public/assets/favicon.svg` je statický SVG bez skriptů a odkazů ven; `curl -s -o /dev/null -w '%{http_code} %{content_type}'
    http://localhost:8080/assets/favicon.svg` → `200 image/svg+xml`. Playwright: po načtení `/` a `/admin/prihlaseni`
    `browser_network_requests` neobsahuje požadavek na `/favicon.ico` s `404` a `browser_console_messages` (level `error`) je prázdné.
    `/favicon.ico` zadané ručně dál vrací `404` (záměrně, otázka 7).
19. **Časy seedovaných článků (integrační `tests/Integration/Seed/DemoContentSeedTest.php`):** po `db:seed` nad prázdnou DB má
    každý seedovaný článek `created_at = updated_at` = `published_at`, je-li vyplněné a ≤ `2026-09-30 23:59:59`, jinak
    `2026-09-01 08:00:00` (koncepty a `planovany-clanek` z roku 2099). **Dorovnání (otázka 3):** seedovaný článek s jinými
    časy a `created_by IS NULL AND updated_by IS NULL` (nikdo ho v administraci neupravil) dostane stejné hodnoty; článek
    s vyplněným `updated_by` se nezmění. Výsledek seedu hlásí počet dorovnaných řádků (klíč `časy`); druhé spuštění
    hlásí 0 nových článků i 0 dorovnaných časů.
20. **Session cookie `Secure`:** kód se nemění; grep: `SESSION_COOKIE_SECURE` čte jen `config/container.php`. README místo
    dnešní nepravdivé věty („nastav v `.env`“ – kontejner `app` `.env` nevidí a `compose.yaml` proměnnou nepředává) říká, že
    dev běží bez `Secure` záměrně a produkční `compose.prod.yaml` (M9) musí `SESSION_COOKIE_SECURE: "1"` dát do `environment:`
    služby `app`. `docs/plan/STAV.md` má tento bod v „Otevřených úkolech pro M9“.

### E. Tutoriál a dokumentace (`docs/tutorial.html`, `README.md`)
21. **Architektura aplikace** (`#architektura`): bez `.zastupny`; obsahuje Mermaid diagram vrstev (`<pre class="mermaid">`,
    zjednodušený podle `docs/architektura.md` §1), sekvenční diagram toku požadavku (front controller → kontejner → Kernel →
    middleware → controller → use-case → repozitář → šablona), middleware řetěz přesně v pořadí
    `SecurityHeaders → ErrorHandler → Routing → Csrf → AdminAccess` s vysvětlením obou odchylek od obecné zásady
    (routing před CSRF kvůli 404/405, líná session místo vrstvy), pravidlo „závislosti míří dovnitř“, DI kontejner
    s autowiringem, modul `App\Ai` a odkazy na ADR-0001 až ADR-0007. Každá zmíněná třída/soubor existuje (ověřit Globem).
22. **Bezpečnost v kódu** (`#bezpecnost`): bez `.zastupny`; tabulka „opatření → kde v kódu → co brání“ minimálně pro: `e()`
    v šablonách, CSRF (`CsrfMiddleware`, `CsrfToken`, `hash_equals`), session cookie (`NativeSession`: `HttpOnly`,
    `SameSite=Strict`, `use_strict_mode`, `regenerateId`), argon2id a vyrovnání času (`PasswordHasher::verifyDummy`),
    bezpečnostní hlavičky a statická CSP (`SecurityHeadersMiddleware`), PDO bez emulace a jen v `*Repository`,
    přesměrování jen na interní cesty (`Response::redirect`), 404 pro vše neveřejné, bezpečný Markdown (ADR-0005),
    audit log, LLM rizika (LLM01/02/05/06/10 podle kapitoly M6), Docker (`cap_drop`, `no-new-privileges`, porty jen na
    loopback, repo v kontejneru jen ke čtení), hooky jako pojistka, ne hranice. Oddíl **„Vědomě vynecháno ve výukovém
    režimu“**: omezení pokusů o přihlášení, timeouty session, `Secure` cookie a prefix `__Host-` (M9), CSP s nonce (není
    potřeba, aplikace nemá inline skripty), rate limit AI endpointů, kola security review od M2, sandbox Claude Code,
    digesty obrazů, 2FA — každé s jednou větou „co by hrozilo“.
23. **Kapitola M8** (`#m8`, v navigaci za „AI jádro“): stavba podle skillu `tutorial-kapitola` (Jak to funguje s Mermaid,
    kroky, Spusť to, Bezpečnost, Jak to postavili agenti, Vyzkoušej sám, kvíz 3 otázky se skrytými odpověďmi, pojmy).
    Kroky: filtr jako GET formulář bez CSRF, validace filtru → 422, stránkování se zachováním filtru, `LEFT JOIN` bez N+1
    a index `(action, created_at)`, pravidlo časů z ADR-0007 (ukázka: stejný záznam v Admineru v UTC a na stránce
    v pražském čase), opravy z backlogu (stavový řádek, vnořená pole, favicon, seed). Krátký odstavec „`/audit` vs. audit log“.
    Příkazy a výstupy ověřené spuštěním.
24. **Konzistence:** v kapitole M3 se ukázka `HTTP/1.1 422` doplní na `HTTP/1.1 422 Unprocessable Content` a věta
    „…omezení pokusů, timeouty a CSP s nonce v milníku M8“ se opraví na odkaz do „Vědomě vynecháno“; v celém souboru
    nezůstane slib „doplní agent po milníku …“ u hotových kapitol. Globální slovníček je abecedně (české řazení), každý
    pojem právě jednou, doplněné pojmy: ADR, CSP, Časové pásmo a UTC, Stavový řádek (reason phrase), Vrstvy aplikace,
    Audit log (filtr). Každý odkaz v navigaci má cílové `id` a naopak. Komentář v hlavičce souboru odkazuje na kapitolu
    „Nasazení na VPS Debian“ jménem (ne zastaralým číslem 16). Kapitola Nasazení je mezi značkami ZAMČENO beze změny
    (`git diff` v tom rozsahu prázdný). Kapitoly AI 06–10 zůstávají zástupné (M7).
25. **README:** v tabulce adres řádek „Audit log (jen admin, filtr podle akce a data)“ → `http://localhost:8080/admin/audit`;
    oprava věty o `SESSION_COOKIE_SECURE` (AC 20); poznámka k časům (audit log v DB v UTC, na stránce pražský čas, odkaz na ADR-0007).
    `tests/E2E-scenare.md` má v hlavičce jedno pravidlo pro Playwright: `http://web/`, a když jméno `web` nejde přeložit
    (`ERR_NAME_NOT_RESOLVED` – MCP prohlížeč neběží v síti compose), `http://localhost:8080/`; starší oddíly na ně odkazují.

### F. Z hostitele a E2E (`tests/E2E-scenare.md`, oddíl „Audit log a opravy (M8)“)
26. **curl:** `curl -s http://localhost:8080/admin/audit -D - -o /dev/null` → `303` na přihlášení;
    `curl -s -X POST http://localhost:8080/admin/audit -o /dev/null -w '%{http_code}'` → `405`; přihlášeně
    `…/admin/audit?od=2026-13-01` → `422` „Zadejte datum od ve tvaru RRRR-MM-DD.“, `…/admin/audit?strana=999` → `404`;
    žádné tělo neobsahuje `SQLSTATE` ani `Stack trace`.
27. **Playwright:** přihlásit se → rozcestník „Audit log“ → tabulka, první řádek „Přihlášení“, uživatel „Administrátor“,
    čas = aktuální pražský čas (± 2 min); MCP `SELECT created_at FROM audit_log ORDER BY id DESC LIMIT 1` ukáže tentýž
    okamžik v UTC (o 2 h méně v letním čase) – snímek `tests/_artefakty/admin-audit-m8.png`; vybrat „Neúspěšné přihlášení“ +
    dnešní datum od–do → „Filtrovat“ → URL `/admin/audit?akce=auth.login_failed&od=…&do=…`, jen tyto akce; „Další strana“
    (je-li) zachová filtr; „Zrušit filtr“ vrátí vše. Celý tok jde klávesnicí (Tab, šipky v `select`, Enter), při 375 px se
    tabulka posouvá ve svém obalu, `browser_console_messages` (error) prázdné. Informativně MCP `EXPLAIN` dotazu výpisu
    s filtrem akce (`possible_keys` obsahuje `idx_audit_log_action_created_at`) a bez filtru (`idx_audit_log_created_at`
    nebo plný průchod u malé tabulky – ne FAIL).
28. **Regrese:** M5 A7 a M6 I8 z `tests/E2E-scenare.md`, `/zdravi` → `200 {"stav":"ok","db":"ok"}`, administrace článků
    ukazuje u seedovaných článků „Naposledy upraveno“ v pražském čase (např. `1. září 2026 08:00`).

### G. Kvalita
29. `make qa` kód 0; `composer.json`/`composer.lock` beze změny; SQL jen v `*Repository` a seedu; filtr se do SQL skládá jen
    z pevných fragmentů (žádná hodnota ze vstupu v textu dotazu); šablona `admin/audit/index.php` vypisuje vše přes `e()`/`e_attr()`;
    každý `<form method="post">` má `csrf_field` (formulář filtru je `get`); žádné české identifikátory; nové veřejné
    metody mají test.

## Návrh

### 1. Tok požadavku
```
GET /admin/audit?akce=auth.login&od=2026-10-03&do=2026-10-04&strana=2
  SecurityHeaders → ErrorHandler → Routing → Csrf (GET projde) → AdminAccess
  → Admin\AuditLogController::index
      AuthSession::user() null → 303 přihlášení (obrana do hloubky jako v M5/M6)
      page = PageNumber::fromQuery(strana)                         neplatné → PageNotFound (404)
      filter = AuditLogSearch::filter(akce, od, do)                InvalidAuditLogFilter → 422 + formulář
      result = AuditLogSearch::page(filter, page)                  null → PageNotFound (404)
         AuditLogRepository::count(filter)                          1 dotaz
         AuditLogRepository::search(filter, 50, offset)             1 dotaz, LEFT JOIN users
            PdoAuditLogRepository: hranice Praha → UTC, created_at UTC → Praha (ADR-0007)
      odkazy stránek = query (akce, od, do, strana) bez prázdných hodnot
      → templates/admin/audit/index.php
```
- **Vrstvy:** `Http` (controller, šablona) → `Application\Audit\AuditLogSearch` (validace filtru, stránkování) →
  `Domain\Audit` (`AuditLogRepository`, `AuditLogFilter`, `AuditLogRecord`, `AuditAction`) ← `Infrastructure\Persistence\PdoAuditLogRepository`.
  Stejný vzor jako `AdminArticles` (M5).
- **Jen čtení:** jediná trasa `GET`; formulář filtru je `method="get"` (záložka a odkaz s filtrem fungují, CSRF netřeba).
- **Jedno rozhraní repozitáře:** `AuditLogRepository` dostane dvě čtecí metody; samostatné „reader“ rozhraní by mělo jen jedno
  použití (YAGNI). Implementace: PDO + dvojník v testech.
- **Validace filtru v Application** (jako `ArticleInputValidator`): `akce` = `''` nebo hodnota `AuditAction::tryFrom`; datum
  `^\d{4}-\d{2}-\d{2}\z` + `checkdate`, den v zóně `date_default_timezone_get()` (`createFromFormat('!Y-m-d', …)`); `do` se
  převede na „< následující den 00:00“ (`modify('+1 day')` – správně i přes změnu času).
- **SQL** (`PdoAuditLogRepository`): pevné fragmenty `a.action = :action`, `a.created_at >= :from`, `a.created_at < :until`
  spojené `AND`; parametry vázané, `LIMIT :limit OFFSET :offset` jako `PARAM_INT` (emulace vypnutá), každý pojmenovaný
  parametr v dotazu jen jednou. Výpis:
  `SELECT a.id, a.created_at, a.action, a.user_id, u.display_name, a.entity_type, a.entity_id, a.summary, a.ip_address
   FROM audit_log a LEFT JOIN users u ON u.id = a.user_id [WHERE …] ORDER BY a.created_at DESC, a.id DESC LIMIT … OFFSET …`.
  Hydratace s kontrolou tvaru (`\UnexpectedValueException`) jako ostatní repozitáře.
- **Časy (ADR-0007):** zápis auditu beze změny (výchozí hodnota DB = UTC); `ConnectionFactory` po připojení
  `SET time_zone = '+00:00'`; repozitář při čtení převádí. Doména a šablony vidí jen pražský čas.

### 2. Nové a změněné třídy
| Soubor | Typ | Odpovědnost |
|---|---|---|
| `src/Domain/Audit/AuditAction.php` | změna `enum` | + `label(): string` (české popisky z AC 2) |
| `src/Domain/Audit/AuditLogRecord.php` | nový `final readonly class` | `int $id`, `\DateTimeImmutable $createdAt`, `string $action` (surová hodnota z DB), `?int $userId`, `?string $userName`, `?string $entityType`, `?int $entityId`, `string $summary`, `?string $ipAddress`; `actionLabel(): string` (`AuditAction::tryFrom(...)?->label() ?? $action`), `entityLabel(): string` (AC 2, `'—'` bez typu) |
| `src/Domain/Audit/AuditLogFilter.php` | nový `final readonly class` | `?AuditAction $action = null`, `?\DateTimeImmutable $from = null` (včetně), `?\DateTimeImmutable $until = null` (vyjma); konstruktor odmítne `from >= until` (`\InvalidArgumentException`); `isEmpty(): bool` |
| `src/Domain/Audit/AuditLogRepository.php` | změna `interface` | + `count(AuditLogFilter $filter): int`, `search(AuditLogFilter $filter, int $limit, int $offset): list<AuditLogRecord>` |
| `src/Infrastructure/Persistence/PdoAuditLogRepository.php` | změna | implementace dvou metod (§1), docblok s pravidlem ADR-0007; `add()` beze změny |
| `src/Infrastructure/Persistence/ConnectionFactory.php` | změna | po vytvoření PDO `SET time_zone = '+00:00'` (např. `exec` nebo `Pdo\Mysql::ATTR_INIT_COMMAND`; programátor ověří v kontejneru) |
| `src/Application/Audit/AuditLogSearch.php` | nový `final readonly class` | `PAGE_SIZE = 50`; `__construct(AuditLogRepository)`; `filter(string $action, string $from, string $to): AuditLogFilter` (`@throws InvalidAuditLogFilter`); `page(AuditLogFilter, int $page): ?AuditLogPage` |
| `src/Application/Audit/AuditLogPage.php` | nový `final readonly class` | `list<AuditLogRecord> $records`, `int $page`, `int $totalPages`, `int $total`; `hasPrevious()`, `hasNext()` (vědomě bez sjednocení s `ArticlePage`, viz Mimo rozsah) |
| `src/Application/Audit/InvalidAuditLogFilter.php` | nový `final class extends \RuntimeException` | `array<string, string> $errors` (pole → česká hláška), vzor `InvalidArticleInput` |
| `src/Http/Controller/Admin/AuditLogController.php` | nový `final readonly class` | `__construct(TemplateRenderer, AuditLogSearch, AuthSession)`; `index(Request): Response` (AC 1–9); privátní `pageUrl(array $query, int $page): string` (`http_build_query` z `akce`, `od`, `do`, `strana`, prázdné vynechat, strana 1 bez `strana`) |
| `templates/admin/audit/index.php` | nová šablona | formulář filtru, souhrn chyb `role="alert"`, tabulka v `.table-wrapper` s `<caption class="visually-hidden">`, `<time datetime>`, čas `czech_date($t) . ' ' . $t->format('H:i:s')`, stránkování jako `admin/articles/index.php`, odkaz „Zpět do administrace“; žádná logika kromě cyklů a podmínek |
| `config/routes.php` | změna | `$router->get('/admin/audit', [AuditLogController::class, 'index']);` |
| `templates/admin/dashboard.php` | změna | `<li><a href="/admin/audit">Audit log</a></li>` |
| `public/assets/app.css` | změna | `.audit-filter` (pole vedle sebe, na úzkém displeji pod sebou); jinak znovupoužít `.admin-table`, `.pagination` |
| `src/Http/Response.php` | změna | `static reasonPhrase(int $status): string` (AC 16); `send()`: je-li text známý, `header(sprintf('HTTP/1.1 %d %s', …), true, $status)`, jinak `http_response_code($status)`. Pravděpodobná příčina (ověří programátor v kontejneru): PHP-FPM posílá nginxu `Status: 422` bez textu (jeho vnitřní tabulka 422 nezná) a nginx pro 422 vlastní text nemá → `HTTP/1.1 422 `. Z hlavičky `HTTP/1.1 …` FPM převezme text do `Status:` a nginx ho předá. |
| `src/Http/Request.php` | změna | `fromGlobals()`: pole z `$_POST`, které není plochým seznamem řetězců, zapíše do `bodyLists` jako `[]` (dnes se zahodí celé a pole se tváří jako neodeslané); docblok `$bodyLists` doplnit |
| `templates/layout.php`, `public/assets/favicon.svg` | změna / nový | `<link rel="icon" …>` (AC 18); jednoduchá ikona (např. písmeno „R“ v barvě webu), `viewBox`, bez `<script>`, `xlink:href` a externích odkazů |
| `database/seeds/demo_content.php` | změna | `INSERT` článků s `created_at`/`updated_at` (AC 19); dorovnání seedovaných, nikdy neupravených článků jedním `UPDATE … WHERE slug = :slug AND created_by IS NULL AND updated_by IS NULL AND (created_at <> :t1 OR updated_at <> :t2)` (každý parametr jednou); návrat + klíč `časy` |

### 3. Testy (píše tester; názvy anglicky)
- Dvojník `tests/Unit/Support/InMemoryAuditLogRepository.php`: + `public array $records` (`list<AuditLogRecord>`), `count`, `search`
  se sémantikou SQL, počítadla volání (`countCalls`, `searchCalls`) pro AC 7 a 12; `add()`/`byAction()` beze změny (testy M3/M5).
- Unit: `Http/AdminAuditLogTest` (AC 1–10), `Application/Audit/AuditLogSearchTest` (AC 11–12), `Domain/Audit/AuditLogRecordTest`
  + `AuditActionTest` (popisky, `entityLabel`, neznámá akce), `Http/ResponseReasonPhraseTest` (AC 16), rozšířit `Http/RequestListTest`
  (AC 17); regrese `AdminArticlesTest` (rozcestník), `AdminAiTest`.
- Integrační: rozšířit `Persistence/PdoAuditLogRepositoryTest` (AC 13–15; řádky vkládat SQL s explicitním UTC `created_at`),
  `Seed/DemoContentSeedTest` (AC 19).
- `tests/E2E-scenare.md`: hlavička (pravidlo URL pro Playwright, AC 25) a oddíl „Audit log a opravy (M8)“ (AC 16–18, 26–28).

## Dotčené soubory
**Nové:** `src/Domain/Audit/{AuditLogRecord,AuditLogFilter}.php`, `src/Application/Audit/{AuditLogSearch,AuditLogPage,InvalidAuditLogFilter}.php`,
`src/Http/Controller/Admin/AuditLogController.php`, `templates/admin/audit/index.php`, `public/assets/favicon.svg`,
`docs/adr/0007-casy-v-databazi-utc-vs-praha.md` (hotovo v rámci plánu), testy dle §3.

**Změněné:** `src/Domain/Audit/{AuditAction,AuditLogRepository}.php`, `src/Infrastructure/Persistence/{PdoAuditLogRepository,ConnectionFactory}.php`,
`src/Http/{Request,Response}.php`, `config/routes.php`, `templates/{layout,admin/dashboard}.php`, `public/assets/app.css`,
`database/seeds/demo_content.php`, `tests/Unit/Support/InMemoryAuditLogRepository.php`, `tests/E2E-scenare.md`,
`docs/tutorial.html`, `README.md`, `docs/architektura.md` a `docs/plan/STAV.md` (číslování – hotovo v rámci plánu; backlog a M9 – vedoucí).

**Beze změny:** migrace a schéma, `compose.yaml`, `docker/`, `Makefile`, `composer.*`, `.github/`, `config/container.php`
(nové třídy se skládají autowiringem; `PdoAuditLogRepository` dostává jen `\PDO` jako dnes), kapitola „Nasazení na VPS Debian“.
`.claude/agents/tester.md` jen se souhlasem (otázka 10).

## Úkoly pro agenty
Brána 1 (člověk) schvaluje: tento plán, ADR-0007 a otázky 1–11.

| # | Fáze | Agent | Úkol | Výstup | Souběh |
|---|---|---|---|---|---|
| T1 | 1 | `tester` (režim A) | testy z §3 pro AC 1–17 a 19, úprava dvojníka `InMemoryAuditLogRepository`; hlavička a oddíl M8 v `tests/E2E-scenare.md` (AC 16–18, 25–28) | testy; RED ze správného důvodu (chybí třídy/trasa/metody), u souběžně hotového kódu rozlišit „zelené hned“ | ∥ T2, T3 |
| T2 | 1–2 | `programator` | celý §2: nejdřív opravy D (`Response`, `Request`, favicon, `ConnectionFactory`, seed), pak stránka audit logu (Domain → Application → Pdo → controller, šablona, trasa, rozcestník, CSS) | AC 1–19 zelené, `make qa` zelené, výstup curl z AC 16–17 a 26 | ∥ T1, T3; GREEN po T1 |
| T3 | 1 | `technicky-spisovatel` | kapitoly „Architektura aplikace“ a „Bezpečnost v kódu“ (AC 21–22) z kódu, `docs/architektura.md` a ADR; slovníček, navigace, hlavičkový komentář a oprava věty v M3 (AC 24, část bez M8) | diff tutoriálu; seznam ověřených souborů | ∥ T1, T2 (nezávisí na M8 kódu) |
| T4 | 3 | `tester` (režim B) | `make qa`, AC 16–18 a 26–28 (curl, Playwright, MCP dotazy, informativně `EXPLAIN`), AC 29 grepy | PASS/FAIL po kritériích; FAIL vrací T2 | po T2 |
| T5 | 3 | `technicky-spisovatel` | kapitola M8 (AC 23) s ověřenými příkazy a výstupy, ukázka 422 v M3 na nový stavový řádek, README (AC 20, 25) | diff, ověřené příkazy | po T2, ∥ T4 |
| T6 | 3 | vedoucí | `docs/plan/STAV.md`: M8 hotovo, backlog (Mimo rozsah), M9 bod `SESSION_COOKIE_SECURE`; po souhlasu (otázka 10) věta v `.claude/agents/tester.md` o URL pro Playwright | diff | ∥ T4, T5 |
| — | 4 | vedoucí | report → **brána 2** → commity | — | — |

`databazista` se nespouští (schéma se nemění, indexy existují; `EXPLAIN` informativně v T4). `security-reviewer` se v tomto
milníku nespouští (výukový režim, STAV.md); rizika jsou jen vyjmenovaná níže.

Návrh commitů (každý projde `make qa`):
1. `fix(http): stavový řádek s textem důvodu a vnořená pole formuláře jako neplatná` (AC 16–17)
2. `fix(web): ikona webu` (AC 18)
3. `fix(db): zóna spojení UTC a časy seedovaných článků` (AC 13 část, 19)
4. `feat(audit): stránka audit logu s filtrem podle akce a data` (AC 1–15, 26–27)
5. `docs: tutoriál – architektura, bezpečnost, kapitola M8 a slovníček; README` (T3, T5)
6. `docs: plán 007, ADR-0007, architektura a stav projektu` (tento plán, T6)

## Rizika a bezpečnost
- **Dvě konvence času v DB (ADR-0007):** pohled do Admineru/MCP ukazuje `audit_log` v UTC, stránka v pražském čase. Kdo
  bude psát další dotaz nad `audit_log` (např. `/audit` skill, M7 agent), musí převádět. Zmírnění: pravidlo v architektuře,
  docbloku repozitáře, tutoriálu a integrační test hranic dne.
- **Filtr přes změnu času:** den 25. 10. 2026 má 25 h; převod dělá PHP (`modify('+1 day')` v pražské zóně) a pokrývá ho AC 11.
- **XSS přes audit (A03):** `summary` neúspěšného přihlášení obsahuje text od útočníka, `display_name` zadává admin v konzoli.
  Vše přes `e()`; AC 9 hlídá. Odkazy stránkování skládá `http_build_query` jen z validovaných hodnot a escapuje `e_attr()`.
- **SQL injection (A05):** dynamický je jen výběr pevných fragmentů `WHERE`; hodnoty vždy jako parametry.
- **Únik informací:** audit obsahuje IP adresy a zkoušené e-maily; stránka je za `AdminAccessMiddleware` a kontrolou v controlleru.
  IP se ukazuje celá (skill doporučuje zkracovat už při zápisu – mimo rozsah, otázka 5).
- **Výkon:** dva dotazy na stránku, `LIMIT 50`; `COUNT(*)` nad velkou tabulkou bez filtru projde index `created_at`. Audit se
  nikdy nemaže → u produkčního objemu by se hodila retence (backlog).
- **Stavový řádek:** `header('HTTP/1.1 …')` za nginxem s HTTP/2 nemá význam (HTTP/2 text důvodu nezná) – neškodí; klienti text
  podle RFC 9112 ignorují. Pokud by FPM text nepředal, programátor změnu vrátí a v T2 to nahlásí (ne kritické).
- **Seed přepisuje časy:** dorovnání mění jen seedované řádky s `created_by` a `updated_by` `NULL`; článek upravený adminem,
  jehož autor byl mezitím smazán (`ON DELETE SET NULL`), by se dorovnal také – v dev datech přijatelné.
- **`Request` a vnořená pole:** změna platí pro všechny formuláře (pole s neplatnou strukturou je „odeslané a prázdné“); `tags[][]`
  se dál chová jako „žádný štítek“, AC 17 hlídá stávající testy.
- **Favicon:** prohlížeče s `<link rel="icon">` `/favicon.ico` nežádají; JSON odpověď `/zdravi` otevřená v prohlížeči
  `/favicon.ico` vyžádat může (404, kosmetické).
- **Neopravené z backlogu (vědomě):** omezení pokusů o přihlášení (příběh 4 zadání ho požaduje), timeouty session, rate limit AI,
  `Secure` cookie v dev — v tutoriálu jako „Vědomě vynecháno“ (otázka 9).

## Mimo rozsah
- **Spuštění skillu `/audit`** a opravy z něj (samostatné `/feature` po rozhodnutí člověka, otázka 1).
- **M7:** AI příklady 06–10 (vlastní plán, číslo 008 nebo pozdější).
- **Backlog M8b (zapsat do STAV.md):** omezení pokusů o přihlášení (tabulka pokusů, 5 / 15 min na IP + účet), timeout nečinnosti
  a absolutní limit session, filtr auditu podle uživatele a fulltext ve shrnutí, export CSV, retence/úklid `audit_log`, zkracování
  IP při zápisu, audit AI volání (`ai_calls` už metadata má), sjednocení `ArticlePage`/`AuditLogPage` do obecné stránky,
  převod `users.last_login_at` při zobrazení (až se bude zobrazovat), `/favicon.ico` přes nginx (204), M4b a M5b z STAV.md.
- Změny kapitoly „Nasazení na VPS Debian“ a kapitol AI 06–10.

## Otázky pro člověka
1. **`/audit` z milníku M8 = skill pro revizi repozitáře, ne součást tohoto plánu.** Plán 007 dělá jen stránku audit logu
   (příběh 9). Doporučuji **spustit `/audit` až po M7, před M9** (skill sám říká „před releasem“) — po M8 by revidoval
   neúplnou aplikaci a jeho `security-reviewer` jde proti výukovému režimu bez revizí; nálezy pak jako série `/feature`.
2. **Časy podle ADR-0007:** audit zůstává v UTC, převádí se při čtení v repozitáři; spojení se připíchne na UTC; žádná migrace.
   Doporučuji **přijmout** — nejmenší změna bez přepisu dat, audit v UTC je jednoznačný i při změně času; alternativa
   „vše v pražském čase“ by potřebovala datovou migraci auditu.
3. **Seed dorovná časy svých nikdy neupravených článků** ve stávající dev DB (nejen nové řádky). Doporučuji **ano** — jinak
   v administraci zůstanou u 16 ukázkových článků časy posunuté o 2 h; mění se jen řádky bez `created_by`/`updated_by`,
   nic se nemaže a druhé spuštění nic nemění.
4. **Rozsah stránky:** 50 záznamů na stránku, nejnovější nahoře, filtry jen akce + od–do (bez filtru uživatele, fulltextu, exportu).
   Doporučuji **ano** — přesně příběh 9; zbytek je backlog M8b.
5. **IP adresa ve výpisu celá** (jak je uložená). Doporučuji **ano** — vidí ji jen admin, v Dockeru je to stejně adresa brány;
   zkracování při zápisu (skill) je backlog.
6. **Neplatný filtr → 422 s chybou u pole** (ne tiché ignorování). Doporučuji **ano** — stejné chování jako formuláře M5/M6
   a admin hned vidí, proč filtr nefunguje.
7. **Favicon jako SVG + `<link rel="icon">`**, `/favicon.ico` dál 404 (bez změny nginx). Doporučuji **ano** — žádná binární
   ikona v repu ani úkol pro devops; konzole prohlížeče na stránkách aplikace bude čistá. Alternativa: nginx
   `location = /favicon.ico { return 204; }` (devops, jedna řádka).
8. **Text stavového řádku přes `header('HTTP/1.1 422 Unprocessable Content')`** s malou tabulkou kódů v `Response`.
   Doporučuji **ano** — pár řádků, opraví i ukázku v tutoriálu; když se v kontejneru neosvědčí, vrátí se a zapíše do backlogu.
9. **Omezení pokusů o přihlášení, timeouty session a CSP s nonce zůstávají mimo M8** (v tutoriálu „Vědomě vynecháno“,
   v STAV.md backlog M8b), přestože příběh 4 zadání ochranu proti brute force zmiňuje. Doporučuji **ano** (výukový režim,
   malý rozsah); CSP s nonce není potřeba vůbec, dokud aplikace nemá inline skripty. Pokud brute force chceš, je to
   samostatný plán (nová tabulka + migrace, úkol pro `databazista`).
10. **Úprava `.claude/agents/tester.md`** (věta: Playwright `http://web/`, při `ERR_NAME_NOT_RESOLVED` `http://localhost:8080/`).
    Doporučuji **ano, provede vedoucí** — definice agenta dnes tvrdí jen `http://web`, tester to opakovaně obchází; jde o změnu
    `.claude/`, proto se ptám (není to hook).
11. **Číslování:** 007 = M8; M7 a CI dostanou čísla podle pořadí plánování. Doporučuji **M7 = 008, CI = 009** — M7 je obsahově
    na řadě (zadání), CI patří těsně před M9 (nasazení).
