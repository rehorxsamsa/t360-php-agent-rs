# 004 – Veřejná část: titulní stránka se stránkováním, detail článku, ukázková data
Stav: hotovo

- **Milník:** M4 (zúžený rozsah, příběhy 1–2 zadání; příběh 3 → M4b) · **Režim:** výukový
  (viz `docs/plan/STAV.md`) — MVP, bez kola security review
- **Autor:** agent architekt · **Datum:** 2026-10-03
- **Souvisí:** [plán 003](003-prihlaseni-admin.md) (stav po M3), [ADR-0003](../adr/0003-anglicke-identifikatory.md),
  [ADR-0004](../adr/0004-anglicke-nazvy-v-databazi.md), **nový [ADR-0005](../adr/0005-vlastni-markdown-renderer.md)**
  (vlastní Markdown renderer), [architektura](../architektura.md), skilly `php-oop-standardy`,
  `bezpecnost-owasp`, `db-migrace`
- **Číslování:** CI (`ci.yml`), slibované plány 001–003 jako „další volné číslo“, dostane **005 nebo
  pozdější** číslo podle toho, kdy se bude plánovat.
- **Schéma DB se nemění.** Tabulky `articles`, `categories`, `tags`, `article_tags` z M2 stačí. Výpis
  používá existující index `idx_articles_status_published_at (status, published_at)` (InnoDB k němu
  implicitně přidává `id`, takže pokryje i `ORDER BY published_at DESC, id DESC`); detail používá
  `uq_articles_slug`, štítky `PRIMARY (article_id, tag_id)` tabulky `article_tags`. Nová migrace ani
  index nejsou potřeba. FULLTEXT index přijde až s hledáním v M4b.
- **Nová závislost žádná** (`composer.json` beze změny) — Markdown podle ADR-0005, české datum bez `intl`
  (rozšíření není v obrazu, viz `docker/php/Dockerfile`).

## Cíl
Návštěvník na `http://localhost:8080/` vidí 10 nejnovějších publikovaných článků (titulek, datum
česky, rubrika, perex) a listuje stránkami starších článků. Kliknutím otevře článek na
`/clanek/{slug}`, kde je text z Markdownu vykreslený bezpečně, rubrika a štítky. Koncepty,
archivované a naplánované články ani neexistující adresy veřejnost nevidí (404). Vývojář naplní
prázdnou databázi ukázkovými daty jedním příkazem `make seed`.

## Akceptační kritéria
Unit kritéria ověřuje PHPUnit (repozitář článků v paměti, `FixedClock`, `ArraySession`), integrační
PHPUnit nad `redakce_test` (`TestDatabase::reset()` + migrace + seed), HTTP kritéria curl z hostitele
(povolené volby hooku viz plán 003) a Playwright MCP proti `http://web/`.

**Ukázková data (kontrakt pro testy, AC 25):** 3 rubriky, 5 štítků, 16 článků — 12 publikovaných
s `published_at` od `2026-09-01 08:00` po `2026-09-12 08:00` (jeden denně), 2 koncepty
(`rozepsany-koncept`, `druhy-koncept`, `published_at NULL`), 1 archivovaný (`archivni-clanek`,
`2026-08-15`), 1 publikovaný s budoucím datem (`planovany-clanek`, `2099-01-01 08:00`). Pevné slugy:
nejnovější `ukazka-markdownu` (2026-09-12, rubrika „Technologie“, štítky „Bezpečnost“ a „PHP“, text viz
§5), druhý nejnovější `sablony-a-escapovani` (2026-09-11, titulek `Šablony & escapování: <script> se nespustí`),
nejstarší `prvni-clanek` (2026-09-01).

### A. Doména, aplikační služba, Request, české datum (unit)
1. **Given** `Slug::isValid()`, **Then** `true` pro `prvni-clanek`, `a`, `clanek-2026`; `false` pro `''`,
   `Prvni-clanek`, `prvni--clanek`, `-prvni`, `prvni-`, `prvni clanek`, `prvni-clanek ` (mezera na konci),
   `článek`, `a/b` a pro řetězec 221 znaků.
2. **Given** `PublishedArticles` nad repozitářem v paměti s 12 publikovanými články a `FixedClock`
   na `2026-10-03 12:00`, **When** `page(1)`, **Then** `ArticlePage` má 10 článků v pořadí z repozitáře,
   `page = 1`, `totalPages = 2`, `total = 12`, `hasPrevious() === false`, `hasNext() === true`; repozitář
   dostal `limit 10`, `offset 0` a čas z hodin. **When** `page(2)`, **Then** 2 články, `offset 10`,
   `hasPrevious() === true`, `hasNext() === false`. **When** `page(3)`, **Then** `null`.
3. **Given** prázdný repozitář, **When** `page(1)`, **Then** `ArticlePage` s prázdným seznamem,
   `totalPages = 1`, `total = 0` (titulní stránka nesmí skončit 404); **When** `page(2)`, **Then** `null`.
4. **Given** `PublishedArticles`, **When** `findBySlug('Neplatny slug')`, **Then** `null` a repozitář
   **nebyl volán**; **When** platný slug, **Then** vrátí výsledek `findPublishedBySlug(slug, now)`.
5. **Given** `$_GET = ['strana' => '2', 'x' => ['pole']]`, **When** `Request::fromGlobals()`, **Then**
   `queryParameter('strana') === '2'`, `queryParameter('x') === ''`, `queryParameter('chybi') === ''`;
   **And** `withRoute()` i `withRouteParameters()` query zachovají (regrese AC 1 plánu 003).
6. **Given** `czech_date()`, **Then** `2026-01-01` → `1. ledna 2026`, `2026-10-03 09:05` → `3. října 2026`,
   `2026-12-31` → `31. prosince 2026`; test pokryje všech 12 měsíců v 2. pádě (ledna, února, března, dubna,
   května, června, července, srpna, září, října, listopadu, prosince).

### B. Bezpečný Markdown (`tests/Unit/Http/View/MarkdownRendererTest.php`)
7. **Given** `MarkdownRenderer::toHtml()`, **Then** přesně:
   - `"Ahoj **světe** a *vy*"` → `<p>Ahoj <strong>světe</strong> a <em>vy</em></p>`
   - `"## Nadpis"` → `<h2>Nadpis</h2>`; `"# Nadpis"` → `<h2>Nadpis</h2>`; `"### N"` → `<h3>N</h3>`;
     `"###### N"` → `<h4>N</h4>`
   - `"- a\n- b"` → `<ul><li>a</li><li>b</li></ul>`; `"1. a\n2. b"` → `<ol><li>a</li><li>b</li></ol>`
   - `"> citace"` → `<blockquote><p>citace</p></blockquote>`
   - ```` "```\n<b>x</b> **y**\n```" ```` → `<pre><code>&lt;b&gt;x&lt;/b&gt; **y**</code></pre>`
   - `` "kód `<i>` a **`x`**" `` → `<p>kód <code>&lt;i&gt;</code> a <strong><code>x</code></strong></p>`
   - `"odstavec 1\n\nodstavec 2"` → dva elementy `<p>`
   - `""` → `""`
   (mezi bloky smí být `\n`; testy porovnávají po odstranění `\n` mezi značkami).
8. **Given** odkazy, **Then** `[PHP](https://www.php.net/)` → `<a href="https://www.php.net/">PHP</a>`;
   `[o nás](/clanek/o-nas)` → `<a href="/clanek/o-nas">o nás</a>`; `[x](#kapitola-2)` a `[x](mailto:a@b.cz)`
   → odkaz; `[x](https://a.cz/?a=1&b=2)` → `<a href="https://a.cz/?a=1&amp;b=2">x</a>`;
   `[x](https://a.cz/"onmouseover=alert(1))` → **žádné** `<a` (uvozovky allowlist URL nepovoluje).
   **And** `[x](javascript:alert(1))`, `[x](JavaScript:alert(1))`,
   `[x](java\tscript:alert(1))`, `[x](data:text/html;base64,PHN…)`, `[x](vbscript:x)`, `[x](//zlo.cz)`,
   `[x]( javascript:alert(1))` → **žádné** `<a`, ve výstupu zůstane text `x`.
9. **Given** syrové HTML a útoky: `<script>alert(1)</script>`, `<img src=x onerror=alert(1)>`,
   `<a href="javascript:x">y</a>`, `**<b>**`, `[<b>x</b>](https://a.cz)`, `"><svg onload=alert(1)>`,
   `&lt;script&gt;`, neplatné UTF-8 `"\xC3\x28"`, **When** `toHtml()`, **Then** výstup neobsahuje `<script`,
   `<img`, `<svg`, `onerror`, `onload`, `javascript:` uvnitř `href`; **And** vlastnost pro všechny vstupy
   AC 7–9: každá značka ve výstupu odpovídá `~</?(p|h2|h3|h4|ul|ol|li|blockquote|pre|code|strong|em|a)>~`
   nebo `~<a href="[^"<>]*">~` (žádný jiný element ani atribut). `&lt;script&gt;` se vypíše jako
   `&amp;lt;script&amp;gt;` (text, ne entita převedená zpět na značku).
10. **Given** 100 000 znaků `*`, totéž pro `[`, `` ` ``, `**a` opakované a `[a](` opakované, **When**
    `toHtml()`, **Then** každý doběhne do 1 s bez výjimky a bez varování (ReDoS).

### C. HTTP přes Kernel (`tests/Unit/Http/PublicPagesTest.php`; repozitář článků v paměti)
11. **Given** repozitář s 12 publikovanými články, **When** `GET /`, **Then** `200`, `<h1>Nejnovější články</h1>`,
    přesně 10 elementů `<article`, každý s `<h2><a href="/clanek/{slug}">titulek</a></h2>`,
    `<time datetime="2026-09-12T08:00:00+02:00">12. září 2026</time>` (ISO 8601 v atributu), názvem rubriky
    a perexem; `<nav aria-label="Stránkování">` s odkazem `<a href="/?strana=2" rel="next">Starší články</a>`,
    textem „Strana 1 z 2“ a **bez** odkazu „Novější články“; odpověď **nemá** `Set-Cookie`
    (regrese AC 24 plánu 003).
12. **When** `GET /?strana=2`, **Then** `200`, 2 články, odkaz `<a href="/" rel="prev">Novější články</a>`
    (strana 1 je kanonicky `/`, ne `/?strana=1`), „Strana 2 z 2“, bez „Starší články“, `<title>` obsahuje
    „Strana 2“. **When** `GET /?strana=1`, **Then** `200` jako `GET /`.
13. **When** `GET /?strana=3`, `/?strana=0`, `/?strana=-1`, `/?strana=abc`, `/?strana=1.5`, `/?strana=01`,
    `/?strana=9999999`, **Then** `404` se stránkou „Stránka nenalezena“ (ne `500`).
14. **Given** prázdný repozitář, **When** `GET /`, **Then** `200` a text „Zatím tu nejsou žádné publikované
    články.“ bez navigace stránkování.
15. **Given** článek s titulkem `Šablony & escapování: <script> se nespustí` a perexem `<b>perex</b>`,
    **When** `GET /`, **Then** tělo obsahuje `Šablony &amp; escapování: &lt;script&gt; se nespustí`
    a `&lt;b&gt;perex&lt;/b&gt;`, nikdy `<script>` ani `<b>perex`.
16. **Given** publikovaný článek `ukazka-markdownu`, **When** `GET /clanek/ukazka-markdownu`, **Then** `200`,
    `<article>` s `<h1>` titulku, `<time datetime="…">12. září 2026</time>`, „Rubrika: Technologie“, perex,
    `<div class="article-body">` s HTML z `MarkdownRenderer` (např. `<h2>`, `<strong>`, `<pre><code>`),
    seznam štítků `<ul class="tag-list">` s položkami „Bezpečnost“, „PHP“ (české řazení), odkaz
    „Zpět na titulní stránku“; `<title>` začíná titulkem článku; **bez** `Set-Cookie`.
17. **When** `GET /clanek/neexistuje`, `/clanek/rozepsany-koncept` (koncept), `/clanek/archivni-clanek`
    (archiv), `/clanek/planovany-clanek` (budoucí datum), `/clanek/Ukazka-Markdownu`,
    `/clanek/ukazka-markdownu%20`, `/clanek/%C4%8Dl%C3%A1nek`, `/clanek/`, `/clanek/a/b`, **Then** vždy `404`
    se stejnou stránkou „Stránka nenalezena“ (stejné tělo jako `GET /neexistuje`, nelze rozlišit
    koncept od neexistujícího článku). **When** `POST /clanek/ukazka-markdownu`, **Then** `405` + `Allow: GET`.
18. **Given** detail, **Then** tělo text článku obsahuje jen výstup `MarkdownRenderer` — článek s textem
    `<script>alert(1)</script>` vykreslí `&lt;script&gt;alert(1)&lt;/script&gt;`.

### D. Persistence (`tests/Integration/Persistence/PdoArticleRepositoryTest.php`, seed jako fixture)
19. **Given** `redakce_test` po migracích a seedu, `now = 2026-10-03 12:00`, **When**
    `latestPublished(now, 10, 0)`, **Then** 10 `ArticleSummary` seřazených od `ukazka-markdownu`
    (2026-09-12) sestupně, každý s `categoryName`; `latestPublished(now, 10, 10)` → 2 (poslední
    `prvni-clanek`); `countPublished(now) === 12`. Koncepty, archiv ani `planovany-clanek` se neobjeví;
    s `now = 2099-01-02` je `countPublished === 13`.
20. **When** `findPublishedBySlug('ukazka-markdownu', now)`, **Then** `ArticleDetail` s titulkem, perexem,
    textem (Markdown, nevykreslený), `publishedAt` = `2026-09-12 08:00` v `Europe/Prague`, `categoryName =
    'Technologie'`, `tagNames = ['Bezpečnost', 'PHP']`; pro `rozepsany-koncept`, `archivni-clanek`,
    `planovany-clanek`, `neexistuje` → `null`.
21. **Bez N+1:** **Given** stejné spojení, počet příkazů `SELECT` měřený přes
    `SHOW SESSION STATUS LIKE 'Com_select'` (rozdíl před/po, očištěný o režii samotného měření),
    **Then** `latestPublished(now, 10, 0)` = **1** dotaz (rubrika přes `JOIN`), `countPublished` = 1,
    `findPublishedBySlug` ≤ **2** (článek + štítky) — nezávisle na počtu článků a štítků.

### E. Ukázková data a příkaz `db:seed` (`tests/Integration/Seed/DemoContentSeedTest.php`, `tests/Unit/Console/SeedCommandTest.php`)
22. **Given** `redakce_test` po migracích, **When** seed `database/seeds/demo_content.php` poběží dvakrát,
    **Then** v DB je přesně 3 rubriky, 5 štítků, 16 článků (stavy dle kontraktu výše), žádné duplicity
    v `article_tags`; první běh vrátí `['rubriky' => 3, 'štítky' => 5, 'články' => 16]`, druhý samé nuly
    (doplňuje jen chybějící podle slugu, **nic nemaže ani nepřepisuje**). Rubrika řazená
    `ORDER BY name` vrací pořadí podle `utf8mb4_czech_ci` (ověří, že data jsou s diakritikou).
23. **Given** `ConsoleApplication` z `config/container.php`, **Then** `db:seed` je v seznamu příkazů.
    **Given** `SeedCommand` s `appEnv = 'prod'`, **When** `run`, **Then** kód `1`, chyba „Ukázková data lze
    nahrát jen ve vývojovém nebo testovacím prostředí (APP_ENV=dev|test).“ a **žádné** připojení k DB.
    **When** neznámý argument, **Then** kód `1` a nápověda.
24. **Given** dev DB po `make migrate`, **When** `make seed` (= `docker compose exec -T app php bin/konzole db:seed`),
    **Then** kód `0` a „Nově vloženo – rubriky: 3, štítky: 5, články: 16.“; **When** znovu, **Then** kód `0`
    a „Ukázková data už jsou v databázi, nic nového se nevložilo.“; MCP dotaz
    `SELECT status, COUNT(*) FROM articles GROUP BY status` → `draft 2`, `published 13`, `archived 1`.

### F. HTTP z hostitele a E2E (`tests/E2E-scenare.md`, nový oddíl „Veřejná část (M4)“)
25. **Given** `make up`, `make migrate`, `make seed`, **When** `curl -s http://localhost:8080/ -D -`, **Then** `200`,
    hlavičky z AC 3 plánu 003, **bez** `Set-Cookie`, v těle odkaz `/clanek/ukazka-markdownu` a `/?strana=2`;
    `curl -s 'http://localhost:8080/?strana=2' -o /dev/null -w '%{http_code}'` → `200`, `?strana=3` → `404`.
26. **When** `curl -s http://localhost:8080/clanek/ukazka-markdownu -o /dev/null -w '%{http_code}'` → `200`;
    `/clanek/rozepsany-koncept`, `/clanek/archivni-clanek`, `/clanek/planovany-clanek`, `/clanek/neexistuje`
    → `404`; tělo detailu `ukazka-markdownu` obsahuje `&lt;script&gt;` a neobsahuje `<script` ani `javascript:`.
27. **Given** Playwright, **When** `http://web/` → snímek `tests/_artefakty/titulni-m4.png` (10 článků,
    stránkování) → klik „Starší články“ → 2 články → klik „Novější články“ → klik na titulek „Ukázka Markdownu“
    → snímek `tests/_artefakty/clanek-m4.png`; **Then** detail ukazuje vykreslený nadpis, seznam, blok kódu
    a escapovaný `<script>` jako text; `browser_console_messages` (level `error`) prázdné (žádná chyba CSP);
    navigace jde projít klávesnicí (`Tab` z odkazu „Přejít na obsah“ na první titulek článku).
28. **Výkon (orientačně):** `curl -s http://localhost:8080/ -o /dev/null -w '%{time_total}'` po zahřátí < 0,1 s.
29. **Index:** `EXPLAIN` dotazu z `latestPublished` (MCP, dev DB po seedu) má v `possible_keys`
    `idx_articles_status_published_at`; výsledek se zapíše do reportu (u 16 řádků smí optimalizátor zvolit
    plný průchod — informativní, ne FAIL).
30. **Regrese:** `/zdravi` → `200 {"stav":"ok","db":"ok"}`, `POST /zdravi` → `405`, `/admin` → `303`
    na přihlášení, přihlášení admina (scénář P3/P4 plánu 003) funguje.

### G. Kvalita
31. **Given** běžící prostředí, **When** `make qa`, **Then** kód 0; **And** grep: SQL jen v `*Repository`,
    `database/migrations/` a `database/seeds/`; `$_GET` jen v `src/Http/Request.php`; v šablonách jediné
    výpisy bez `e()` jsou `$content` v `layout.php` a `$bodyHtml` v `article.php` (s komentářem
    `bezpecne: sanitizovano`); žádné české znaky v identifikátorech.

## Návrh

### 1. Tok požadavku
```
GET /?strana=2
  SecurityHeaders → ErrorHandler → Routing → Csrf (GET projde) → AdminAccess (není /admin)
  → HomeController::index
      strana = Request::queryParameter('strana')  ('' → 1; jinak ^[1-9][0-9]{0,5}$, jinak PageNotFound)
      PublishedArticles::page(N)            ← Clock::now()
         ArticleRepository::countPublished(now)            1× SELECT COUNT(*)
         ArticleRepository::latestPublished(now, 10, off)  1× SELECT … JOIN categories
      null → PageNotFound → ErrorHandler → 404 „Stránka nenalezena“
      TemplateRenderer::render('home', [page, title])

GET /clanek/{slug}
  → ArticleController::show
      PublishedArticles::findBySlug(slug)   (Slug::isValid jinak null bez dotazu)
         ArticleRepository::findPublishedBySlug(slug, now) 1× SELECT … JOIN categories + 1× SELECT štítků
      null → PageNotFound → 404
      MarkdownRenderer::toHtml(body) → TemplateRenderer::render('article', [article, bodyHtml, title])
```
- **Pravidlo „veřejně jen publikované“ je v SQL repozitáře** (metody mají v názvu `Published`), ne v šabloně:
  `a.status = 'published' AND a.published_at IS NOT NULL AND a.published_at <= :now`. Archiv i koncept jsou
  pro veřejnost 404 (**otázka 1**), budoucí datum se skrývá (**otázka 2**).
- **Čas z `Clock`, ne z `NOW()` v SQL:** PHP běží v `Europe/Prague` (`docker/php/conf.d/app.ini`), MariaDB
  v UTC; `published_at` je `DATETIME` bez zóny zapisovaný z PHP, takže `NOW()` by se lišil o 1–2 h.
  `:now` = `$clock->now()->format('Y-m-d H:i:s.u')`.
- **Slug se validuje před dotazem** (`Slug::isValid`): kolace `utf8mb4_czech_ci` je necitlivá na velikost
  písmen a `PAD SPACE` ignoruje mezery na konci — bez validace by `/clanek/Ukazka-Markdownu` i `…%20`
  našly článek (duplicitní URL).
- **404 z controlleru:** nová výjimka `App\Http\PageNotFound`; `ErrorHandlerMiddleware` ji chytá spolu
  s `RouteNotFound` (stejná stránka i tělo).
- **Bez N+1:** výpis = 1 dotaz s `JOIN categories`, počet = 1 dotaz, detail = 2 dotazy. Štítky se ve výpisu
  nezobrazují (**otázka 7**); až by byly potřeba (M4b), načtou se **jedním** dotazem `WHERE article_id IN (…)`.
- **Bez nových abstrakcí navíc:** `MarkdownRenderer` a `PublishedArticles` jsou třídy bez rozhraní (jedna
  implementace). Rozhraní má jen `ArticleRepository` (PDO + paměť v testech) a `Clock` (systém + pevné v testech).

### 2. Nové a změněné třídy
| Soubor | Typ | Odpovědnost |
|---|---|---|
| `src/Domain/Time/Clock.php` | `interface` | `now(): \DateTimeImmutable` |
| `src/Infrastructure/Time/SystemClock.php` | `final readonly class implements Clock` | `new \DateTimeImmutable()` (zóna z `date.timezone`) |
| `src/Domain/Article/ArticleStatus.php` | `enum ArticleStatus: string` | `Draft = 'draft'`, `Published = 'published'`, `Archived = 'archived'` (repozitář z něj bere hodnotu do SQL; M5 použije ve formuláři) |
| `src/Domain/Article/Slug.php` | `final class` | `public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/'`, `MAX_LENGTH = 220` (= `articles.slug`), `static isValid(string): bool`. M5 doplní generování z titulku. |
| `src/Domain/Article/ArticleSummary.php` | `final readonly class` | `string $title`, `string $slug`, `string $excerpt`, `\DateTimeImmutable $publishedAt`, `string $categoryName` |
| `src/Domain/Article/ArticleDetail.php` | `final readonly class` | totéž + `string $body` (Markdown), `list<string> $tagNames` |
| `src/Domain/Article/ArticleRepository.php` | `interface` | `latestPublished(\DateTimeImmutable $now, int $limit, int $offset): list<ArticleSummary>`, `countPublished(\DateTimeImmutable $now): int`, `findPublishedBySlug(string $slug, \DateTimeImmutable $now): ?ArticleDetail`. M5 přidá zápis a čtení konceptů. |
| `src/Infrastructure/Persistence/PdoArticleRepository.php` | `final readonly class implements ArticleRepository` | `__construct(\PDO)`, prepared statements, `ORDER BY a.published_at DESC, a.id DESC`, `LIMIT :limit OFFSET :offset` (vázané jako `PDO::PARAM_INT`; `EMULATE_PREPARES` je vypnuté), štítky `ORDER BY t.name`; hydratace s kontrolou tvaru řádku jako `PdoUserRepository` (`\UnexpectedValueException`) |
| `src/Application/Article/ArticlePage.php` | `final readonly class` | `list<ArticleSummary> $articles`, `int $page`, `int $totalPages`, `int $total`; `hasPrevious(): bool`, `hasNext(): bool` |
| `src/Application/Article/PublishedArticles.php` | `final readonly class` | `__construct(ArticleRepository, Clock)`; `const int PAGE_SIZE = 10`; `page(int $page): ?ArticlePage` (`totalPages = max(1, ceil(total / 10))`, `page < 1` nebo `> totalPages` → `null`, jinak `latestPublished(now, 10, (page-1)*10)`); `findBySlug(string $slug): ?ArticleDetail` (neplatný slug → `null` bez dotazu) |
| `src/Http/PageNotFound.php` | `final class extends \RuntimeException` | controller → 404 |
| `src/Http/Middleware/ErrorHandlerMiddleware.php` | změna | `catch (RouteNotFound \| PageNotFound)` → stejná 404 |
| `src/Http/Request.php` | změna | + `array<string, string> $query = []` (poslední parametr konstruktoru; jen řetězcové hodnoty z `$_GET`), `queryParameter(string $name): string` (chybí → `''`); `withRoute`/`withRouteParameters` query přenášejí |
| `src/Http/View/MarkdownRenderer.php` | `final readonly class` | `toHtml(string $markdown): string` podle §4 a ADR-0005 |
| `src/Http/View/helpers.php` | změna | + `czech_date(\DateTimeInterface $date): string` → `j. <měsíc v 2. pádě> Y` (pole 12 názvů, bez `intl`) |
| `src/Http/Controller/HomeController.php` | změna | `__construct(TemplateRenderer, PublishedArticles)`; `index(Request)` — parsování `strana`, `PageNotFound` při neplatné/mimo rozsah, šablona `home` (`title` = „Úvod“, od strany 2 „Strana N“) |
| `src/Http/Controller/ArticleController.php` | nový | `__construct(TemplateRenderer, PublishedArticles, MarkdownRenderer)`; `show(Request)` — `routeParameter('slug')` → `findBySlug` → `PageNotFound` / šablona `article` |
| `src/Infrastructure/Seed/Seed.php` | `interface` | `run(\PDO $pdo): array<string, int>` — kontrakt souboru seedu (obdoba `Migration`); vrací počty nově vložených řádků s českými popisky (`'rubriky' => 3, …`) |
| `src/Console/Command/SeedCommand.php` | `final readonly class implements Command` | `db:seed` (**otázka 3**); `__construct(ConnectionFactory $connections, string $seedFile, string $appEnv)`; `appEnv` mimo `dev`/`test` → kód 1 **před** připojením; argumenty nepřijímá; `require` souboru → musí vrátit `Seed`, jinak chyba; běh v transakci (`beginTransaction`/`commit`, při výjimce `rollBack`); výpis dle AC 24 |

### 3. Konfigurace, šablony, styly
- `config/container.php`: `Clock` → `SystemClock`, `ArticleRepository` → `new PdoArticleRepository($c->get(\PDO::class))`,
  `SeedCommand` → `new SeedCommand(new ConnectionFactory($c->get(DatabaseConfig::class)), $root . '/database/seeds/demo_content.php', (string) getenv('APP_ENV'))`,
  `ConsoleApplication` + `'db:seed' => SeedCommand::class`. Seed běží jako `redakce_app` (stačí DML).
- `config/routes.php`: `$router->get('/clanek/{slug}', [ArticleController::class, 'show']);`
- `templates/home.php` — `<h1>Nejnovější články</h1>`; každý článek `<article class="article-summary">`
  s `<h2><a href="/clanek/<?= e_attr($a->slug) ?>">…</a></h2>`, `<p class="article-meta"><time datetime="<?= e_attr($a->publishedAt->format(DATE_ATOM)) ?>"><?= e(czech_date($a->publishedAt)) ?></time> · <?= e($a->categoryName) ?></p>`,
  perex v `<p>`; prázdný stav; `<nav aria-label="Stránkování" class="pagination">` s `rel="prev"` / `rel="next"`
  a `<span aria-current="page">Strana N z M</span>`.
- `templates/article.php` — `<article>` → `<header>` (`<h1>`, meta s `<time>` a „Rubrika: …“), perex
  `<p class="article-excerpt">`, `<div class="article-body"><?= $bodyHtml /* {# bezpecne: sanitizovano #} */ ?></div>`
  (výjimka podle `.claude/rules/sablony.md`), `<footer>` se štítky `<h2 class="visually-hidden">Štítky</h2><ul class="tag-list">`
  (jen když nějaké jsou), odkaz zpět. Rubrika a štítky jsou **text, ne odkazy** (trasy `/rubrika`, `/stitek` jsou M4b).
- `templates/layout.php` — odkaz „Přejít na obsah“ (`<a class="skip-link" href="#obsah">`) a `<main id="obsah">`.
- `public/assets/app.css` — výpis, meta, stránkování, `pre` s `overflow-x: auto`, `blockquote`, štítky, `.skip-link`
  (viditelný při `:focus`), `.visually-hidden`, `:focus-visible` obrys; kontrast AA (stávající barvy splňují).
- Názvy proměnných šablon anglicky (`$page`, `$article`, `$bodyHtml`); URL a texty česky (`/clanek/…`, `?strana=`).
- `Makefile` — cíl `seed: ## Nahraje ukázková data (jen dev)` = `$(COMPOSE) exec -T app php bin/konzole db:seed`, doplnit do `.PHONY`.

### 4. Markdown renderer (shrnutí ADR-0005, závazné pro implementaci)
1. Vstup: `mb_scrub($markdown, 'UTF-8')`, `\r\n`/`\r` → `\n`, rozdělit na řádky.
2. **Bloky** (řádkově, bez rekurze): blok kódu ```` ``` ```` (do uzavírací ```` ``` ```` nebo konce vstupu; slovo za
   ```` ``` ```` se ignoruje; obsah jen `e()`, žádné inline zpracování) → nadpis `^(#{1,6})[ \t]+(.+)$`
   (koncové `#` se ořežou; úroveň 1–2 → `h2`, 3 → `h3`, 4–6 → `h4`) → citace (po sobě jdoucí `^>[ ]?`, obsah
   jako jeden odstavec) → odrážky (`^[-*+][ \t]+`) → číslovaný seznam (`^\d{1,9}[.)][ \t]+`) → jinak odstavec
   (po sobě jdoucí neprázdné řádky spojené `\n`). Prázdný řádek ukončuje blok.
3. **Inline** na textu bloku: nejdřív vyjmout vložený kód `` `…` `` (obsah `e()`, zástupný token), pak escapovat
   zbytek `e()`, pak odkazy `[text](url)`, pak `**…**` → `strong`, pak `*…*` → `em` (nehladové `[^*]+`,
   bez vnořených kvantifikátorů), nakonec vrátit tokeny kódu. Zástupné tokeny nesmí jít podvrhnout ze vstupu
   (např. znak ze soukromé oblasti Unicode, který se ze vstupu předem odstraní).
4. **URL odkazu:** zachycený (už escapovaný) text se dekóduje `html_entity_decode(…, ENT_QUOTES | ENT_HTML5, 'UTF-8')`,
   `trim`, a projde allowlistem: `~^(?:https?://|mailto:)[^\s<>"'\x00-\x1f\x7f]+\z~i`,
   `~^/(?![/\\\\])[^\s<>"'\x00-\x1f\x7f]*\z~` nebo `~^#[A-Za-z0-9_-]*\z~`. Prošlá → `<a href="` . `e_attr($url)` . `">text</a>`,
   neprošlá → jen text. Žádné `target`, `title`, `rel`.
5. Selhání `preg_*` (`null`) → `'<p>' . e($markdown) . '</p>'`.

### 5. Ukázková data (`database/seeds/demo_content.php`, píše `databazista`)
- Soubor `return new class implements Seed { public function run(\PDO $pdo): array { … } };` — SQL jen
  prepared statements; pro každou rubriku/štítek/článek: `SELECT id … WHERE slug = :slug`, chybí-li → `INSERT`.
  Štítky článku: vložit vazbu jen když neexistuje. Nic nemaže, nic nepřepisuje.
- Rubriky: „Zprávy“ (`zpravy`), „Technologie“ (`technologie`), „Věda a výzkum“ (`veda-a-vyzkum`).
  Štítky: „PHP“, „Docker“, „Bezpečnost“, „Umělá inteligence“, „Přístupnost“ (slugy bez diakritiky).
- 16 článků podle kontraktu nad AC: česky, perex 1–2 věty, text v Markdownu 2–6 odstavců, `created_by`/`updated_by` `NULL`
  (admin nemusí existovat). Žádné osobní údaje ani hesla (`.claude/rules/migrace.md`).
- `ukazka-markdownu` („Ukázka Markdownu“) předvádí celou podmnožinu z §4: `## Podnadpis`, odrážky, číslovaný seznam,
  citaci, blok kódu PHP, vložený kód, tučné, kurzívu, odkaz `https://www.php.net/` a dva „útoky“, které se musí
  vykreslit jako text: `<script>alert("xss")</script>` a `[nebezpečný odkaz](javascript:alert(1))`. Slouží zároveň
  jako výukový příklad v tutoriálu.

### 6. Testy (píše tester; názvy anglicky)
- Dvojníci `tests/Unit/Support/InMemoryArticleRepository.php` (nastavitelné souhrny, počet a detaily podle slugu;
  zaznamenává `limit`, `offset`, `now` a počet volání) a `FixedClock.php`.
- **`TestContainer` a `KernelTest` musí nahradit `ArticleRepository` dvojníkem** — jinak `GET /` v unit sadě
  sáhne do `redakce_test` (po `TestDatabase::reset()` bez tabulek) a skončí `500`.
- Unit: `Domain/Article/SlugTest`, `Application/Article/PublishedArticlesTest`, `Http/RequestQueryTest`,
  `Http/View/CzechDateTest`, `Http/View/MarkdownRendererTest`, `Http/PublicPagesTest` (Kernel z `config/container.php`
  s náhradami), `Console/SeedCommandTest`, úprava `Http/Middleware/ErrorHandlerMiddlewareTest` (+ `PageNotFound`).
- Integrační: `Persistence/PdoArticleRepositoryTest`, `Seed/DemoContentSeedTest` (seed se načte `require`
  a spustí nad PDO z `TestDatabase::reset()`).
- `tests/E2E-scenare.md`: oddíl „Veřejná část (M4)“ (AC 24–30), screenshoty `titulni-m4.png`, `clanek-m4.png`.
  Scénář Z7 (M2, text „Články přibudou v dalším milníku.“) označit jako nahrazený.

## Dotčené soubory
**Nové:** `src/Domain/Time/Clock.php`, `src/Infrastructure/Time/SystemClock.php`,
`src/Domain/Article/{ArticleStatus,Slug,ArticleSummary,ArticleDetail,ArticleRepository}.php`,
`src/Infrastructure/Persistence/PdoArticleRepository.php`, `src/Application/Article/{ArticlePage,PublishedArticles}.php`,
`src/Http/PageNotFound.php`, `src/Http/View/MarkdownRenderer.php`, `src/Http/Controller/ArticleController.php`,
`src/Infrastructure/Seed/Seed.php`, `src/Console/Command/SeedCommand.php`, `database/seeds/demo_content.php`,
`templates/article.php`, testy dle §6, `docs/adr/0005-vlastni-markdown-renderer.md` (hotovo).

**Změněné:** `src/Http/{Request,Controller/HomeController,Middleware/ErrorHandlerMiddleware}.php`,
`src/Http/View/helpers.php`, `config/{container,routes}.php`, `templates/{home,layout}.php`, `public/assets/app.css`,
`Makefile` (cíl `seed`), `tests/Unit/Support/TestContainer.php`, `tests/Unit/Http/KernelTest.php`,
`tests/Unit/Http/Middleware/ErrorHandlerMiddlewareTest.php`, `tests/E2E-scenare.md`, `docs/architektura.md` (hotovo),
`docs/plan/STAV.md` (backlog M4b), `docs/tutorial.html` + `README.md` (kapitola M4, `make seed`).

**Beze změny:** schéma DB a migrace, `compose.yaml`, `composer.json`/`composer.lock`, `.claude/` (kromě volitelné otázky 8).

## Úkoly pro agenty
Brána 1 (člověk) schvaluje: tento plán, ADR-0005 a otázky 1–8.

| # | Fáze | Agent | Úkol | Výstup | Souběh |
|---|---|---|---|---|---|
| T1 | 1 | `tester` (režim A) | testy z §6 pro AC 1–23 + dvojníci; úprava `TestContainer`/`KernelTest`; E2E oddíl AC 24–30 | soubory testů; doložit RED ze správného důvodu (chybí třídy/metody/trasy) | ∥ T2a, T3, T4 |
| T2a | 1 | `programator` | vrstva bez HTTP: `Clock`/`SystemClock`, `Domain/Article/*`, `PdoArticleRepository`, `PublishedArticles`/`ArticlePage`, rozhraní `Seed`, `MarkdownRenderer`, `czech_date` (signatury dané §2 a §4, nečeká na testy) | kód; `make check` zelené | ∥ T1, T3, T4 |
| T3 | 1 | `databazista` | `database/seeds/demo_content.php` podle §5 a kontraktu nad AC (rozhraní `Seed` podle §2; `php -l`, plný `make check` až po T2a) | soubor seedu | ∥ T1, T2a, T4 |
| T4 | 1 | `devops` | cíl `make seed` v `Makefile` (+ `.PHONY`, nápověda) | diff Makefile; `make help` ukáže `seed` | ∥ T1, T2a, T3 |
| T2b | 2 | `programator` | HTTP a konzole: `Request` query, `PageNotFound` + ErrorHandler, `HomeController`, `ArticleController`, šablony, CSS, trasy, `SeedCommand`, kontejner; dotáhnout vše do GREEN | `make qa` zelené; výstup AC 24–26 (seed + curl) | po T1, T2a, T3, T4 |
| T5 | 3 | `tester` (režim B) | `make qa`, AC 24–31, Playwright (snímky `titulni-m4.png`, `clanek-m4.png`, klávesnice, konzole), MCP `EXPLAIN` (AC 29) a počty stavů (AC 24) | PASS/FAIL po kritériích; FAIL vrací T2b (seed → T3) | po T2b |
| T6 | 3 | `technicky-spisovatel` | kapitola M4 v `docs/tutorial.html` (čtení bez N+1, stránkování, 404 pro nepublikované, bezpečný Markdown „escapovat, pak značkovat“ + allowlist URL, `Clock`, seed), README: `make seed` | ověřené příkazy | ∥ T5 |
| T7 | 3 | vedoucí | STAV.md: backlog M4b (viz Mimo rozsah) a odchylka od skillu (ADR-0005); se **souhlasem** `.claude/settings.json` (otázka 8) | diff | ∥ T5 |
| — | 4 | vedoucí | report → **brána 2** → commity | — | — |

Security review se v tomto milníku nespouští (výukový režim, STAV.md); rizika jsou jen vyjmenována níže.
Databazista **nepíše migraci** (schéma beze změny), jen seed a v T5 pomůže s vyhodnocením `EXPLAIN`, pokud by
tester narazil na nejasnost.

Návrh commitů (každý projde `make up` + `make qa`):
1. `feat(clanky): čtení publikovaných článků, Clock a repozitář` (AC 1–4, 19–21)
2. `feat(sablony): bezpečný Markdown renderer a české datum` (AC 6–10, ADR-0005)
3. `feat(data): ukázková data a příkaz db:seed, cíl make seed` (AC 22–24)
4. `feat(web): titulní stránka se stránkováním a detail článku` (AC 5, 11–18, 25–30)
5. `docs: plán 004, architektura a kapitola M4` (T6 + T7 + tento plán)

## Rizika a bezpečnost
- **XSS přes Markdown (A03 Injection / A05 v OWASP 2025):** `$bodyHtml` se vypisuje bez `e()`. Zmírnění: princip
  „nejdřív escapovat“, allowlist značek a URL, vlastnostní test AC 9. Riziko chyby ve vlastním parseru je vyšší
  než u prověřené knihovny (ADR-0005) — od M6 bude text navrhovat i LLM (LLM05 nevalidovaný výstup), proto je
  renderer jediná cesta Markdown → HTML.
- **Vtažení `javascript:` přes entity a bílé znaky:** URL se kontroluje až po dekódování entit a na celé
  hodnotě allowlistem (prohlížeče ze schématu odstraňují tabulátory a nové řádky — denylist by šel obejít).
- **ReDoS:** text článku až 16 MB (`MEDIUMTEXT`); regulární výrazy jen lineární, AC 10. Limit délky textu
  (skill: 100 000 znaků) vynutí až formulář v M5.
- **IDOR / únik nepublikovaného obsahu (A01):** podmínka publikace v jediném místě (SQL repozitáře), stejná 404
  pro koncept i neexistující článek (AC 17) — nelze zjistit, že koncept se slugem existuje.
- **Duplicitní URL přes kolaci** (`czech_ci` + `PAD SPACE`) — řeší validace slugu (AC 17).
- **Enumerace stránek / velký OFFSET:** `strana` max 6 číslic a kontrola proti `totalPages`; `OFFSET` nikdy
  z neověřeného vstupu.
- **Seed v nesprávné DB:** běží jen při `APP_ENV` `dev`/`test`, nic nemaže; v produkci (M9) příkaz odmítne.
  Integrační test seedu běží jen nad `redakce_test` (`TestDatabase`). Dvě sady testů dál nesmí běžet paralelně.
- **Unit testy omylem na DB:** `KernelTest`/`TestContainer` musí nahradit `ArticleRepository` (§6).
- **Časová zóna:** `published_at` bez zóny, výklad v `Europe/Prague`; přechod letní/zimní čas může článek
  publikovaný v „chybějící“ hodině posunout o hodinu (přijato).
- **Cache:** HTML má `Cache-Control: no-store` (z `Response::html`) — pro MVP v pořádku, výkon AC 28 to nevadí.
- **CSP:** obrázky v Markdownu nejsou podporované, takže `img-src 'self' data:` nic nerozbije; inline styly
  ani skripty se nepřidávají.
- **LLM rizika:** M4 neobsahuje AI.

## Mimo rozsah
- **M4b (backlog, zapsat do STAV.md):** `/rubrika/{slug}` a `/stitek/{slug}` (výpis se stránkováním, 404 pro
  neexistující), odkazy na rubriku a štítky ve výpisu a detailu, štítky ve výpisu jedním dotazem `IN (…)`,
  fulltext `/hledat?q=` (nová migrace s FULLTEXT indexem nad `title, excerpt, body`, `MATCH … AGAINST`
  v režimu boolean, escapování dotazu), případně veřejný archiv.
- Obrázky, tabulky a vnořené seznamy v Markdownu; `league/commonmark` (ADR-0005).
- Kešování HTML / `ETag`, RSS, sitemap, `<link rel="canonical">`, meta description, Open Graph.
- Administrace článků (M5), limit délky textu ve formuláři (M5), generování slugu z titulku (M5).
- Úprava skillů `bezpecnost-owasp` (CommonMark → ADR-0005) a `db-migrace` (zmínka o `db:seed`) — změna `.claude/`.

## Otázky pro člověka
1. **Archivované články pro veřejnost 404?** Doporučuji **ano** — odpovídá zadání („404 pro nepublikovaný
   slug“) a je to nejjednodušší; čitelný archiv lze přidat v M4b jednou podmínkou v repozitáři.
2. **Skrývat publikované články s budoucím datem** (naplánované zveřejnění) přes `Clock`? Doporučuji **ano** —
   tři malé soubory (`Clock`, `SystemClock`, `FixedClock` v testech), M5 tím dostane plánované publikování zdarma
   a skill `php-oop-standardy` čas přes `Clock` stejně předepisuje.
3. **Název příkazu `db:seed`** (ostatní příkazy jsou česky: `migrace:spust`, `admin:vytvor`)? Doporučuji **ano,
   `db:seed`** — už ho slibují skill `db-migrace` a plán 002, je všeobecně známý; česká alternativa
   `data:ukazka` by vyžadovala úpravu skillu (souhlas, `.claude/`).
4. **Vlastní minimální Markdown renderer podle ADR-0005** (odchylka od skillu, který chce CommonMark)?
   Doporučuji **ano** — nová závislost je zakázaná, podmnožina pokryje běžné články a test vlastnosti
   (jen povolené značky) hlídá bezpečnost; přechod na CommonMark později = výměna jedné třídy.
5. **Seed doplňuje jen chybějící záznamy podle slugu, nic nemaže, a běží jen v `APP_ENV=dev|test`?**
   Doporučuji **ano** — opakované spuštění je bezpečné a nevyžaduje bránu „mazání dat“; „reset“ dev DB zůstává
   ruční (`migrace:vrat` + `make migrate`).
6. **Stránkování `?strana=N`, strana 1 kanonicky `/`, neplatná nebo příliš vysoká strana → 404?** Doporučuji
   **ano** — 404 místo tichého přesměrování je jednoduché, testovatelné a nevytváří nekonečně mnoho platných URL.
7. **Výpis bez štítků (jen rubrika jako text), štítky jen v detailu; rubrika a štítky zatím bez odkazů?**
   Doporučuji **ano** — výpis zůstane jedním dotazem a nevzniknou odkazy na trasy M4b, které by vracely 404.
8. **Povolit `Bash(make seed)` (a případně `Bash(make migrate)`) v `.claude/settings.json`?** Doporučuji **ano
   pro oba** — jde o přesné tvary bez argumentů, oba cíle jen spouštějí příkaz v kontejneru; jinak se tester
   v T5 na každé spuštění ptá. Změna `.claude/` vyžaduje souhlas, proto se ptám.
