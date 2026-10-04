# Architektura projektu (pro seniorního programátora)

Tento dokument vysvětluje, **jak je aplikace postavená a proč**. Je psaný pro zkušeného vývojáře,
který chce pochopit návrh dřív, než otevře kód. Podrobnější a průběžně udržovaný pohled po milnících
(M1–M8) včetně diagramů je v [`architektura.md`](architektura.md), jednotlivá rozhodnutí v [`adr/`](adr/).
Zde je nahoře shrnutí a pak postupně každá vrstva.

> Stav k 2026-10-04: M1–M6 a M8 jsou hotové. M7 (příklady 06 a 07, streaming a tool use, plán 008, ADR-0008)
> je rozpracovaný: třídy `Example06WritingAssistant`, `Example07AskNewsroom`, `SseParser` a nástroje existují,
> ale trasy `/admin/ai/06` a `/admin/ai/07` zatím nejsou v `config/routes.php` a související testy padají.

## 1. Shrnutí v deseti bodech

1. **Čisté PHP 8.4 bez frameworku.** Vlastní DI kontejner, router, pipeline middlewarů, migrátor a šablony.
   Jediný runtime požadavek na knihovny je nulový (`composer.json` má v `require` jen rozšíření); vše
   z `vendor/` je vývojové (PHPUnit, PHPStan, PHP-CS-Fixer).
2. **Vrstvená architektura se závislostmi dovnitř** (`Domain` ← `Application` ← `Http`/`Console`/`Ai`,
   `Infrastructure` implementuje porty z `Domain`).
3. **Kompoziční kořen je jediné místo, které zná konkrétní třídy** (`config/container.php`); ostatní kód
   dostává závislosti konstruktorem a mluví s rozhraními.
4. **Čtení a zápis mají oddělená rozhraní repozitářů:** veřejné `ArticleRepository` vidí jen publikované,
   `ArticleAdminRepository` všechno a umí zapisovat.
5. **Tři databázové účty s odstupňovanými právy** (DML, DDL, jen SELECT); web nikdy nedrží migrační heslo.
6. **Bezpečnost je vrstva, ne příkaz:** bezpečnostní hlavičky, CSRF, autorizace podle prefixu, escapování
   výstupu, prepared statements; obsah od uživatele i z LLM je vždy nedůvěryhodný.
7. **AI je zasunutá za port `LlmClient`** s dekorátorem pro limit tokenů, cenu a audit volání. Žádné SDK,
   jen cURL; falešný klient umožňuje vývoj i testy bez API klíče a bez sítě.
8. **Čas je explicitní závislost** (`Clock`) a konvence UTC vs. Europe/Prague je zapsaná v ADR-0007.
9. **Vše běží v Dockeru** (včetně MCP serverů pro agenty); na hostiteli není PHP ani Node.
10. **Aplikaci staví tým AI agentů** (Claude Code) podle plánů a ADR; kvalitu hlídá `make qa`
    (lint, PHP-CS-Fixer, PHPStan level max, PHPUnit, `composer audit`).

## 2. Mapa adresářů a vrstev

```
public/index.php        front controller (jediný vstup z webu)
bin/konzole             CLI vstup (migrace, admin:vytvor, db:seed, ai:priklad)
config/                 container.php (kompoziční kořen), routes.php, ai-models.php (ceník)
src/Container/          DI kontejner (autowiring), bez závislosti na doméně
src/Domain/             entity, value objecty, rozhraní repozitářů, Clock, read modely
src/Application/        use-case třídy (Create/Update/DeleteArticle, PublishedArticles, AuditLogSearch…)
src/Infrastructure/     PDO repozitáře, ConnectionFactory, migrátor, seed, session, SystemClock
src/Http/               Kernel, Request/Response, Router, middleware, controllery, šablony (View)
src/Console/            ConsoleApplication a příkazy
src/Ai/                 LlmClient (port), klienti, příklady 01–07, nástroje, prompty, ceník
templates/              PHP šablony (layout, home, search, article, admin/…)
database/               migrations/ a seeds/
docker/, compose.yaml   prostředí; Makefile jako jednotné příkazy
```

Pravidlo závislostí (směr šipek = „zná“):

```
Http, Console, Ai  →  Application  →  Domain  ←  Infrastructure
                                         ↑
                       (Infrastructure implementuje rozhraní z Domain)
```

Výjimky jsou záměrné a malé: čtecí controllery (veřejné stránky) smí volat rozhraní z `Domain`
přímo (např. `SearchController` bere `ArticleRepository`), pokud by use-case třída byla jen prázdný
průchozí obal. Zápisové operace vždy procházejí `Application`.

`App\Container` je technické jádro bez znalosti domény. Kontejner smí volat jen kompoziční kořen
a `Kernel` (dispečer); controllery a služby dostávají závislosti konstruktorem a kontejner neznají
(žádný service locator v aplikačním kódu).

## 3. Životní cyklus HTTP požadavku

```
nginx → PHP-FPM → public/index.php
   → config/container.php   (nový Container při každém požadavku, továrny jsou líné)
   → Kernel::handle(Request::fromGlobals())
        → MiddlewarePipeline
             SecurityHeadersMiddleware   nejvnější: hlavičky i na chybových stránkách
             ErrorHandlerMiddleware      výjimka → HTML 404 / 405 / 500 (detail jen do error_log)
             RoutingMiddleware           Router::match → Request::withRoute (404/405 vznikají tady)
             CsrfMiddleware              POST bez platného tokenu → 403
             AdminAccessMiddleware       prefix /admin bez přihlášení → 303 na přihlášení
          → Kernel::dispatch            container->get(Controller), volání metody, kontrola návratu Response
   → Response::send()
```

Důležité návrhové body:

- **Pořadí middlewarů je záměrné.** Párování trasy je *před* CSRF a autorizací, aby neexistující URL
  dostala 404 (ne 403 nebo přesměrování na login) a aby CSRF a autorizace běžely jen nad existující trasou.
  `ErrorHandlerMiddleware` je hned pod hlavičkami, takže zachytí i výjimky z ostatních vrstev a hlavičky
  dostanou i chybové stránky. Pipeline je klasická „cibule“ skládaná přes `array_reverse` a uzávěry.
- **Request i Response jsou neměnné.** `Request::withRoute`, `Response::withHeaders` vrací kopie.
  Trasa tak není skrytý stav, ale hodnota na požadavku.
- **Router** kompiluje cestu na regex s pojmenovanými skupinami (`/clanek/{slug}`), přesná shoda bez tolerance
  koncového lomítka. Rozlišuje „cesta neexistuje“ (`RouteNotFound` → 404) od „metoda nepovolena“
  (`MethodNotAllowed` → 405 s hlavičkou `Allow`). Tabulka tras je jediný soubor `config/routes.php`.
- **Kontrakt controlleru:** `fn(Request): Response`. Kernel kontroluje návratový typ a existenci metody;
  chyba je `LogicException` (chyba programátora → 500), ne tichá chyba.
- **Chyby doménového charakteru** (`PageNotFound`, `InvalidArticleInput`) jsou výjimky s jasným
  významem; HTTP mapování je na jednom místě (`ErrorHandlerMiddleware`, nebo controller u formulářů → 422).
- **Session je líná služba**, ne middleware: `NativeSession` startuje až při prvním čtení či zápisu,
  takže veřejné stránky nedostanou cookie (a jsou cachovatelné). Cookie `HttpOnly; SameSite=Strict`,
  `Secure` zapíná produkce přes `SESSION_COOKIE_SECURE=1`.
- **CSRF** je synchronizační token v session (`CsrfToken`), porovnání `hash_equals`, rotace po přihlášení.
  Token jde do formulářů přes helper `csrf_field()`; ověřuje se centrálně v middlewaru, ne v controllerech.
- **Autorizace** je jediná role `admin`, chráněný je prefix `/admin` (porovnání po segmentech, takže
  `/adminx` chráněné není). AI controller přesto kontroluje přihlášení znovu (obrana do hloubky).
- **CSP** je statická bez nonce, protože aplikace nemá inline skripty ani styly; formuláře směřují jen
  na `'self'`.
- **Přesměrování** (`Response::redirect`) validuje cíl regexem (jen interní cesta, žádné `//`, žádné řídicí
  znaky), takže nemůže vzniknout open redirect. Formuláře používají PRG (303) a jednorázovou `Flash` zprávu.

### Šablony a výstup
`TemplateRenderer` vykreslí čistou PHP šablonu (`extract` dat) do `layout`. Pravidla:

- Veškerý výstup přes `e()` / `e_attr()`. Jediné výjimky bez `e()` jsou `$content` v `layout.php`
  (už vykreslené HTML šablony) a výstup `MarkdownRenderer` (vlastní renderer, ADR-0005, který escapuje vstup
  sám a povoluje jen úzkou množinu značek).
- Zvýraznění hledaného výrazu (`highlight()`) text nejdřív rozdělí podle shody a každý kousek escapuje
  zvlášť, takže `<mark>` je jediné HTML, které může vzniknout.
- Šablony jsou pro PHPStan slepé místo (proměnné vznikají přes `extract`), proto jsou v `excludePaths`
  a hlídají je testy nad vykresleným HTML.

## 4. Perzistence

### Spojení a role
`ConnectionFactory` vytváří PDO s `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, **`EMULATE_PREPARES=false`**
(skutečné prepared statements), timeoutem spojení a `SET time_zone='+00:00'`. Aplikace používá účet
`redakce_app` (jen DML). Migrace běží pod `redakce_migrace` (DDL) a tento účet kontejner sestaví **líně**,
jen v konzoli, takže web migrační heslo nikdy nedrží. `redakce_cteni` (jen SELECT) je pro MCP server agentů.

Důsledek `EMULATE_PREPARES=false`: jeden pojmenovaný parametr nejde v dotazu použít víckrát. Proto
například `searchPublished` používá tři různé parametry (`q_title`, `q_excerpt`, `q_body`) pro tentýž vzorek.

### Repozitáře
- **SQL jen v `Pdo*Repository`**, nikdy v controlleru ani use-case. Rozhraní jsou v `Domain`, takže testy
  používají repozitáře v paměti (`tests/Unit/Support/InMemory*`) a integrační testy běží nad skutečnou
  databází `redakce_test`.
- **Veřejné čtení vs. administrace** jsou dvě rozhraní. Pravidlo „veřejně jen publikované a ne budoucí“
  (`status='published' AND published_at <= :now`) je na jednom místě v SQL repozitáře (`PUBLISHED_CONDITION`),
  čas dodává `Clock`, ne `NOW()`.
- **Read modely místo entit:** `ArticleSummary` (výpis, rubrika přes `JOIN`), `ArticleDetail` (štítky druhým
  dotazem), `AdminArticleSummary`, `EditableArticle`. Žádné N+1, žádné ORM, žádné líně načítané vztahy.
- **Transakce** obaluje uložení článku a jeho štítků (`PdoArticleAdminRepository`); audit se zapisuje
  až po úspěšném commitu.
- **LIKE vyhledávání** (`searchPublished`) escapuje `%`, `_` i samotný escapovací znak a hledá doslova;
  je to jednoduchý `LIKE '%…%'` nad titulkem, perexem a tělem. Pro tento objem dat stačí, pro velký
  korpus by přišel `FULLTEXT` index nebo vektorové vyhledávání (viz „Rozšiřitelnost“).

### Schéma a migrace
Tabulky: `users`, `categories`, `tags`, `articles`, `article_tags`, `audit_log`, `ai_calls`, `migrations`.
Vše `InnoDB`, `utf8mb4_czech_ci`, cizí klíče s promyšleným `ON DELETE` (rubrika `RESTRICT`, autor `SET NULL`,
štítky `CASCADE`). Názvy v databázi jsou anglicky (ADR-0004).

Migrace jsou PHP soubory `RRRRMMDDHHMM_popis.php` vracející anonymní třídu s `up()` a `down()`
(`Migration`). `Migrator` je objevuje v adresáři, porovnává s tabulkou `migrations` a umí `migrate`,
`rollback(N)` a `status`. Seed (`db:seed`) má obdobné rozhraní, běží jen v `APP_ENV=dev|test`, nic nemaže.

### Čas (ADR-0007)
Sloupce plněné databází (`DEFAULT CURRENT_TIMESTAMP`) jsou v UTC; sloupce plněné aplikací z `Clock`
jsou v Europe/Prague. Převod UTC → Praha dělá výhradně repozitář, který takový čas čte
(dnes `PdoAuditLogRepository`). Je to pragmatický kompromis, který je dokumentovaný, protože je to
klasické místo pro tiché chyby.

## 5. Aplikační vrstva a doména

- **Use-case třídy** jsou malé, s jednou veřejnou operací: `CreateArticle`, `UpdateArticle`, `DeleteArticle`,
  `PublishedArticles` (stránkování), `AuditLogSearch`, `AdminAuthenticator`, `CreateAdmin`.
- **Validace vstupu** je mimo controller: `ArticleInputValidator` vrací české chyby a vyvolá
  `InvalidArticleInput`, controller z toho udělá 422 s předvyplněným formulářem.
- **Slug** je value object s čistými funkcemi (`Slug::fromText`, `Slug::uniqueAmong`); unikátnost se řeší
  jedním dotazem `takenSlugs` a pojistkou je `UNIQUE` v databázi (`SlugAlreadyTaken`).
- **Audit** (`AuditEntry`, `AuditAction`) se zapisuje z use-casů, ne z controllerů, takže ho nelze obejít
  jiným vstupem (např. konzolí).
- **Hesla:** `password_hash` s Argon2id, přihlášení (`AdminAuthenticator::attempt` dostává IP klienta pro omezení pokusů); účet admina vzniká jen příkazem
  `admin:vytvor`, žádná veřejná registrace neexistuje.
- **Typový systém:** `declare(strict_types=1)` všude, třídy `final readonly`, konstruktorová injekce,
  enumy pro stavy (`ArticleStatus`, `Role`, `AiProvider`, `LlmErrorType`), typované konstanty PHP 8.3+.
  PHPStan běží na level max včetně testů.

## 6. DI kontejner

`Container` je ~140 řádků: `set(id, továrna)` pro rozhraní a skalární konfiguraci, jinak **autowiring
přes reflexi konstruktoru** (třídy typované v konstruktoru se vyřeší rekurzivně, parametry s výchozí
hodnotou se vezmou z ní). Instance jsou sdílené (singleton na kontejner), sestavení je líné a hlídá
zacyklení (`Zacyklená závislost: A -> B -> A`). Nelze-li hodnotu určit (skalár bez výchozí hodnoty),
vyhodí `ContainerException`.

Praktické důsledky:
- Controllery, use-case třídy a příklady se do `config/container.php` **nepíší**, stačí je typovat v konstruktoru.
- Do kompozičního kořene patří jen rozhraní → implementace, konfigurace z prostředí a dekorátory.
- Kontejner se v `public/index.php` vytváří při každém požadavku (PHP-FPM je bez sdíleného stavu); líné
  továrny zajišťují, že se platí jen za to, co požadavek skutečně použije.
- Testy staví kontejner přes `TestContainer::create(...)` a podstrčí repozitáře v paměti a falešný klient.

## 7. AI vrstva (`src/Ai`)

Toto je nejzajímavější část z hlediska návrhu, proto podrobněji. Rozhodnutí jsou v ADR-0006
(vlastní klient přes cURL) a ADR-0008 (streaming a nástroje).

### 7.1 Porty a adaptéry

```
Controller / ai:priklad
        │
   ExampleRunner ──► Example01..07 ──► LlmClient (port)
                                          │
                          MeteredLlmClient (dekorátor: limit, cena, log)
                                          │
                 ┌────────────────────────┴──────────────────────┐
         FakeLlmClient (výchozí, bez sítě)            AnthropicClient
                                                           │
                                                   HttpTransport (port)
                                                           │
                                                   CurlHttpTransport (cURL)
```

- **`LlmClient::complete(LlmRequest): LlmResponse`** je jediné, co příklady znají.
  `StreamingLlmClient` ho rozšiřuje o `stream(request, onText)`; stará rozhraní tak zůstala beze změny
  a příklady 01–05 streamování nepotřebují. `BufferedStreamingClient` obalí klienta bez streamování
  (používá se v testech).
- **`HttpTransport`** je nejmenší možné HTTP rozhraní (`post`, `stream`). Díky němu se `AnthropicClient`
  testuje skriptovaným transportem (`ScriptedHttpTransport`) bez sítě, a cURL je izolovaný na jednom místě.
  Chyby 4xx/5xx nejsou výjimka transportu, mapuje je klient.
- **`AiProvider`** (`falesny` | `anthropic`) se vybírá proměnnou prostředí v kompozičním kořeni. Pod
  `LlmClient` se vždy registruje `MeteredLlmClient`, takže limit a log nelze obejít volbou poskytovatele.
- **`FakeLlmClient`** není „mock na jedno použití“: je to plnohodnotná náhrada deterministická podle
  vstupu (generuje odpovědi pro všech sedm příkladů včetně tool use a streamu). Umožňuje demonstrovat
  celou aplikaci a spouštět testy bez klíče; v dev kontejneru čeká 60 ms mezi přírůstky streamu, aby byl
  efekt vidět.

### 7.2 `LlmRequest` / `LlmResponse` jako čisté datové objekty
`LlmRequest` validuje sám sebe v konstruktoru (`maxTokens` 1–16 000, poslední zpráva `user`, jména
nástrojů podle pravidel API) a nenese parametry, které nové modely odmítají (teplota, vynucený nástroj,
viz ADR-0006). `exampleId` a `userId` jsou jen metadata pro log, do API se neposílají. Obsah zprávy
smí být buď řetězec, nebo seznam bloků (`tool_use`, `tool_result`, `thinking`), které se **vracejí modelu
beze změny** (u bloků `thinking` je to podmínka API kvůli `signature`). `LlmResponse` nese text, spotřebu
(`TokenUsage` včetně cache zápisu a čtení), `stopReason`, ID požadavku, počet pokusů a cenu.

### 7.3 `AnthropicClient`
- Posílá `POST /v1/messages` s hlavičkou `anthropic-version: 2023-06-01`; klíč je označený
  `#[\SensitiveParameter]` a `__debugInfo()` ho maskuje.
- **Retry:** opakuje jen přechodné stavy (500, 529, a 429 jen s `Retry-After` do limitu); 429 bez
  `Retry-After` znamená vyčerpaný rozpočet a čekání nepomůže, takže se neopakuje. Plán čekání je parametr
  konstruktoru (`[1000, 2000]` ms, v testech `[0, 0]`).
- **Prompt caching:** `LlmRequest::cacheSystem` přidá `cache_control: ephemeral` na systémový prompt.
- **Structured output** přes `output_config.format` (JSON schéma) plus validace v PHP (viz níže).
- **Chyby** se mapují na `LlmErrorType` (konfigurace, transport, timeout, autentizace, limit, neplatná
  odpověď…) a vyhazují jako `LlmCallFailed`; volající tak nikdy neparsuje HTTP stavy.
- **Streaming:** `AnthropicStreamReader` skládá události z `SseParser` (`message_start`, `content_block_delta`,
  `message_delta`, `message_stop`, `error`). Vrátí-li callback `false`, klient přestane číst a výsledek má
  `stopReason 'aborted'` (přerušení uživatelem není chyba). Spotřeba přerušeného proudu se odhadne
  (znaky / 4), protože API závěrečné číslo nedoručí. Proud bez `message_stop` je `InvalidResponse`.
  Opakování je bezpečné jen před prvním doručeným bajtem, proto se opakuje pouze chybová odpověď před proudem.
  Streaming s nástroji je zatím zakázaný (`LogicException`).

### 7.4 `MeteredLlmClient` (dekorátor)
Pro každé volání (`complete` i `stream` sdílejí `metered()`):

1. ověří, že model je v katalogu (`ModelCatalog` z `config/ai-models.php`: ceny za milion tokenů, cena
   zápisu a čtení cache, příznak `supports_effort`); neznámý model je `LlmCallFailed(Configuration)`,
2. spočítá dnešní spotřebu od půlnoci pražského času a **rezervuje `maxTokens`**; při překročení
   `AI_DENNI_LIMIT_TOKENU` vyhodí `AiBudgetExceeded` ještě před voláním (vstup se neodhaduje, takže jedno
   volání může limit přesáhnout o svůj vstup, je to zdokumentovaný kompromis),
3. změří dobu, zavolá vnitřního klienta, spočítá cenu a zapíše řádek do `ai_calls`,
4. při chybě zapíše řádek se stavem `error` a typem chyby; selhání zápisu logu se jen zaloguje, aby
   nezakrylo původní chybu.

`ai_calls` obsahuje **jen metadata** (příklad, poskytovatel, model, tokeny včetně cache, cena, doba, počet
pokusů, stav, `stop_reason`, `request_id`), nikdy texty promptů ani odpovědí. Souvisí to s minimalizací
dat a s tím, že články mohou obsahovat osobní údaje.

### 7.5 Příklady a opakující se vzory
Příklady implementují `AiExample` (01–05, běží přes `ExampleRunner`) nebo `ExampleDescription`
(06, 07 mají vlastní vstup). `ExampleRunner` je jediný vstup z HTTP i konzole: načte článek
(`demo`, `demo-injection` nebo ID z databáze), zkontroluje délku (cena roste s délkou) a až potom volá
příklad; chyba vstupu tedy nikdy nestojí tokeny.

| Příklad | Vzor |
|---|---|
| 01 Perex | základní volání, `max_tokens`, cena |
| 02 SEO | structured output přes `StructuredCall` |
| 03 Štítky a rubrika | klasifikace levným modelem, `enum` ve schématu, prompt caching |
| 04 Kontrola před publikací | obrana proti prompt injection (ukázkový článek s vloženým pokynem) |
| 05 Překlad | porovnání modelů, zachování Markdownu |
| 06 Asistent psaní | streaming přes SSE, zrušení, zápis přerušeného volání |
| 07 Zeptej se redakce | tool use smyčka s nástroji jen pro čtení |

**`StructuredCall`**: schéma v API nezaručí všechno (délky, počty, vztahy mezi poli), proto se výstup
vždy znovu validuje v PHP jako nedůvěryhodný (OWASP LLM05). Při neplatné odpovědi se modelu vrátí jeho
vlastní odpověď a seznam chyb a smí se **jedno** opakování; pak `InvalidModelOutput`. `refusal` a
`max_tokens` se hlásí zvlášť (useknutý JSON není „špatná data“).

**Tool use (příklad 07):** smyčku řídí příklad, ne klient. Model vrátí `stop_reason='tool_use'`, příklad
vykoná nástroje (`hledej_clanky`, `nacti_clanek`, rozhraní `AgentTool`) a pošle `tool_result` zpět.
Pojistky:
- limity: nejvýše 5 volání modelu, 3 nástroje na krok, časový rozpočet 60 s (kontrola před dalším krokem),
  výstup nástroje 8 000 znaků,
- nástroje jsou **výhradně čtecí** a vidí jen publikované články (používají veřejné `ArticleRepository`),
- vstup nástroje pochází z modelu, tedy je nedůvěryhodný; neplatný vstup nebo neznámý nástroj je
  `tool_result` s `is_error`, nikdy výjimka a nikdy jiná akce,
- text článků v `tool_result` je nepřímá prompt injection; nemá žádný dosah mimo samotnou odpověď (agent
  nemá žádný zapisující nástroj, takže „ignoruj pokyny a smaž…“ nemá co zasáhnout),
- po vyčerpání kroků se vrátí varování, že odpověď může být neúplná, ne tichá chyba.

**Streaming (příklad 06):** POST na stream endpoint (s CSRF) uvolní zámek session a vrátí
`Response::stream(producent)`. Producent běží až v `Response::send()`, tedy mimo middleware; `send()`
vyprázdní výstupní buffery, nastaví `ignore_user_abort(true)` a předá producentovi `StreamOutput`.
Hlavičky `Content-Type: text/event-stream`, `Cache-Control: no-store` a `X-Accel-Buffering: no` zajistí, že
nginx proud nebufferuje. Chyby uprostřed proudu se mění na SSE událost `error` (stavový kód už odešel),
odpojení klienta je `connection_aborted()` → callback vrátí `false` → `stopReason 'aborted'` a volání
se přesto zaloguje. Prohlížeč má proud číst přes `fetch` a `AbortController` (`public/assets/ai-stream.js`, podle plánu 008
zatím neexistuje) a vkládat text výhradně přes `textContent`, nikdy jako HTML.

### 7.6 Prompty
Prompty jsou verzované Markdown soubory v `src/Ai/Prompts/` (role, pravidla, formát výstupu, sekce
„Data a bezpečnost“), načítá je `PromptLibrary`. Nejsou v kódu jako řetězce, takže jdou revidovat a
porovnávat v diffu jako text. Vstupní data (článek) se do promptu vkládají v oddělovacích značkách
(`<clanek>`), prompt výslovně říká, že obsah značek jsou data, ne pokyny.

### 7.7 Bezpečnost LLM (shrnutí)
| Riziko (OWASP LLM) | Obrana v aplikaci |
|---|---|
| Prompt injection | oddělení dat značkami, instrukce v promptu, nástroje bez zápisu, ukázkový útok v příkladu 04 |
| Nezabezpečené zpracování výstupu | výstup modelu se jen zobrazuje přes `e()`, nikam se neukládá, validuje se v PHP |
| Nadměrná agentura | pouze čtecí nástroje, limity kroků a času |
| Neomezená spotřeba | denní limit tokenů, `max_tokens` na každém volání, kontrola délky vstupu, audit v `ai_calls` |
| Únik citlivých dat | do logu jen metadata, klíč jen v `.env` a maskovaný v `__debugInfo` |

## 8. Konzole

`bin/konzole` → `ConsoleApplication` mapuje název příkazu na třídu `Command` (vytváří ji kontejner):
`migrace:spust`, `migrace:vrat [--kroky=N]`, `migrace:stav`, `admin:vytvor`, `db:seed`, `ai:priklad NN`.
Konzole a web sdílejí stejné use-case třídy a `ExampleRunner`, takže chování je jedno a testuje se jednou.

## 9. Prostředí, kvalita a vývojový proces

- **Docker Compose** (projekt `t360`): `web` (nginx, jen čtení, uživatel 101), `app` (PHP-FPM 8.4, cíl `dev`
  s Composerem, UID/GID hostitele kvůli vlastnictví souborů, `cap_drop`), `db` (MariaDB 11.8 s healthcheckem),
  `adminer`; profil `mcp` pro Playwright a MariaDB MCP, které spouští Claude Code přes `compose run`.
  Služby čekají na zdraví závislostí (`depends_on: service_healthy`). Dockerfile je vícestupňový
  (`base` → `dev` | `builder` → `prod`).
- **Kvalita:** `make check` (php -l, PHP-CS-Fixer PER-CS, PHPStan level max), `make test` (PHPUnit 13:
  `Unit` s repozitáři v paměti a `Integration` nad `redakce_test`), `make qa` navíc `composer audit`.
  Integrační sada maže a znovu vytváří tabulky, proto nesmí běžet souběžně dvě nad stejnou databází.
- **Testovací strategie:** HTTP vrstva se testuje přes skutečný `Kernel` s `TestContainer` a repozitáři
  v paměti (rychlé, pokrývá middleware, routing, šablony i CSRF); persistenci pokrývají integrační testy;
  AI vrstva se testuje bez sítě přes `FakeLlmClient` a `ScriptedHttpTransport`, včetně chyb, retry a streamu.
- **Proces:** vývoj řídí tým agentů (`.claude/agents/`) podle `CLAUDE.md` a `AGENTS.md`: plán a ADR →
  testy napřed → implementace → `make qa` + E2E → bezpečnostní revize → dokumentace → commit do `main`
  (Conventional Commits, bez push). Hooky (`.claude/hooks/`) jsou pojistka proti omylům, ne bezpečnostní
  hranice; ta je v allowlistu oprávnění (`.claude/settings.json`) a v Docker izolaci.

## 10. Klíčová rozhodnutí a jejich cena

| Rozhodnutí | Přínos | Cena / riziko |
|---|---|---|
| Bez frameworku (ADR-0001, 0006) | úplná kontrola, nulové runtime závislosti, výuková průhlednost | vlastní kód pro router, DI, migrace; žádný ekosystém, vyšší nároky na disciplínu |
| Vlastní LLM klient přes cURL (ADR-0006) | žádné SDK, plná kontrola nad retry, cenou, streamem | údržba při změnách API; je třeba hlídat nové parametry modelů |
| Kontejner při každém požadavku | žádný sdílený stav mezi požadavky | režie sestavení (zmírněná lazy továrnami; opcache) |
| Dvě rozhraní repozitáře (čtení/admin) | veřejné čtení nemůže omylem vidět koncepty | více rozhraní a duplicitní SQL |
| Metadata AI volání bez textů | soukromí a malé tabulky | horší ladění konkrétní odpovědi (musí se reprodukovat) |
| UTC vs. Praha podle toho, kdo čas plní (ADR-0007) | žádná změna schématu, jasné pravidlo | nutná disciplína a dokumentace; zdroj chyb při nové tabulce |
| `LIKE '%…%'` pro vyhledávání | jednoduché, bez indexu navíc, funguje na všech datech | nemá relevanci ani stemming, lineární sken při velkém objemu |
| Anglické identifikátory, české texty UI a komentáře (ADR-0003) | konzistentní kód, česká doména v UI | občas míchání jazyků u názvů URL (`/clanek`, `/hledani`) |

## 11. Rozšiřitelnost: kam sáhnout

- **Nová stránka:** řádek v `config/routes.php` + controller (autowiring) + šablona; zápis jde přes use-case.
- **Nová tabulka:** migrace `database/migrations/RRRRMMDDHHMM_popis.php`, rozhraní v `Domain`, `Pdo*Repository`,
  registrace rozhraní v `config/container.php`, repozitář v paměti pro testy.
- **Nový AI příklad:** třída v `src/Ai/Examples/`, prompt v `src/Ai/Prompts/`, registrace v `ExampleRegistry`,
  odpověď ve `FakeLlmClient`, dokument v `docs/ai-priklady/`, kapitola v tutoriálu.
- **Nový model:** záznam v `config/ai-models.php` (ceník, `supports_effort`); bez něj ho `MeteredLlmClient` odmítne.
- **Nový nástroj pro agenta:** implementace `AgentTool` (jen čtení, vstup z modelu je nedůvěryhodný) a
  přidání do příkladu; zápisové nástroje by vyžadovaly nové ADR (potvrzení člověkem, audit).
- **Vyhledávání ve velkém:** `FULLTEXT` index nad `title, excerpt, body` (nebo vektorové sloupce MariaDB 11.8
  pro RAG) za stejným rozhraním `ArticleRepository::searchPublished`; controller ani šablona se nemění.
- **Produkce:** `compose.prod.yaml` a CI/CD zatím nejsou (M9); řídí se kontraktem ve skillu `devops-kontrakt`.
