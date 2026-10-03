# 005 – Administrace článků: seznam, vytvoření, úprava, smazání s auditem
Stav: návrh

- **Milník:** M5 (zúžený rozsah, příběhy 5–7 zadání; příběh 8 = správa rubrik a štítků → **M5b**) ·
  **Režim:** výukový (viz `docs/plan/STAV.md`) — MVP, bez kola security review
- **Autor:** agent architekt · **Datum:** 2026-10-03
- **Souvisí:** [plán 003](003-prihlaseni-admin.md) (přihlášení, CSRF, `AdminAccessMiddleware`, audit),
  [plán 004](004-verejna-cast.md) (stav po M4: `Slug`, `ArticleStatus`, `Clock`, `MarkdownRenderer`, seed),
  [ADR-0003](../adr/0003-anglicke-identifikatory.md), [ADR-0004](../adr/0004-anglicke-nazvy-v-databazi.md),
  [ADR-0005](../adr/0005-vlastni-markdown-renderer.md), [architektura](../architektura.md), skilly
  `php-oop-standardy`, `bezpecnost-owasp`, `db-migrace`
- **Číslování:** číslo 005 dostal M5. CI (`ci.yml`), slibované plány 001–004 jako „další volné číslo“,
  dostane **006 nebo pozdější** číslo podle toho, kdy se bude plánovat.
- **Schéma DB se nemění.** `articles` už má `created_by`, `updated_by` (FK `users`, `ON DELETE SET NULL`),
  `created_at`, `updated_at`; `article_tags` maže vazby kaskádou; `audit_log` má `entity_type`/`entity_id`
  bez FK (záznam přežije smazání článku). Jediná past: výchozí hodnoty `CURRENT_TIMESTAMP(6)` a
  `ON UPDATE CURRENT_TIMESTAMP(6)` běží v **UTC** (MariaDB), kdežto aplikace v `Europe/Prague` → repozitář
  zapisuje `created_at` a `updated_at` **explicitně z `Clock`** (explicitní hodnota v `UPDATE` přebíjí
  `ON UPDATE`). Index nad `updated_at` pro řazení seznamu se nepřidává (desítky až stovky řádků, filesort
  je levný) — viz Rizika. Úkol pro `databazista` proto není.
- **Nová závislost žádná** (`composer.json` beze změny). Převod titulku na slug bez `intl` a bez
  `iconv //TRANSLIT` (závisí na locale kontejneru) — vlastní převodní tabulka diakritiky.
- **ADR se nepíše:** rozhodnutí (tvrdé mazání podle skillu `db-migrace`, audit mimo transakci, normalizace
  slugu) jdou vrátit bez migrace dat; zdůvodnění je v §1 a v otázkách.

## Cíl
Přihlášený admin na `http://localhost:8080/admin/clanky` vidí všechny články (i koncepty, archiv a
naplánované) se stavem, rubrikou, datem publikace a tím, kdo a kdy je naposledy měnil. Vytvoří nový
článek (titulek, slug generovaný z titulku s ošetřením kolizí, perex, text v Markdownu, rubrika, štítky,
stav, datum publikace), upraví existující a smaže ho po potvrzení. Chybně vyplněný formulář vrátí české
chybové hlášky u polí; každé vytvoření, úprava a smazání se zapíše do `audit_log`. Rubriky a štítky
pocházejí ze seedu a ve formuláři se jen vybírají.

## Akceptační kritéria
Unit kritéria ověřuje PHPUnit (repozitáře v paměti, `FixedClock` na `2026-10-03 12:00` `Europe/Prague`,
`ArraySession`; přihlášení = `user_id` admina „Administrátor“ v `ArraySession` jako v `AdminLoginFlowTest`),
integrační PHPUnit nad `redakce_test` (`TestDatabase::reset()` + migrace + seed z M4), HTTP kritéria curl
z hostitele (povolené volby hooku, cookie jen `-H 'Cookie: …'`) a Playwright MCP proti `http://web/`.

**Testovací data HTTP testů (kontrakt):** rubriky `1 Technologie`, `2 Věda a výzkum`, `3 Zprávy`; štítky
`1 Bezpečnost`, `2 Docker`, `3 PHP`; admin `id 7` „Administrátor“. Platný formulář = `title = 'Nový článek'`,
`slug = ''`, `excerpt = 'Perex.'`, `body = "Ahoj **světe**"`, `category_id = '1'`, `tags[] = ['3', '2']`,
`status = 'draft'`, `published_at = ''`, platný `_csrf`.

### A. Doména (unit, `tests/Unit/Domain/Article/{SlugTest,ArticleStatusTest}.php`)
1. **Given** `Slug::fromText()`, **Then** přesně:
   `'Šablony & escapování: <script> se nespustí'` → `sablony-escapovani-script-se-nespusti`;
   `'Žluťoučký kůň úpěl ďábelské ódy'` → `zlutoucky-kun-upel-dabelske-ody`;
   `'  PHP 8.4 — novinky!  '` → `php-8-4-novinky`; `'Ahoj---světe'` → `ahoj-svete`;
   `'Ärger über Straße'` → `arger-uber-strasse`; `'ŘEŘICHA'` → `rericha`;
   `''`, `'!!!'`, `'日本語'` → `clanek`; 300× `a` → přesně 200× `a`; `str_repeat('ab ', 100)` → výsledek
   ≤ 200 znaků **bez** pomlčky na konci. **And** pro všechny vstupy výše `Slug::isValid(výsledek) === true`.
2. **Given** `Slug::uniqueAmong($base, $taken)`, **Then** `('clanek', [])` → `clanek`;
   `('clanek', ['clanek'])` → `clanek-2`; `('clanek', ['clanek', 'clanek-2', 'clanek-3'])` → `clanek-4`;
   `('clanek', ['clanek-2'])` → `clanek`; `('clanek', ['clanek', 'clanek-3'])` → `clanek-2`;
   `('clanek', ['clanek', 'clanek-x', 'clanekx'])` → `clanek-2`.
3. **Given** `ArticleStatus`, **Then** `label()` vrací `Koncept`, `Publikováno`, `Archiv`.

### B. Validace formuláře (unit, `tests/Unit/Application/Article/ArticleInputValidatorTest.php`)
4. **Given** validátor nad rubrikami a štítky z kontraktu a `FixedClock`, **When** `validate()` platného
   formuláře, **Then** `ArticleData` s `title = 'Nový článek'`, `slug = 'novy-clanek'`, `excerpt = 'Perex.'`,
   `body = "Ahoj **světe**"`, `categoryId = 1`, `tagIds = [2, 3]` (seřazené, bez duplicit — i pro vstup
   `['3', '2', '3']`), `status = ArticleStatus::Draft`, `publishedAt = null`. Titulek i perex jsou
   oříznuté (`trim`), text se neořezává.
5. **Given** `slug = 'Můj Vlastní Slug!'`, **Then** `slug = 'muj-vlastni-slug'` (ruční slug se normalizuje
   stejně jako titulek — **otázka 1**); **Given** `slug = ''` a `title = '!!!'`, **Then** `slug = 'clanek'`.
6. **Given** `status = 'published'` a `published_at = ''`, **Then** `publishedAt = 2026-10-03 12:00:00`
   `Europe/Prague` (čas z hodin, sekundy vynulované — **otázka 2**); **Given** `published_at = '2026-11-01T09:30'`,
   **Then** `2026-11-01 09:30:00` (budoucí datum = naplánovaný článek, je platné); **And** formát
   `'2026-11-01T09:30:15'` je také platný. **Given** `status = 'draft'` a datum vyplněné, **Then** datum
   se zachová.
7. **Given** neplatný vstup, **When** `validate()`, **Then** `InvalidArticleInput` s mapou `pole => zpráva`
   obsahující **všechny** chyby najednou, přesně:
   | Vstup | Pole | Zpráva |
   |---|---|---|
   | `title = '   '` | `title` | „Vyplňte titulek.“ |
   | `title` 201 znaků (vícebajtových, `mb_strlen`) | `title` | „Titulek může mít nejvýše 200 znaků.“ |
   | `slug` 221 znaků | `slug` | „Adresa (slug) může mít nejvýše 220 znaků.“ |
   | `excerpt` 501 znaků | `excerpt` | „Perex může mít nejvýše 500 znaků.“ |
   | `body` 100 001 znaků | `body` | „Text může mít nejvýše 100 000 znaků.“ |
   | `status = 'published'`, `body = "  \n"` | `body` | „Publikovaný článek musí mít text.“ (**otázka 6**) |
   | `category_id` `''`, `'abc'`, `'0'`, `'99'` | `category_id` | „Vyberte rubriku.“ |
   | `tags[] = ['99']` nebo `['x']` | `tags` | „Vybraný štítek neexistuje.“ |
   | `status` `''` nebo `'smazano'` | `status` | „Vyberte stav článku.“ |
   | `published_at` `'zitra'`, `'2026-02-30T10:00'`, `'2026-10-03 10:00'`, `'2026-10-03T25:00'` | `published_at` | „Zadejte platné datum a čas publikace.“ |
   | libovolné pole s neplatným UTF-8 `"\xC3\x28"` | to pole | „Pole obsahuje neplatné znaky.“ |
   Prázdný `excerpt`, prázdné `tags` a prázdný `body` u konceptu jsou platné.

### C. Use-case služby a audit (unit, `tests/Unit/Application/Article/{CreateArticle,UpdateArticle,DeleteArticle}Test.php`)
8. **Given** prázdný repozitář, **When** `CreateArticle::handle(platný formulář, admin 7, '172.18.0.1')`,
   **Then** vrátí nové ID; repozitář dostal `create(ArticleData se slugem 'novy-clanek', authorId 7, now)`;
   audit má jeden záznam `article.created`, `userId 7`, `entityType 'article'`, `entityId` = ID,
   `summary = 'Nový článek [novy-clanek]'`, IP `172.18.0.1`.
9. **Given** existují slugy `novy-clanek` a `novy-clanek-2`, **When** vytvoření se stejným titulkem, **Then**
   slug `novy-clanek-3`; repozitář byl dotázán na obsazené slugy **jednou** (`takenSlugs('novy-clanek', null)`).
10. **Given** neplatný formulář, **When** `CreateArticle::handle`, **Then** `InvalidArticleInput`, repozitář
    **nedostal** `create`, audit je prázdný. **Given** repozitář při `create` vyhodí `SlugAlreadyTaken`
    (souběh), **Then** `InvalidArticleInput` s `slug` → „Adresa (slug) je už obsazená, uložte formulář znovu.“
    a audit je prázdný.
11. **Given** článek 5 se slugem `stary-slug` a formulář se `slug = 'stary-slug'`, novým titulkem „Nový
    titulek“, **When** `UpdateArticle::handle(5, …, admin 7, ip)`, **Then** slug zůstává `stary-slug`
    (úprava titulku slug nemění), `takenSlugs` dostal `exceptArticleId = 5` (vlastní slug není kolize),
    repozitář dostal `update(5, data, editorId 7, now)`, audit `article.updated` s `entityId 5`
    a `summary = 'Nový titulek [stary-slug]'`. **Given** `slug = ''`, **Then** slug se vygeneruje z titulku
    (`novy-titulek`).
12. **Given** neexistující článek 404, **When** `UpdateArticle::handle(404, …)` nebo `DeleteArticle::handle(404, …)`,
    **Then** `ArticleNotFound`, žádný zápis ani audit.
13. **Given** článek 5 „Starý článek“ (`stary-clanek`), **When** `DeleteArticle::handle(5, admin 7, ip)`,
    **Then** repozitář dostal `delete(5)`, vrátí `'Starý článek'` (pro flash), audit `article.deleted`,
    `entityId 5`, `summary = 'Starý článek [stary-clanek]'` (kopie titulku — entita už neexistuje).

### D. HTTP infrastruktura (unit, `tests/Unit/Http/{RequestListTest,PageNumberTest,FlashTest}.php`)
14. **Given** `$_POST = ['tags' => ['3', '5'], 'x' => [['vnořené']], 'y' => ['a' => 'b'], 'title' => 'T']`,
    **When** `Request::fromGlobals()`, **Then** `inputList('tags') === ['3', '5']`, `inputList('x') === []`
    (vnořené pole se zahodí), `inputList('y') === ['b']` (klíče se zahodí), `inputList('chybi') === []`,
    `inputList('title') === []`, `input('tags') === ''` (regrese AC 1 plánu 003); **And** `withRoute()`
    seznamy zachová.
15. **Given** `PageNumber::fromQuery()`, **Then** `''` → `1`, `'2'` → `2`, `'999999'` → `999999`; `'0'`, `'-1'`,
    `'01'`, `'1.5'`, `'abc'`, `'1000000'` → `PageNotFound` (stejná pravidla jako `HomeController` v M4,
    AC 13 plánu 004 dál prochází).
16. **Given** `Flash` nad `ArraySession`, **When** `set('Uloženo.')`, pak `pull()` dvakrát, **Then**
    `'Uloženo.'`, pak `''`; klíč v session je `flash` (sdílený s přihlášením — AC 15 plánu 003 dál prochází).

### E. HTTP přes Kernel (`tests/Unit/Http/AdminArticlesTest.php`; repozitáře v paměti)
17. **Přístup:** **Given** nepřihlášený, **When** `GET /admin/clanky`, `/admin/clanky/novy`,
    `/admin/clanky/5/upravit`, `/admin/clanky/5/smazat`, **Then** `303` `Location: /admin/prihlaseni`;
    **When** `POST /admin/clanky/novy`, `/admin/clanky/5/upravit`, `/admin/clanky/5/smazat` s platným
    `_csrf`, **Then** `303` na přihlášení a repozitář nedostal žádný zápis; **When** stejné `POST`
    **přihlášeně bez `_csrf`** (nebo se špatným), **Then** `403` „Neplatný formulář“ a žádný zápis ani audit.
18. **Seznam:** **Given** přihlášený a 3 články (koncept, publikovaný, publikovaný s datem `2099-01-01`),
    jeden s `updatedByName = null`, **When** `GET /admin/clanky`, **Then** `200`, `<h1>Články</h1>`, odkaz
    `<a href="/admin/clanky/novy">Nový článek</a>`, `<table>` s řádkem na článek: titulek jako odkaz
    `/admin/clanky/{id}/upravit`, stav „Koncept“ / „Publikováno“ / „Naplánováno“, rubrika, datum publikace
    česky nebo „—“, „Naposledy upraveno“ ve tvaru `3. října 2026 14:05` + jméno nebo „neuvedeno“, odkazy
    „Upravit“ a „Smazat“ (`/admin/clanky/{id}/smazat`). Pořadí = pořadí z repozitáře. **Given** prázdný
    repozitář, **Then** „Zatím tu nejsou žádné články.“
19. **Stránkování** (**otázka 3**): **Given** 21 článků, **When** `GET /admin/clanky`, **Then** 20 řádků
    a `<a href="/admin/clanky?strana=2" rel="next">Další strana</a>`; `?strana=2` → 1 řádek a
    `<a href="/admin/clanky" rel="prev">Předchozí strana</a>`; `?strana=3`, `?strana=0`, `?strana=abc` → `404`.
20. **Formulář nového článku:** **When** `GET /admin/clanky/novy`, **Then** `200`, `<h1>Nový článek</h1>`,
    `<form method="post" action="/admin/clanky/novy">` se skrytým `_csrf`, poli `title` (`maxlength="200"`,
    `required`), `slug` (`maxlength="220"`, nápověda „Nechte prázdné – vytvoří se z titulku.“), `excerpt`
    (`<textarea maxlength="500">`), `body` (`<textarea>`), `<select name="category_id">` s prázdnou volbou
    „— vyberte rubriku —“ a rubrikami v pořadí z repozitáře, `<fieldset>` „Štítky“ se zaškrtávátky
    `name="tags[]"` (hodnota = ID), `<select name="status">` (Koncept vybraný, Publikováno, Archiv),
    `<input type="datetime-local" name="published_at">`; každé pole má `<label for>`; tlačítko „Uložit článek“.
21. **Vytvoření:** **When** `POST /admin/clanky/novy` s platným formulářem, **Then** `303`
    `Location: /admin/clanky/{nové id}/upravit`; následné `GET` té adresy ukáže **jednou** „Článek byl vytvořen.“
    (`role="status"`); repozitář má článek se slugem `novy-clanek`, `createdBy = updatedBy = 7`,
    štítky `[2, 3]`; audit `article.created`.
22. **Chyby formuláře:** **When** `POST /admin/clanky/novy` s `title = ''`, `category_id = ''`,
    `excerpt = '<b>perex</b>'`, zaškrtnutým štítkem 3, **Then** `422`, souhrn
    „Článek se nepodařilo uložit, opravte prosím chyby ve formuláři.“ (`role="alert"`), u polí
    „Vyplňte titulek.“ a „Vyberte rubriku.“ (`id="title-error"`, pole má `aria-invalid="true"`
    a `aria-describedby="title-error"`), zadané hodnoty zůstávají (perex escapovaný `&lt;b&gt;perex&lt;/b&gt;`,
    štítek 3 `checked`), nic se neuložilo, audit prázdný.
23. **Úprava — formulář:** **Given** článek 5 (koncept, štítky 1 a 3, `published_at 2026-11-01 09:30`,
    `updatedAt 2026-10-02 14:05`, `updatedByName 'Jana <b>'`), **When** `GET /admin/clanky/5/upravit`,
    **Then** `200`, `<h1>Úprava článku</h1>`, `<form method="post" action="/admin/clanky/5/upravit">`,
    předvyplněné hodnoty (titulek, slug, perex, text, vybraná rubrika, štítky 1 a 3 `checked`,
    `value="2026-11-01T09:30"`), text „Naposledy upraveno 2. října 2026 14:05 (Jana &lt;b&gt;)“; odkaz
    „Smazat článek“ na `/admin/clanky/5/smazat`. Odkaz „Zobrazit na webu“ (`/clanek/{slug}`) je **jen**
    u publikovaného článku s `published_at <= now`.
24. **Náhled** (**otázka 4**): **Given** článek 5 s textem `"## Nadpis\n\n<script>alert(1)</script>"`,
    **When** `GET /admin/clanky/5/upravit`, **Then** sekce „Náhled uloženého textu“ obsahuje `<h2>Nadpis</h2>`
    a `&lt;script&gt;alert(1)&lt;/script&gt;`, nikdy `<script>`. U prázdného textu sekce chybí.
25. **Úprava — uložení:** **When** `POST /admin/clanky/5/upravit` s platným formulářem, **Then** `303` na
    `/admin/clanky/5/upravit` a jednou „Změny byly uloženy.“; repozitář dostal `update(5, …, 7, now)`;
    audit `article.updated`. **When** neplatný formulář, **Then** `422` jako AC 22 s `action` na
    `/admin/clanky/5/upravit` a bez zápisu.
26. **Smazání:** **When** `GET /admin/clanky/5/smazat`, **Then** `200`, `<h1>Smazat článek</h1>`, text
    „Opravdu smazat článek „Starý článek“? Akci nelze vrátit.“, `<form method="post" action="/admin/clanky/5/smazat">`
    se `_csrf` a tlačítkem „Smazat článek“, odkaz „Zrušit“ na `/admin/clanky/5/upravit`; **GET nic nesmaže**.
    **When** `POST /admin/clanky/5/smazat` s `_csrf`, **Then** `303` na `/admin/clanky` a jednou
    „Článek „Starý článek“ byl smazán.“; audit `article.deleted`.
27. **404:** **When** `GET`/`POST` na `/admin/clanky/404/upravit` nebo `/admin/clanky/404/smazat`
    (neexistuje), `/admin/clanky/0/upravit`, `/admin/clanky/abc/upravit`, `/admin/clanky/05/upravit`,
    `/admin/clanky/1234567890123456789/upravit` (19 číslic), **Then** `404` „Stránka nenalezena“ (ne `500`)
    a žádný zápis. **When** `PUT /admin/clanky/5/upravit`, **Then** `405` + `Allow: GET, POST`.
28. **Escapování:** **Given** článek s titulkem `<script>alert(1)</script>` a rubrikou `R & D`, **When**
    seznam, formulář úprav i potvrzení smazání, **Then** tělo obsahuje `&lt;script&gt;` a `R &amp; D`,
    nikdy `<script>alert`.
29. **Rozcestník:** **When** `GET /admin`, **Then** odkaz `<a href="/admin/clanky">Články</a>` (text
    „Správa článků přibude v dalším milníku.“ zmizí).

### F. Persistence (`tests/Integration/Persistence/{PdoArticleAdminRepository,PdoCategoryRepository,PdoTagRepository}Test.php`)
30. **Given** `redakce_test` po migracích a seedu (16 článků), admin vložený přes `PdoUserRepository::add`,
    `now = 2026-10-03 12:00:00` `Europe/Prague`, **When** `create(data se 2 štítky, authorId, now)`, **Then**
    řádek má `created_by = updated_by = authorId`, `created_at = updated_at = '2026-10-03 12:00:00.000000'`
    (čas z PHP, **ne** UTC z DB), 2 řádky v `article_tags`; `findForEditing(id)` vrátí `EditableArticle`
    se všemi poli, `tagIds` seřazené, `updatedByName` = jméno admina; neexistující ID → `null`.
31. **When** `update(id, data s jiným titulkem a 1 štítkem, editorId, now + 1 h)`, **Then** `updated_at =
    '2026-10-03 13:00:00.000000'`, `updated_by = editorId`, `created_*` beze změny, v `article_tags` jen nový
    štítek. **When** `delete(id)`, **Then** řádek i jeho `article_tags` zmizí (kaskáda), ostatních 16 článků zůstává.
32. **When** `list(20, 0)` a `countAll()`, **Then** `countAll() === 17` (seed + nový), řazení
    `updated_at DESC, id DESC`, každá položka má `categoryName`; seedované články mají `updatedByName = null`.
33. **Slugy:** **Given** články se slugy `novy-clanek`, `novy-clanek-2`, `novy-clanek-x`, `novy-clanekx`,
    **When** `takenSlugs('novy-clanek', null)`, **Then** obsahuje `novy-clanek`, `novy-clanek-2` (smí obsahovat
    i `novy-clanek-x`, filtr dělá `Slug::uniqueAmong`), **ne** `novy-clanekx`; s `exceptArticleId` = id
    `novy-clanek` ho vynechá. **When** `create` se slugem, který už existuje, **Then** `SlugAlreadyTaken`
    (ne `PDOException`) a nic se neuloží (ani štítky — transakce).
34. **Bez N+1** (měření `Com_select`/`Com_insert`/`Com_delete` jako AC 21 plánu 004): `list()` = 1 SELECT
    (rubrika i jméno přes `JOIN`), `countAll()` = 1, `findForEditing()` ≤ 2 (článek + štítky),
    `CategoryRepository::all()` = 1, `TagRepository::all()` = 1, `takenSlugs()` = 1; `create` se 3 štítky =
    **2** `INSERT` (článek + jeden vícehodnotový `INSERT` vazeb); `update` = 1 `UPDATE` + 1 `DELETE` + ≤ 1 `INSERT`
    — nezávisle na počtu štítků.
35. **Given** seed, **When** `PdoCategoryRepository::all()` a `PdoTagRepository::all()`, **Then** 3 rubriky
    a 5 štítků seřazených podle `utf8mb4_czech_ci` (`Technologie`, `Věda a výzkum`, `Zprávy`).

### G. HTTP z hostitele a E2E (`tests/E2E-scenare.md`, nový oddíl „Administrace článků (M5)“)
36. **Given** `make up`, `make migrate`, `make seed`, admin z AC 20 plánu 003, **When**
    `curl -s http://localhost:8080/admin/clanky -D - -o /dev/null`, **Then** `303` + `Location: /admin/prihlaseni`;
    **When** `curl -s -X POST http://localhost:8080/admin/clanky/1/smazat -o /dev/null -w '%{http_code}'`
    (bez cookie a tokenu), **Then** `403`; MCP dotaz `SELECT COUNT(*) FROM articles` je stejný jako před tím.
37. **Given** Playwright, **When** přihlášení na `http://web/admin` → „Články“ (snímek
    `tests/_artefakty/admin-clanky-m5.png`) → „Nový článek“ → odeslat prázdný formulář, **Then** `422`
    s hláškami „Vyplňte titulek.“ a „Vyberte rubriku.“; **When** vyplnit titulek „Můj první článek
    z administrace“, perex, text `**Tučně** a <script>alert(1)</script>`, rubriku Technologie, štítky PHP
    a Docker, stav Publikováno, datum prázdné → „Uložit článek“, **Then** stránka úprav s „Článek byl
    vytvořen.“, slugem `muj-prvni-clanek-z-administrace`, „Naposledy upraveno … (Administrátor)“ a náhledem
    s tučným textem a escapovaným `<script>` (snímek `tests/_artefakty/admin-clanek-m5.png`); **And**
    `http://web/` má článek na prvním místě a `http://web/clanek/muj-prvni-clanek-z-administrace` ho zobrazí
    se štítky „Docker“, „PHP“.
38. **When** úprava titulku → uložit, **Then** „Změny byly uloženy.“ a slug beze změny; **When** druhý článek
    se stejným titulkem jako první (původním), **Then** slug `muj-prvni-clanek-z-administrace-2`; **When**
    u druhého „Smazat článek“ → potvrzení → „Smazat článek“, **Then** seznam s hláškou „… byl smazán.“ bez
    druhého článku; MCP dotaz `SELECT action, entity_type, entity_id, summary FROM audit_log ORDER BY id DESC LIMIT 4`
    ukáže `article.deleted`, `article.created`, `article.updated`, `article.created`;
    `browser_console_messages` (level `error`) prázdné (žádná chyba CSP); formulář jde vyplnit a odeslat
    jen klávesnicí (`Tab`, mezerník u zaškrtávátek, `Enter`).
39. **Regrese:** AC 25–26 a 30 plánu 004 (veřejná část, `/zdravi`, přihlášení) dál platí; první článek
    z E2E zůstává v dev DB (seed ho nepřepíše), druhý smazal scénář.

### H. Kvalita
40. **Given** běžící prostředí, **When** `make qa`, **Then** kód 0; **And** grep: SQL jen v `*Repository`,
    `database/migrations/` a `database/seeds/`; `$_POST`/`$_GET` jen v `src/Http/Request.php`; v šablonách
    jediné výpisy bez `e()` jsou `$content` (`layout.php`), `$bodyHtml` (`article.php`) a `$previewHtml`
    (`admin/articles/form.php`), oba poslední s komentářem `bezpecne: sanitizovano`; žádné `style=` ani
    `onclick=`; žádné české znaky v identifikátorech; každý `<form method="post">` má `csrf_field`.

## Návrh

### 1. Tok požadavku a klíčová rozhodnutí
```
POST /admin/clanky/5/upravit
  SecurityHeaders → ErrorHandler → Routing → Csrf (_csrf) → AdminAccess (/admin/… → přihlášen?)
  → Admin\ArticleController::update
      id = ArticleId z trasy (^[1-9][0-9]{0,17}\z, jinak PageNotFound)
      user = AuthSession::user()            (obrana do hloubky, null → 303 přihlášení)
      input = new ArticleInput(…z request->input(), tagIds: request->inputList('tags'))   (surové řetězce)
      UpdateArticle::handle(5, input, user, clientIp)
         ArticleAdminRepository::findForEditing(5)    null → ArticleNotFound → PageNotFound → 404
         ArticleInputValidator::validate(input)        Category/TagRepository::all(), Clock
            chyby → InvalidArticleInput(errors) → controller: 422 + formulář s hodnotami a chybami
         slug: input.slug ?: titulek → Slug::fromText → takenSlugs(base, except 5) → Slug::uniqueAmong
         ArticleAdminRepository::update(5, data, user.id, now)   transakce: UPDATE + DELETE/INSERT vazeb
         AuditLogRepository::add(article.updated)
      Flash::set('Změny byly uloženy.') → 303 /admin/clanky/5/upravit   (PRG)
```
- **Vrstvy:** controller → use-case (`CreateArticle`, `UpdateArticle`, `DeleteArticle` podle skillu
  `php-oop-standardy`) → rozhraní v `Domain` ← PDO repozitáře. Čtení pro administraci jde přes
  `AdminArticles` (Application), ne přímo z controlleru do repozitáře.
- **Oddělené rozhraní pro administraci:** `ArticleAdminRepository` (čtení všech stavů + zápis) vedle
  veřejného `ArticleRepository` (jen publikované). Pravidlo „veřejně jen publikované“ z M4 tak nejde omylem
  obejít a veřejný dvojník v testech se nemění. Implementuje ho nová třída `PdoArticleAdminRepository`.
- **Admin URL podle ID, ne slugu** (slug se smí měnit). ID se validuje regexem před dotazem (max 18 číslic
  — žádné přetečení `int`), neplatné/neexistující → 404.
- **Slug:** `Slug::fromText()` (čistá funkce: převodní tabulka diakritiky → malá písmena → vše mimo `a-z0-9`
  na `-` → zkrátit na 200 znaků, ať zbude místo na příponu `-N` do 220) a `Slug::uniqueAmong()` (čistá
  funkce nad seznamem obsazených). Obsazené slugy načte **jeden** dotaz `takenSlugs()` — žádná smyčka
  dotazů. Souběh dvou uložení řeší `UNIQUE` index: repozitář přeloží chybu 1062 na `SlugAlreadyTaken`
  a use-case na chybu formuláře.
- **Transakce:** `create`/`update` v repozitáři obalí článek + vazby štítků do jedné transakce
  (`beginTransaction`/`commit`/`rollBack`). **Audit se zapisuje až po úspěšném uložení, mimo transakci**
  (repozitáře nesdílejí transakci bez nové abstrakce v Application) — **otázka 5**.
- **Čas:** `now` z `Clock` → `created_at`, `updated_at` i výchozí `published_at`; formát
  `Y-m-d H:i:s.u` v zóně PHP stejně jako `PdoArticleRepository::formatNow`.
- **Mazání:** tvrdé `DELETE` (skill `db-migrace`: „tvrdé DELETE + záznam v audit_log s kopií titulku“),
  vazby maže kaskáda. Potvrzení = samostatná GET stránka s POST formulářem (bez JavaScriptu).
- **Flash zprávy:** nová malá třída `Http\Session\Flash` nad `Session` (klíč `flash`), použije ji
  `ArticleController` i `LoginController` (dvě reálná použití, plán 003 slíbil zobecnění v M5).
- **Seznam hodnot z formuláře:** `Request` dnes ne-řetězce zahazuje, takže `tags[]` by se ztratilo.
  Přibude `bodyLists` (jen jednoúrovňová pole řetězců, klíče se zahodí) a `inputList()`.
- **Stránkování seznamu:** 20 na stránku, `?strana=N`. Parsování čísla strany se vytáhne z `HomeController`
  do `Http\PageNumber` (dvě použití) a `ArticlePage` se zobecní PHPDoc šablonou `@template T`.
- **Bez živého náhledu:** živý náhled by potřeboval JavaScript a druhou implementaci Markdownu nebo
  endpoint — netriviální. Místo něj stránka úprav vykreslí **uloženou** verzi textu přes `MarkdownRenderer`.

### 2. Nové a změněné třídy
| Soubor | Typ | Odpovědnost |
|---|---|---|
| `src/Domain/Article/Slug.php` | změna | + `static fromText(string $text): string` (tabulka `strtr` pro česká, slovenská a německá písmena vč. velkých: á č ď é ě í ľ ĺ ň ó ô ŕ ř š ť ú ů ý ž ä ö ü → základní písmeno, ß → ss; pak `strtolower`, `preg_replace('/[^a-z0-9]+/', '-')`, `trim('-')`, `substr(0, 200)`, `rtrim('-')`; prázdné → `clanek`); `const int BASE_MAX_LENGTH = 200`; `static uniqueAmong(string $base, list<string> $taken): string` (`base`, jinak první volné `base-2`, `base-3`, …) |
| `src/Domain/Article/ArticleStatus.php` | změna | + `label(): string` (Koncept / Publikováno / Archiv) |
| `src/Domain/Article/ArticleData.php` | `final readonly class` | zapisovaný stav: `string $title`, `string $slug`, `string $excerpt`, `string $body`, `int $categoryId`, `list<int> $tagIds`, `ArticleStatus $status`, `?\DateTimeImmutable $publishedAt`; `withSlug(string): self` |
| `src/Domain/Article/AdminArticleSummary.php` | `final readonly class` | řádek seznamu: `int $id`, `string $title`, `string $slug`, `ArticleStatus $status`, `string $categoryName`, `?\DateTimeImmutable $publishedAt`, `\DateTimeImmutable $updatedAt`, `?string $updatedByName`; `isScheduled(\DateTimeImmutable $now): bool` (publikovaný s budoucím datem) |
| `src/Domain/Article/EditableArticle.php` | `final readonly class` | článek pro formulář: `int $id`, pole jako `ArticleData` (`list<int> $tagIds`), `\DateTimeImmutable $updatedAt`, `?string $updatedByName`; `isPubliclyVisible(\DateTimeImmutable $now): bool` |
| `src/Domain/Article/ArticleAdminRepository.php` | `interface` | `list(int $limit, int $offset): list<AdminArticleSummary>`, `countAll(): int`, `findForEditing(int $id): ?EditableArticle`, `takenSlugs(string $base, ?int $exceptArticleId): list<string>`, `create(ArticleData $data, int $authorId, \DateTimeImmutable $now): int`, `update(int $id, ArticleData $data, int $editorId, \DateTimeImmutable $now): void`, `delete(int $id): void`. `create`/`update` hází `SlugAlreadyTaken`. |
| `src/Domain/Article/SlugAlreadyTaken.php` | `final class extends \RuntimeException` | souběžná kolize slugu (chyba 1062) |
| `src/Domain/Category/{Category,CategoryRepository}.php` | `final readonly class` + `interface` | `Category(int $id, string $name, string $slug)`; `all(): list<Category>` (`ORDER BY name`). M5b rozšíří o CRUD. |
| `src/Domain/Tag/{Tag,TagRepository}.php` | `final readonly class` + `interface` | totéž pro štítky |
| `src/Domain/Audit/AuditAction.php` | změna | + `ArticleCreated = 'article.created'`, `ArticleUpdated = 'article.updated'`, `ArticleDeleted = 'article.deleted'` |
| `src/Application/Article/ArticleInput.php` | `final readonly class` | surová data formuláře (`string $title`, `$slug`, `$excerpt`, `$body`, `$categoryId`, `$status`, `$publishedAt`, `list<string> $tagIds`) — slouží i k opětovnému vykreslení po chybě. Application nezná `Http\Request`, takže objekt skládá controller pojmenovanými argumenty z `input()`/`inputList('tags')`. `static fromArticle(EditableArticle)` (`publishedAt` → `Y-m-d\TH:i`), `static empty()` (stav `draft`, ostatní prázdné) |
| `src/Application/Article/InvalidArticleInput.php` | `final class extends \RuntimeException` | `array<string, string> $errors` (pole → česká zpráva) |
| `src/Application/Article/ArticleInputValidator.php` | `final readonly class` | `__construct(CategoryRepository, TagRepository, Clock)`; `validate(ArticleInput): ArticleData` podle AC 4–7; konstanty délek `TITLE_MAX = 200`, `SLUG_INPUT_MAX = 220`, `EXCERPT_MAX = 500`, `BODY_MAX = 100_000`; datum `createFromFormat('!Y-m-d\TH:i', …, Europe/Prague)` nebo `'!Y-m-d\TH:i:s'` + kontrola zpětným `format()` (odmítne 30. února) |
| `src/Application/Article/ArticleNotFound.php` | `final class extends \RuntimeException` | článek s ID neexistuje |
| `src/Application/Article/CreateArticle.php` | `final readonly class` | `__construct(ArticleInputValidator, ArticleAdminRepository, AuditLogRepository, Clock)`; `handle(ArticleInput, User $actor, ?string $ipAddress): int` (AC 8–10) |
| `src/Application/Article/UpdateArticle.php` | `final readonly class` | totéž; `handle(int $id, ArticleInput, User, ?string): void` (AC 11–12) |
| `src/Application/Article/DeleteArticle.php` | `final readonly class` | `__construct(ArticleAdminRepository, AuditLogRepository)`; `handle(int $id, User, ?string): string` vrací titulek (AC 12–13) |
| `src/Application/Article/AdminArticles.php` | `final readonly class` | čtení pro administraci: `const int PAGE_SIZE = 20`; `page(int): ?ArticlePage<AdminArticleSummary>` (stejná logika jako `PublishedArticles::page`), `find(int): ?EditableArticle`, `categories(): list<Category>`, `tags(): list<Tag>` |
| `src/Application/Article/ArticlePage.php` | změna | PHPDoc `@template T`, `@param list<T> $articles`; `PublishedArticles` vrací `ArticlePage<ArticleSummary>` |
| `src/Infrastructure/Persistence/PdoArticleAdminRepository.php` | `final readonly class implements ArticleAdminRepository` | `__construct(\PDO)`; seznam `SELECT a.id, a.title, a.slug, a.status, a.published_at, a.updated_at, c.name AS category_name, u.display_name AS updated_by_name FROM articles a JOIN categories c ON c.id = a.category_id LEFT JOIN users u ON u.id = a.updated_by ORDER BY a.updated_at DESC, a.id DESC LIMIT :limit OFFSET :offset`; `takenSlugs`: `WHERE (slug COLLATE utf8mb4_bin = :base OR slug COLLATE utf8mb4_bin LIKE :prefix) AND id <> :except_id` (`prefix = base . '-%'`; slug je ASCII bez `_`/`%`; binární kolace obchází kontrakce typu „ch“ v `czech_ci`; `except_id` = 0, když není; **pojmenovaný parametr nelze použít dvakrát** — `EMULATE_PREPARES` je vypnuté); vazby štítků jedním vícehodnotovým `INSERT … VALUES (?, ?), (?, ?)`; `created_at`/`updated_at` explicitně z `$now`; `PDOException` s `errorInfo[1] === 1062` na `uq_articles_slug` → `SlugAlreadyTaken`; hydratace s kontrolou tvaru řádku (`\UnexpectedValueException`) jako `PdoArticleRepository` |
| `src/Infrastructure/Persistence/{PdoCategoryRepository,PdoTagRepository}.php` | `final readonly class` | `all()` jedním dotazem `ORDER BY name` |
| `src/Http/Request.php` | změna | + `array<string, list<string>> $bodyLists = []` (poslední parametr konstruktoru), `inputList(string $name): list<string>`; `fromGlobals` plní z `$_POST` hodnot, které jsou pole a všechny jejich položky jsou řetězce (`array_values`); `withRoute`/`withRouteParameters` seznamy přenášejí |
| `src/Http/PageNumber.php` | `final class` | `static fromQuery(string $value): int` — `''` → 1, `^[1-9][0-9]{0,5}\z` → číslo, jinak `PageNotFound`; `HomeController` ho převezme (refaktoring bez změny chování) |
| `src/Http/Session/Flash.php` | `final readonly class` | `__construct(Session)`; `set(string $message): void`, `pull(): string` (klíč `flash`); `LoginController` přejde na něj |
| `src/Http/Controller/Admin/ArticleController.php` | `final readonly class` | `__construct(TemplateRenderer, AdminArticles, CreateArticle, UpdateArticle, DeleteArticle, MarkdownRenderer, AuthSession, CsrfToken, Flash, Clock)`; akce `index`, `create`, `store`, `edit`, `update`, `confirmDelete`, `delete`; `ArticleNotFound` → `PageNotFound`; `InvalidArticleInput` → `422` s formulářem. Žádná validace ani SQL. |
| `src/Http/Controller/Admin/DashboardController.php` | beze změny | (mění se jen šablona) |

### 3. Trasy, šablony, styly
- `config/routes.php` (URL česky, ochrana přes prefix `/admin` z M3):
  ```
  GET  /admin/clanky               ArticleController::index
  GET  /admin/clanky/novy          ::create          POST /admin/clanky/novy          ::store
  GET  /admin/clanky/{id}/upravit  ::edit            POST /admin/clanky/{id}/upravit  ::update
  GET  /admin/clanky/{id}/smazat   ::confirmDelete   POST /admin/clanky/{id}/smazat   ::delete
  ```
  Po vytvoření i úpravě PRG na `/admin/clanky/{id}/upravit` (admin pokračuje v práci), po smazání na `/admin/clanky`.
- `config/container.php`: `ArticleAdminRepository` → `PdoArticleAdminRepository`, `CategoryRepository` →
  `PdoCategoryRepository`, `TagRepository` → `PdoTagRepository` (vše nad sdíleným `\PDO`); ostatní autowiring.
- `templates/admin/articles/index.php` — `<h1>Články</h1>`, flash, odkaz „Nový článek“, `<table>`
  s `<caption class="visually-hidden">Seznam článků</caption>` a `<th scope="col">`, stav přes
  `$article->isScheduled($now) ? 'Naplánováno' : $article->status->label()`, datum `czech_date()` + `H:i`,
  stránkování `<nav aria-label="Stránkování">`, odkaz „Zpět do administrace“.
- `templates/admin/articles/form.php` — sdílená pro nový i úpravu (`$action`, `$heading`, `$input`
  typu `ArticleInput`, `$errors`, `$categories`, `$tags`, `$article` nebo `null`, `$previewHtml`, `$flash`,
  `$csrfToken`, `$now`); chyby u polí `<p class="field-error" id="{pole}-error">` + `aria-invalid`
  a `aria-describedby`; souhrn chyb `role="alert"`; náhled `<section class="article-body">` s
  `<?= $previewHtml /* {# bezpecne: sanitizovano #} */ ?>`.
- `templates/admin/articles/delete.php` — potvrzení (AC 26).
- `templates/admin/dashboard.php` — odkaz „Články“ místo věty o dalším milníku.
- `public/assets/app.css` — tabulka (na úzkém displeji `overflow-x: auto` v obalu), `input[type=text|datetime-local]`,
  `select`, `textarea` (šířka 100 %, `min-height` u textu), `fieldset`, `.field-error`, `[aria-invalid="true"]`
  červený rámeček, `.button-danger`, odkaz jako tlačítko; kontrast AA. Žádné inline styly (CSP).
- Názvy polí formuláře anglicky (`title`, `slug`, `excerpt`, `body`, `category_id`, `tags[]`, `status`,
  `published_at`, `_csrf`), texty a URL česky.

### 4. Testy (píše tester; názvy anglicky)
- Dvojníci v `tests/Unit/Support/`: `InMemoryArticleAdminRepository` (ukládá `ArticleData`, přiděluje ID,
  zaznamenává volání `create`/`update`/`delete`/`takenSlugs` a argumenty, umí nasimulovat `SlugAlreadyTaken`),
  `InMemoryCategoryRepository`, `InMemoryTagRepository` (výchozí data z kontraktu nad AC).
- **`TestContainer::replaceArticleDependencies` musí nahradit i `ArticleAdminRepository`, `CategoryRepository`
  a `TagRepository`** — jinak unit testy přes Kernel sáhnou do `redakce_test` (po `TestDatabase::reset()`
  bez tabulek → `500`).
- Unit: `Domain/Article/{SlugTest (rozšířit), ArticleStatusTest}`, `Application/Article/{ArticleInputValidatorTest,
  CreateArticleTest, UpdateArticleTest, DeleteArticleTest, AdminArticlesTest}`, `Http/{RequestListTest,
  PageNumberTest, FlashTest, AdminArticlesTest}`; regrese `PublicPagesTest`, `AdminLoginFlowTest`, `KernelTest`.
- Integrační: `Persistence/{PdoArticleAdminRepositoryTest, PdoCategoryRepositoryTest, PdoTagRepositoryTest}`
  (seed jako fixture, měření příkazů jako `PdoArticleRepositoryTest`).
- `tests/E2E-scenare.md`: oddíl „Administrace článků (M5)“ (AC 36–39), snímky `admin-clanky-m5.png`,
  `admin-clanek-m5.png`.

## Dotčené soubory
**Nové:** `src/Domain/Article/{ArticleData,AdminArticleSummary,EditableArticle,ArticleAdminRepository,SlugAlreadyTaken}.php`,
`src/Domain/Category/{Category,CategoryRepository}.php`, `src/Domain/Tag/{Tag,TagRepository}.php`,
`src/Application/Article/{ArticleInput,InvalidArticleInput,ArticleInputValidator,ArticleNotFound,CreateArticle,UpdateArticle,DeleteArticle,AdminArticles}.php`,
`src/Infrastructure/Persistence/{PdoArticleAdminRepository,PdoCategoryRepository,PdoTagRepository}.php`,
`src/Http/PageNumber.php`, `src/Http/Session/Flash.php`, `src/Http/Controller/Admin/ArticleController.php`,
`templates/admin/articles/{index,form,delete}.php`, testy dle §4.

**Změněné:** `src/Domain/Article/{Slug,ArticleStatus}.php`, `src/Domain/Audit/AuditAction.php`,
`src/Application/Article/{ArticlePage,PublishedArticles}.php` (jen PHPDoc), `src/Http/Request.php`,
`src/Http/Controller/HomeController.php` (→ `PageNumber`), `src/Http/Controller/Admin/LoginController.php`
(→ `Flash`), `config/{container,routes}.php`, `templates/admin/dashboard.php`, `public/assets/app.css`,
`tests/Unit/Support/TestContainer.php`, `tests/Unit/Domain/Article/SlugTest.php`, `tests/E2E-scenare.md`,
`docs/architektura.md` (hotovo v rámci plánu), `docs/plan/STAV.md` (backlog M4b + M5b),
`docs/tutorial.html` + `README.md` (kapitola M5).

**Beze změny:** schéma DB a migrace, seed, `compose.yaml`, `Makefile`, `composer.json`/`composer.lock`, `.claude/`.

## Úkoly pro agenty
Brána 1 (člověk) schvaluje: tento plán a otázky 1–7.

| # | Fáze | Agent | Úkol | Výstup | Souběh |
|---|---|---|---|---|---|
| T1 | 1 | `tester` (režim A) | testy z §4 pro AC 1–35 + dvojníci; úprava `TestContainer`; E2E oddíl AC 36–39 | soubory testů; doložit RED ze správného důvodu (chybí třídy/metody/trasy) | ∥ T2a |
| T2a | 1 | `programator` | vrstva bez HTTP: změny `Slug`/`ArticleStatus`/`AuditAction`, `Domain/Article/*` nové, `Domain/Category`, `Domain/Tag`, `Application/Article/*` nové, tři PDO repozitáře, továrny v kontejneru (signatury dané §2, nečeká na testy) | kód; `make check` zelené | ∥ T1 |
| T2b | 2 | `programator` | HTTP: `Request::inputList`, `PageNumber` (+ refaktoring `HomeController`), `Flash` (+ `LoginController`), `ArticleController`, trasy, šablony, CSS, rozcestník; dotáhnout vše do GREEN | `make qa` zelené; výstup AC 36 (curl) | po T1 + T2a |
| T3 | 3 | `tester` (režim B) | `make qa`, AC 36–40, Playwright (snímky, klávesnice, konzole), MCP dotazy do `articles` a `audit_log`; informativně `EXPLAIN` dotazu seznamu (filesort je očekávaný) | PASS/FAIL po kritériích; FAIL vrací T2b (persistence → T2a) | po T2b |
| T4 | 3 | `technicky-spisovatel` | kapitola M5 v `docs/tutorial.html`: PRG a flash, validace na serveru s 422, slug z titulku + kolize jedním dotazem, transakce článek + štítky, audit, zápis času z `Clock` (DB v UTC), potvrzení mazání přes POST + CSRF; README: kde je administrace | ověřené příkazy | ∥ T3 |
| T5 | 3 | vedoucí | `docs/plan/STAV.md`: sekce Backlog s M4b (z plánu 004 — zatím ve STAV.md chybí) a M5b (viz Mimo rozsah); CI = plán 006+ | diff | ∥ T3 |
| — | 4 | vedoucí | report → **brána 2** → commity | — | — |

`databazista` se nespouští (schéma beze změny, seed beze změny); v T3 může pomoct s vyhodnocením měření
příkazů (AC 34), pokud by tester narazil na nejasnost. `devops` se nespouští (`compose.yaml`/`Makefile`
beze změny). Security review se v tomto milníku nespouští (výukový režim, STAV.md); rizika jsou jen
vyjmenována níže.

Návrh commitů (každý projde `make up` + `make qa`):
1. `refactor(http): sdílené číslo strany, flash zprávy a seznamy hodnot z formuláře` (AC 14–16; HomeController, LoginController)
2. `feat(clanky): doména a služby správy článků se slugem a auditem` (AC 1–13, 30–35)
3. `feat(admin): administrace článků – seznam, formulář, úprava, smazání` (AC 17–29, 36–39)
4. `docs: plán 005, architektura a kapitola M5` (T4 + T5 + tento plán)

## Rizika a bezpečnost
- **Přístup (A01):** celá `/admin/clanky/*` je za `AdminAccessMiddleware` (prefix) a controller kontroluje
  `AuthSession::user()` znovu. Jediná role → každý admin smí měnit každý článek (dle zadání). Nová admin
  trasa mimo prefix `/admin/` by chráněná nebyla — trasy výše prefix dodržují.
- **CSRF:** všechny tři POST akce chrání `CsrfMiddleware` (běží **před** autorizací → bez tokenu `403`
  i pro nepřihlášeného, AC 17). Mazání nikdy přes GET (AC 26).
- **XSS (A05 Injection):** všechny výpisy přes `e()`/`e_attr()`, včetně vrácených hodnot po chybě (AC 22, 28);
  náhled jen přes `MarkdownRenderer` (ADR-0005). Jméno v „Naposledy upraveno“ je data z DB → escapovat.
- **Hromadné přiřazení / podvržená pole:** use-case bere jen pojmenovaná pole; `created_by`/`updated_by`
  a časy plní server, ne formulář. Neexistující rubrika/štítek → chyba validace, ne `500` z FK.
- **SQL injection:** jen prepared statements; `LIMIT/OFFSET` jako `PARAM_INT`; vícehodnotový `INSERT`
  vazeb skládá jen zástupné `(?, ?)` podle počtu ověřených celých čísel.
- **Souběh:** kolize slugu → `SlugAlreadyTaken` → chyba formuláře (AC 10, 33). Dvě současné úpravy
  téhož článku = „poslední vyhrává“ (bez optimistického zamykání — backlog).
- **Audit mimo transakci (otázka 5):** selže-li zápis auditu po uložení, článek zůstane uložen bez
  záznamu a uživatel uvidí `500`. Přijato pro MVP.
- **Čas:** `created_at`/`updated_at` nových a upravených článků jsou v `Europe/Prague` (z `Clock`), seedované
  články mají výchozí hodnotu DB v UTC → v seznamu se u nich „Naposledy upraveno“ liší o 1–2 h. Totéž platí
  pro `audit_log.created_at` (výchozí DB) — řešit v M8 s výpisem auditu.
- **Řazení seznamu bez indexu** nad `updated_at`: `EXPLAIN` ukáže filesort; pro stovky článků nevadí.
  Kdyby vadilo, nová migrace `idx_articles_updated_at` (backlog).
- **Velikost vstupu / DoS:** text max 100 000 znaků (skill), `post_max_size` PHP (8 MB) je výš; Markdown
  renderer je lineární (AC 10 plánu 004). `maxlength` v HTML je jen nápověda, rozhoduje server.
- **Neplatné UTF-8** ve vstupu → chyba validace (AC 7), do DB se nedostane.
- **Tvrdé mazání** je nevratné; stopa zůstává jen v `audit_log.summary` (titulek + slug). Koš/obnova = backlog.
- **Změna slugu** rozbije staré odkazy na `/clanek/{slug}` (bez přesměrování ze starých slugů — backlog).
- **E2E v dev DB** vytváří data; scénář po sobě smaže druhý článek, první zůstává (přijato, seed ho nepřepíše).
- **Unit testy omylem na DB:** `TestContainer` musí nahradit tři nové repozitáře (§4).
- **LLM rizika:** M5 neobsahuje AI. Od M6 bude LLM navrhovat texty článků — ukládat je smí jen admin přes
  tento formulář (LLM06, human-in-the-loop), výstup modelu projde stejnou validací.

## Mimo rozsah
- **M5b (backlog, zapsat do STAV.md):** CRUD rubrik a štítků v administraci (`/admin/rubriky`, `/admin/stitky`;
  slug z názvu přes `Slug::fromText`, rubriku s články nelze smazat bez přesunu článků — FK `RESTRICT`,
  štítek se maže i s vazbami — `CASCADE`; audit `category.*`, `tag.*`); rozšíří `CategoryRepository`
  a `TagRepository` z tohoto plánu.
- Živý náhled Markdownu (JavaScript), automatické ukládání, historie verzí.
- Filtrování a hledání v seznamu, řazení podle sloupců, hromadné akce.
- Optimistické zamykání, koš / obnovení smazaných článků, přesměrování ze starých slugů.
- Index `idx_articles_updated_at`; výpis audit logu (M8); oprava časové zóny `audit_log.created_at` (M8).
- Obrázky a přílohy (mimo rozsah zadání), AI asistence při psaní (M6+).

## Otázky pro člověka
1. **Pole „slug“ ve formuláři:** prázdné = vygenerovat z titulku; vyplněné se **normalizuje** stejně jako
   titulek (`Můj Slug!` → `muj-slug`) a při kolizi dostane příponu `-2`, `-3`… Při úpravě článku se slug
   sám nemění (změna titulku ho nepřepíše), dokud ho admin ručně nezmění nebo nevymaže. Doporučuji **ano** —
   žádná chybová hláška u slugu, stabilní URL po úpravách a admin má přesto možnost slug upravit.
2. **Publikovat bez data = teď** (čas z `Clock`, zaokrouhlený na celé minuty, aby šel zpět do pole
   `datetime-local`); budoucí datum = naplánovaný článek, v seznamu „Naplánováno“. Doporučuji **ano** —
   navazuje na M4, kde veřejnost budoucí články nevidí.
3. **Stránkování seznamu po 20** (`?strana=N`, vytažení `PageNumber` z `HomeController`, `ArticlePage`
   jako generická třída). Doporučuji **ano** — použije hotový vzor z M4 a je to přesně ten případ „dvě reálná
   použití“, kdy se sdílený kód vyplatí; bez stránkování by seznam s přibývajícími články rostl donekonečna.
4. **Náhled uložené verze textu na stránce úprav** (serverový, bez JavaScriptu) místo živého náhledu.
   Doporučuji **ano** — jeden řádek v controlleru a šabloně, admin vidí vykreslený koncept, který veřejně
   vidět nejde; živý náhled je netriviální (JS + druhá implementace Markdownu nebo endpoint).
5. **Audit zapisovat až po úspěšném uložení, mimo transakci článku.** Doporučuji **ano pro MVP** — sdílená
   transakce přes dva repozitáře by vyžadovala novou abstrakci (např. `TransactionManager` v Application),
   což je v rozporu s „žádná abstrakce bez potřeby“; riziko (článek bez auditu při chybě DB) je zapsané
   a lze ho vyřešit v M8.
6. **Text povinný jen u publikovaného článku**, koncept smí být bez textu. Doporučuji **ano** — odpovídá
   běžné práci redaktora (nejdřív titulek, text později) a veřejnost prázdný článek neuvidí.
7. **Tvrdé mazání** (`DELETE`, štítky kaskádou, v auditu kopie titulku a slugu), bez koše. Doporučuji **ano** —
   tak to předepisuje skill `db-migrace`, schéma se nemění; koš (sloupec `deleted_at`) by znamenal migraci
   a úpravu všech veřejných dotazů.
