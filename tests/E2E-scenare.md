# E2E scénáře

Konvence: curl běží z hostitele na `http://localhost:8080`; Playwright (v Dockeru, síť compose)
míří na `http://web`. Snímky se ukládají do `tests/_artefakty/` (ignorováno gitem).
Předpoklad: `make up` proběhl, `AI_PROVIDER=falesny`.

## Zdraví aplikace

Plán: `docs/plan/001-docker-zaklad.md` (AC 10–14).

### Z1: aplikace i databáze jsou v pořádku
1. `curl -s http://localhost:8080/zdravi -w '\n%{http_code}\n'`
2. Očekávání: přesně `{"stav":"ok","db":"ok"}` a na dalším řádku `200`.
3. Playwright: `browser_navigate` na `http://web/zdravi`; snímek stránky obsahuje
   `{"stav":"ok","db":"ok"}`; screenshot `tests/_artefakty/zdravi-ok.png`.
   Soubor patří uživateli hostitele (`stat -c %u` = `id -u`).

### Z2: hlavičky odpovědi
1. `curl -s http://localhost:8080/zdravi -D - -o /dev/null`
2. Očekávání: `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`,
   `X-Content-Type-Options: nosniff`; `Server` bez čísla verze nginx; žádná hlavička `X-Powered-By`.

### Z3: výpadek databáze (negativní)
1. `docker compose stop db`
2. `curl -s -m 5 http://localhost:8080/zdravi -w '\n%{http_code}\n'`
3. Očekávání: do 3 s `{"stav":"chyba","db":"chyba"}` a `503`; tělo neobsahuje `SQLSTATE`,
   název hostitele, uživatele ani heslo; žádný stack trace.
4. `docker compose start db`, počkat na healthy (`docker compose ps`), znovu krok Z1.1 → `200`.
5. Úklid: DB musí být zpět spuštěná.

### Z4: neexistující cesta a špatná metoda (negativní)
1. `curl -s http://localhost:8080/neexistuje -w '\n%{http_code}\n'` → `404`, bez stack trace.
2. `curl -s http://localhost:8080/zdravi -X POST -D - -o /dev/null` → `405` a hlavička `Allow: GET`.
3. Žádná z odpovědí není `500`.
4. (M2) Plán `docs/plan/002-router-di-migrator.md` (AC 19): 404 je HTML stránka s textem
   „Stránka nenalezena“ a odkazem na `/`; 405 obsahuje „Metoda není povolena“; tělo bez
   `Stack trace` a `.php`.

### Z5: web servíruje jen public/ (negativní)
1. `curl -s http://localhost:8080/vendor/autoload.php -w '\n%{http_code}\n'` → `404`.
2. `curl -s --path-as-is http://localhost:8080/../composer.json -w '\n%{http_code}\n'` → `404`.
3. `curl -s http://localhost:8080/index.php/zdravi -w '\n%{http_code}\n'` nesmí vrátit zdrojový kód
   ani `500` (očekává se `404`).
4. Tělo žádné odpovědi neobsahuje `<?php`.

### Z7: titulní stránka (M2) – NAHRAZENO scénáři V1–V7 (M4)
Text „Články přibudou v dalším milníku.“ už neplatí; titulní stránku ověřují scénáře „Veřejná část (M4)“ níže.
Plán: `docs/plan/002-router-di-migrator.md` (AC 18, 20).
1. `curl -s http://localhost:8080/ -D -` → `200`, `Content-Type: text/html; charset=utf-8`,
   tělo obsahuje `<html lang="cs">` a nadpis „Redakční systém“.
2. Playwright: `browser_navigate` na `http://web/`; snímek obsahuje nadpis „Redakční systém“
   a text „Články přibudou v dalším milníku.“; screenshot `tests/_artefakty/titulni-m2.png`.
3. Playwright: `http://web/neexistuje` → stránka „Stránka nenalezena“, odkaz „Zpět na titulní
   stránku“ vede na `/`; screenshot `tests/_artefakty/404-m2.png`.
4. Regrese: `curl -s http://localhost:8080/zdravi -w '\n%{http_code}\n'` → `{"stav":"ok","db":"ok"}` a `200`.

### Z8: migrace a konzole (M2)
Plán: `docs/plan/002-router-di-migrator.md` (AC 25, 26).
1. `docker compose exec -T app php bin/konzole; echo $?` → seznam `migrace:spust`, `migrace:vrat`,
   `migrace:stav`, kód `0`.
2. `docker compose exec -T app php bin/konzole neznamy:prikaz; echo $?` → chyba na stderr, kód `1`.

## Přihlášení a odhlášení admina (M3)

Plán: `docs/plan/003-prihlaseni-admin.md` (AC 21–24). Předpoklad: `make up`, `make migrate`, admin vytvořený
`docker compose exec -T app php bin/konzole admin:vytvor --email=admin@example.cz --jmeno=Administrátor --heslo=<heslo-min-12-znaku>`.
Cookie se předává ručně hlavičkou `-H 'Cookie: redakce_session=…'` (bez cookie jar).

### P1: nepřihlášený nemá přístup do administrace
1. `curl -s http://localhost:8080/admin -D - -o /dev/null` → `303`, `Location: /admin/prihlaseni`.
2. `curl -s http://localhost:8080/admin/cokoliv -D - -o /dev/null` → `303` na přihlášení (nikdy `200` ani `500`).
3. `curl -s http://localhost:8080/adminx -D - -o /dev/null` → `404` (prefix po segmentech, není přesměrováno).
4. `curl -s http://localhost:8080/admin/odhlaseni -D - -o /dev/null` (GET) → `303` na přihlášení nebo `405`, ne `200`.

### P2: přihlašovací stránka, cookie a hlavičky
1. `curl -s http://localhost:8080/admin/prihlaseni -D -` → `200`; `Set-Cookie: redakce_session=…; path=/; HttpOnly; SameSite=Strict`.
2. Hlavičky: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`,
   `Permissions-Policy: camera=(), microphone=(), geolocation=()`, `Cross-Origin-Opener-Policy: same-origin`,
   `Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'`.
3. Tělo: nadpis „Přihlášení do administrace“, `name="_csrf" value="…"` (64 hex znaků), pole `email` a `password`.
4. Zapsat si cookie a token pro P3.

### P3: CSRF a přihlášení přes HTTP (negativní i pozitivní)
1. `curl -s http://localhost:8080/admin/prihlaseni -X POST -d 'email=admin@example.cz&password=x' -D - -o /dev/null` (bez cookie a tokenu) → `403`.
2. Totéž s cookie z P2, ale bez `_csrf` → `403`; s cookie a špatným `_csrf=abc` → `403`. Tělo 403 obsahuje „Neplatný formulář“, nikoli token ani stack trace.
3. S cookie, platným `_csrf` a špatným heslem → `422`, v těle „Neplatné přihlašovací údaje.“; stejná odpověď pro neexistující e-mail (nelze rozlišit účty).
4. S cookie, platným `_csrf` a správnými údaji → `303 Location: /admin` a nová `Set-Cookie: redakce_session=…` (jiná hodnota než před přihlášením).
5. `curl -s http://localhost:8080/admin -H 'Cookie: redakce_session=<nová>' -i` → `200`, „Přihlášen(a) jako“; se starou cookie → `303` na přihlášení (fixace session).
6. POST s `email=%22%3E%3Cb%3Ex&password=x&_csrf=<token>` a cookie → `422`, tělo neobsahuje nezakódované `"><b>x`.
7. POST s heslem delším než 4096 znaků → `422`, ne `500`.
8. `POST /admin/odhlaseni` s cookie, ale bez `_csrf` → `403`, admin zůstává přihlášen (`GET /admin` → `200`).

### P4: přihlášení a odhlášení v prohlížeči
1. Playwright: `browser_navigate` na `http://web/admin` → skončí na `/admin/prihlaseni` (nadpis „Přihlášení do administrace“).
2. Vyplnit e-mail a heslo admina, kliknout „Přihlásit se“ → URL `/admin`, text „Přihlášen(a) jako Administrátor“,
   tlačítko „Odhlásit se“; screenshot `tests/_artefakty/admin-m3.png`.
3. Špatné heslo → zůstane formulář s „Neplatné přihlašovací údaje.“, e-mail předvyplněný, pole hesla prázdné.
4. Kliknout „Odhlásit se“ → `/admin/prihlaseni` s „Byli jste odhlášeni.“; obnovení stránky hlášku už nezobrazí;
   zpět (`browser_navigate_back`) a `/admin` znovu přesměruje na přihlášení.
5. MCP dotaz (`redakce_cteni`): `SELECT action, user_id, ip_address FROM audit_log ORDER BY id DESC LIMIT 10`
   obsahuje `auth.login`, `auth.login_failed` a `auth.logout`.
6. Konzole prohlížeče: žádná chyba CSP (`browser_console_messages` level `error`).

### P5: příkaz admin:vytvor (dev DB)
1. `docker compose exec -T app php bin/konzole admin:vytvor --email=admin@example.cz --jmeno=Administrátor --heslo=dlouhe-heslo-12; echo $?`
   → „Vytvořen administrátor admin@example.cz (ID …).“, kód `0`.
2. Totéž znovu → hláška „už existuje“ na stderr, kód `1`.
3. Bez `--heslo` (jiný e-mail) → vypíše „Vygenerované heslo: …“ (≥ 20 znaků), kód `0`.
4. Bez `--email` → nápověda, kód `1`.
5. MCP: `SELECT LEFT(password_hash, 10) FROM users` → `$argon2id$` u všech řádků.

### P6: regrese M2
1. `curl -s http://localhost:8080/ -D - -o /dev/null` → `200` a **bez** `Set-Cookie` (session se zakládá líně).
2. `curl -s http://localhost:8080/zdravi -w '\n%{http_code}\n'` → `{"stav":"ok","db":"ok"}` a `200`.
3. `curl -s http://localhost:8080/zdravi -X POST -D - -o /dev/null` → `405` s `Allow: GET` (ne `403`).
4. `curl -s http://localhost:8080/neexistuje -X POST -D - -o /dev/null` → `404` (ne `403`).
3. Čistá dev DB: `make migrate` → „Spuštěno: …“ pro každou migraci; znovu `make migrate` →
   „Žádné čekající migrace.“; `docker compose exec -T app php bin/konzole migrace:stav` → všechny
   řádky `[x] … (čas)`. Všechny tři kroky končí kódem `0`.
4. Negativní: `migrace:vrat --kroky=0` → chyba, kód `1` (N musí být ≥ 1).

### Z6: nepřihlášený / CSRF / role
Netýká se M1 (žádné admin URL ani POST formuláře kromě `/zdravi`). Přibude s M3.

## Veřejná část (M4)

Plán: `docs/plan/004-verejna-cast.md` (AC 24–30). Předpoklad: `make up`, `make migrate`, `make seed`
(ukázková data: 12 publikovaných článků, 2 koncepty, 1 archivovaný, 1 naplánovaný na rok 2099).
Veřejná část je jen čtení (GET), žádný formulář.

### V1: ukázková data (`make seed`)
1. `make seed; echo $?` → „Nově vloženo – rubriky: 3, štítky: 5, články: 16.“, kód `0`.
2. Znovu `make seed; echo $?` → „Ukázková data už jsou v databázi, nic nového se nevložilo.“, kód `0`.
3. MCP (`redakce_cteni`): `SELECT status, COUNT(*) FROM articles GROUP BY status` → `draft 2`, `published 13`, `archived 1`.
4. Negativní: `docker compose exec -T -e APP_ENV=prod app php bin/konzole db:seed; echo $?` → na stderr
   „Ukázková data lze nahrát jen ve vývojovém nebo testovacím prostředí (APP_ENV=dev|test).“, kód `1`, v DB se nic nezměnilo.
5. Negativní: `docker compose exec -T app php bin/konzole db:seed --neznamy; echo $?` → nápověda, kód `1`.

### V2: titulní stránka přes curl
1. `curl -s http://localhost:8080/ -D -` → `200`, `Content-Type: text/html; charset=utf-8`, bezpečnostní hlavičky
   jako v Z2, **bez** `Set-Cookie`; tělo obsahuje `/clanek/ukazka-markdownu` a `/?strana=2`, nadpis
   „Nejnovější články“, přesně 10 značek `<article`.
2. `curl -s 'http://localhost:8080/?strana=2' -o /dev/null -w '%{http_code}'` → `200`; `?strana=1` → `200`.
3. Negativní: `?strana=3`, `?strana=0`, `?strana=-1`, `?strana=abc`, `?strana=01`, `?strana=9999999` → `404`
   (nikdy `500`), tělo „Stránka nenalezena“, bez `Stack trace` a `SQLSTATE`.
4. `curl -s 'http://localhost:8080/?strana=2'` → odkaz `href="/" rel="prev"`, text „Strana 2 z 2“, bez „Starší články“.
5. Tělo titulní stránky neobsahuje články `rozepsany-koncept`, `druhy-koncept`, `archivni-clanek`, `planovany-clanek`.

### V3: detail článku přes curl
1. `curl -s http://localhost:8080/clanek/ukazka-markdownu -o /dev/null -w '%{http_code}'` → `200`.
2. Tělo detailu obsahuje `&lt;script&gt;` (útok z ukázky vykreslený jako text), neobsahuje `<script` ani `javascript:`
   v atributu `href`; obsahuje `<div class="article-body">`, `<h2>`, `<pre><code>`, „Rubrika: Technologie“, štítky „Bezpečnost“, „PHP“.
3. Negativní, vždy `404` a totéž tělo jako `/neexistuje`: `/clanek/rozepsany-koncept`, `/clanek/druhy-koncept`,
   `/clanek/archivni-clanek`, `/clanek/planovany-clanek`, `/clanek/neexistuje`, `/clanek/Ukazka-Markdownu`
   (velikost písmen), `/clanek/ukazka-markdownu%20` (mezera na konci), `/clanek/%C4%8Dl%C3%A1nek`, `/clanek/`, `/clanek/a/b`.
4. `curl -s -X POST http://localhost:8080/clanek/ukazka-markdownu -D - -o /dev/null` → `405` a `Allow: GET`
   (ne `403`; chování stejné jako u `POST /zdravi`, viz P6).
5. `curl -s 'http://localhost:8080/clanek/ukazka-markdownu?strana=99' -o /dev/null -w '%{http_code}'` → `200` (query detail neovlivní).

### V4: titulní stránka a detail v prohlížeči
1. Playwright: `browser_navigate` na `http://web/` → snímek `tests/_artefakty/titulni-m4.png`; 10 článků
   (titulek jako odkaz, datum česky např. „12. září 2026“, rubrika, perex), dole navigace „Stránkování“ s „Starší články“ a „Strana 1 z 2“.
2. Kliknout „Starší články“ → URL `/?strana=2`, 2 články, „Strana 2 z 2“, titulek stránky obsahuje „Strana 2“.
3. Kliknout „Novější články“ → URL `/` (ne `/?strana=1`), znovu 10 článků.
4. Kliknout titulek „Ukázka Markdownu“ → URL `/clanek/ukazka-markdownu`; snímek `tests/_artefakty/clanek-m4.png`.
   Vidět: vykreslený podnadpis, odrážky, číslovaný seznam, citace, blok kódu, tučné/kurzíva, odkaz na php.net,
   `<script>alert("xss")</script>` jako viditelný text (žádný alert dialog), „[nebezpečný odkaz](javascript:alert(1))“ jako text bez odkazu.
5. Seznam štítků „Bezpečnost“, „PHP“ a odkaz „Zpět na titulní stránku“ vede na `/`.
6. `browser_console_messages` (level `error`) je prázdné – žádná chyba CSP.

### V5: dostupnost a klávesnice
1. Na `http://web/` stisknout `Tab` → první zaostřený prvek je odkaz „Přejít na obsah“ (viditelný při zaostření);
   `Enter` přesune fokus na hlavní obsah (`#obsah`); další `Tab` zaostří první titulek článku.
2. Zaostřený prvek má viditelný obrys (`:focus-visible`).
3. Bez myši projít stránkování a otevřít detail (Tab + Enter).
4. Zúžit okno na 375 px (`browser_resize`): obsah se nezlomí do vodorovného posuvníku stránky, dlouhé `pre` se posouvá uvnitř bloku.

### V6: neexistující a skrytý obsah v prohlížeči (negativní)
1. Playwright: `http://web/clanek/rozepsany-koncept` a `http://web/clanek/archivni-clanek` → stránka „Stránka nenalezena“
   s odkazem na `/`, stejná jako `http://web/neexistuje`; screenshot `tests/_artefakty/404-clanek-m4.png`.
2. `http://web/?strana=3` → „Stránka nenalezena“.

### V7: výkon, index a regrese
1. Po zahřátí (dvě volání navíc): `curl -s http://localhost:8080/ -o /dev/null -w '%{time_total}'` → < 0,1 s (orientačně).
2. MCP (`redakce_cteni`): `EXPLAIN SELECT a.id FROM articles a JOIN categories c ON c.id = a.category_id WHERE a.status = 'published' AND a.published_at IS NOT NULL AND a.published_at <= NOW() ORDER BY a.published_at DESC, a.id DESC LIMIT 10`
   → `possible_keys` obsahuje `idx_articles_status_published_at` (u 16 řádků může optimalizátor zvolit plný průchod – informativní, ne FAIL).
3. Regrese: `/zdravi` → `200 {"stav":"ok","db":"ok"}`; `POST /zdravi` → `405`; `/admin` → `303` na `/admin/prihlaseni`;
   přihlášení admina (P3/P4) funguje; titulní stránka a detail nenastavují `Set-Cookie` (session se zakládá líně).

## Administrace článků (M5)

Plán: `docs/plan/005-sprava-clanku.md` (AC 36–39). Předpoklad: `make up`, `make migrate`, `make seed`,
admin „Administrátor“ z P5 (`admin@example.cz`). Pravidla hooku pro curl: URL bez uvozovek, zavináč v `-d` jako `%40`,
cookie jen přes `-H 'Cookie: redakce_session=…'`. Playwright běží v síti compose → `http://web/`.
Snímky do `tests/_artefakty/`.

### A1: nepřihlášený a POST bez tokenu (negativní, curl)
1. MCP (`redakce_cteni`): `SELECT COUNT(*) FROM articles` → zapsat si počet N.
2. `curl -s http://localhost:8080/admin/clanky -D - -o /dev/null` → `303`, `Location: /admin/prihlaseni`.
3. Totéž pro `/admin/clanky/novy`, `/admin/clanky/1/upravit`, `/admin/clanky/1/smazat` → vždy `303` na přihlášení (nikdy `200`/`500`).
4. `curl -s -X POST http://localhost:8080/admin/clanky/1/smazat -o /dev/null -w '%{http_code}'` (bez cookie a tokenu) → `403`.
5. `curl -s -X POST http://localhost:8080/admin/clanky/novy -d title=X -o /dev/null -w '%{http_code}'` → `403`.
6. Metoda PUT na `/admin/clanky/1/upravit` → `405`, `Allow: GET, POST`. Hook `curl -X PUT` zakazuje, proto je to ověřeno unit testem (AC 27), v E2E se krok přeskakuje.
7. MCP: `SELECT COUNT(*) FROM articles` → stále N (nic se nesmazalo ani nevytvořilo).

### A2: přihlášený přes curl (CSRF, 404 a 422; negativní)
1. Přihlásit se podle P2–P3 (cookie `redakce_session` a `_csrf` z přihlašovací stránky; e-mail v `-d` jako `admin%40example.cz`).
2. `curl -s http://localhost:8080/admin/clanky -H 'Cookie: redakce_session=<cookie>' -o /dev/null -w '%{http_code}'` → `200`.
3. `curl -s -X POST http://localhost:8080/admin/clanky/1/smazat -H 'Cookie: redakce_session=<cookie>' -o /dev/null -w '%{http_code}'`
   (bez `_csrf`) → `403`; s `-d _csrf=abc` → `403`; počet článků (MCP) beze změny.
4. S cookie: `/admin/clanky/999999/upravit`, `/admin/clanky/0/upravit`, `/admin/clanky/abc/upravit`, `/admin/clanky/05/upravit`,
   `/admin/clanky/1234567890123456789/upravit` → `404` „Stránka nenalezena“ (nikdy `500`, bez `SQLSTATE` a `Stack trace`).
5. S cookie: `/admin/clanky?strana=99`, `?strana=0`, `?strana=abc` → `404`.
6. S cookie a platným tokenem: `-d title= -d category_id= -d _csrf=<token>` na `/admin/clanky/novy` → `422`, tělo obsahuje
   „Vyplňte titulek.“ a „Vyberte rubriku.“, nic se neuložilo.

### A3: seznam a chyby formuláře v prohlížeči
1. Playwright: `browser_navigate` na `http://web/admin` → přihlásit se jako admin → rozcestník má odkaz „Články“
   (věta „Správa článků přibude v dalším milníku.“ už není).
2. Kliknout „Články“ → URL `/admin/clanky`, nadpis „Články“, tabulka se seedovanými články včetně konceptů, archivu
   a „Naplánováno“ u článku z roku 2099; sloupce stav / rubrika / datum publikace / „Naposledy upraveno“ (u seedu „neuvedeno“);
   snímek `tests/_artefakty/admin-clanky-m5.png`.
3. Kliknout „Nový článek“ → URL `/admin/clanky/novy`, formulář s poli Titulek, Adresa (slug) s nápovědou
   „Nechte prázdné – vytvoří se z titulku.“, Perex, Text, Rubrika („— vyberte rubriku —“), Štítky (zaškrtávátka),
   Stav (Koncept vybraný), Datum publikace.
4. Odeslat prázdný formulář (validaci prohlížeče u `required` obejít přes `browser_evaluate`
   `() => { document.querySelector('form[action="/admin/clanky/novy"]').noValidate = true; }`) → HTTP `422`
   (ověřit v `browser_network_requests`), souhrn „Článek se nepodařilo uložit, opravte prosím chyby ve formuláři.“,
   u polí „Vyplňte titulek.“ a „Vyberte rubriku.“; pole titulku má `aria-invalid="true"`.

### A4: vytvoření článku a zobrazení na webu
1. Vyplnit titulek „Můj první článek z administrace“, perex „Perex z E2E.“, text `**Tučně** a <script>alert(1)</script>`,
   rubriku Technologie, zaškrtnout štítky PHP a Docker, stav Publikováno, datum prázdné → „Uložit článek“.
2. Očekávání: URL `/admin/clanky/{id}/upravit`, hláška „Článek byl vytvořen.“, pole slug = `muj-prvni-clanek-z-administrace`,
   „Naposledy upraveno … (Administrátor)“, sekce „Náhled uloženého textu“ s tučným „Tučně“ a viditelným textem
   `<script>alert(1)</script>` (žádný dialog), odkaz „Zobrazit na webu“; snímek `tests/_artefakty/admin-clanek-m5.png`.
3. Obnovit stránku (`browser_navigate` na stejnou URL) → hláška „Článek byl vytvořen.“ už není (flash jen jednou).
4. `http://web/` → článek „Můj první článek z administrace“ je první v seznamu.
5. `http://web/clanek/muj-prvni-clanek-z-administrace` → detail se štítky „Docker“, „PHP“, `<script>` jen jako text.

### A5: úprava, kolize slugu a smazání
1. Na stránce úprav prvního článku změnit titulek na „Můj první článek z administrace (upraveno)“ → „Uložit článek“
   → „Změny byly uloženy.“, slug zůstal `muj-prvni-clanek-z-administrace`.
2. „Články“ → „Nový článek“ → titulek „Můj první článek z administrace“ (původní), rubrika Zprávy, stav Koncept → uložit
   → slug `muj-prvni-clanek-z-administrace-2`, odkaz „Zobrazit na webu“ chybí (koncept).
3. U druhého článku „Smazat článek“ → URL `/admin/clanky/{id}/smazat`, text „Opravdu smazat článek „Můj první článek
   z administrace“? Akci nelze vrátit.“, odkaz „Zrušit“ vede zpět na úpravu; samotné otevření stránky nic nesmaže.
4. Potvrdit „Smazat článek“ → URL `/admin/clanky`, hláška „Článek „Můj první článek z administrace“ byl smazán.“,
   druhý článek v seznamu není, první ano.
5. MCP (`redakce_cteni`): `SELECT action, entity_type, entity_id, summary FROM audit_log ORDER BY id DESC LIMIT 4`
   → shora `article.deleted` (`… [muj-prvni-clanek-z-administrace-2]`), `article.created` (`…-2`),
   `article.updated` (`Můj první článek z administrace (upraveno) [muj-prvni-clanek-z-administrace]`), `article.created`;
   `entity_type = article`, `entity_id` odpovídá ID článků.
6. MCP: `SELECT created_by, updated_by, created_at, updated_at FROM articles WHERE slug = 'muj-prvni-clanek-z-administrace'`
   → `created_by = updated_by` = ID admina, časy v pražském čase (ne UTC); `SELECT COUNT(*) FROM article_tags t
   JOIN articles a ON a.id = t.article_id WHERE a.slug = 'muj-prvni-clanek-z-administrace'` → `2`.
7. `browser_console_messages` (level `error`) prázdné – žádná chyba CSP.

### A6: klávesnice a dostupnost
1. Na `/admin/clanky/novy` projít formulář jen klávesnicí: `Tab` mezi poli v pořadí titulek → slug → perex → text →
   rubrika → štítky → stav → datum → „Uložit článek“; mezerník zaškrtne štítek; šipky vyberou volbu v `select`; `Enter` odešle.
2. Každé pole má viditelný popisek (kliknutí na `<label>` zaostří pole); zaostřený prvek má viditelný obrys.
3. Po chybě (A3 krok 4) je souhrn v `role="alert"` a pole s chybou odkazuje na hlášku (`aria-describedby`).
4. `browser_resize` na 375 px: tabulka seznamu se posouvá uvnitř svého obalu, stránka nemá vodorovný posuvník.

### A7: regrese
1. Plán 004 V2–V3 a V7: titulní stránka, detail, `/zdravi` → `200 {"stav":"ok","db":"ok"}`, přihlášení (P3/P4) dál funguje.
2. První článek z A4 zůstává v dev DB (`make seed` ho nepřepíše, seed hlásí „nic nového se nevložilo“); druhý smazal A5.
3. Po odhlášení se hláška „Byli jste odhlášeni.“ zobrazí právě jednou (flash sdílený přes `Flash`).

## AI jádro a příklady 01–05 (M6)

Plán: `docs/plan/006-ai-jadro.md` (AC 30–33). Předpoklad: `make up`, `make migrate`, `make seed`, admin z P5,
`AI_PROVIDER=falesny` (bez klíče – nic se neúčtuje, ceny jsou orientační). Pravidla hooku pro curl: URL bez uvozovek
a bez proměnných, zavináč v `-d` jako `%40`, `-o` jen `/dev/null`, cookie jen přes `-H 'Cookie: redakce_session=…'`,
`-X PUT` nejde (405 ověřuje unit test `AdminAiTest`). Playwright MCP: `http://web/` (v prostředí, kde jméno `web`
nejde přeložit, `http://localhost:8080/`). Snímky s absolutní cestou do `tests/_artefakty/`.

### I1: prostředí a konzole (curl, docker)
1. `docker compose exec app php -m` → seznam obsahuje `curl`.
2. `docker compose exec app printenv AI_PROVIDER AI_MODEL AI_MODEL_LEVNY AI_DENNI_LIMIT_TOKENU` → `falesny`,
   `claude-sonnet-5-5`, `claude-haiku-4-5-20251001`, `200000` (hodnotu `ANTHROPIC_API_KEY` **nevypisovat**).
3. `docker compose exec app php bin/konzole ai:priklad 01` → kód `0`; výstup obsahuje
   „Příklad 01 – Perex na jedno kliknutí (článek: Ukázkový článek)“, řádek `Perex: …` a řádek
   „Model claude-sonnet-5-5 · poskytovatel … · volání 1 · tokeny vstup N / výstup M · cena X USD“.
4. `docker compose exec app php bin/konzole ai:priklad 04 --clanek=demo-injection` → kód `0`, výstup obsahuje „prompt injection“.
5. `docker compose exec app php bin/konzole ai:priklad 05 --model=claude-haiku-4-5-20251001` → řádek „Model claude-haiku-4-5-20251001 · …“.
6. Negativní: `ai:priklad`, `ai:priklad 06`, `ai:priklad 1`, `ai:priklad 01 --neco=1` → kód `1` a
   „Použití: php bin/konzole ai:priklad 01–05 [--clanek=demo|demo-injection|ID] [--model=ID]“;
   `ai:priklad 01 --clanek=999999` → kód `1`, „Článek 999999 neexistuje.“ (nikdy PHP warning ani stack trace).
7. MCP (`redakce_cteni`): `SELECT example_id, user_id, provider FROM ai_calls ORDER BY id DESC LIMIT 3` → `05`, `04`, `01`,
   `user_id` = `NULL` (volání z konzole), `provider` = `fake`.

### I2: nepřihlášený, CSRF a chybné adresy (negativní, curl)
1. MCP: `SELECT COUNT(*) FROM ai_calls` → zapsat si počet N.
2. `curl -s http://localhost:8080/admin/ai -D - -o /dev/null` → `303`, `Location: /admin/prihlaseni`; totéž `/admin/ai/01`.
3. `curl -s -X POST http://localhost:8080/admin/ai/01 -o /dev/null -w '%{http_code}'` → `403` (bez cookie a tokenu).
4. `curl -s -X POST http://localhost:8080/admin/ai/01 -d article=demo -o /dev/null -w '%{http_code}'` → `403`.
5. Přihlásit se podle P2–P3 (e-mail v `-d` jako `admin%40example.cz`), cookie si zapsat. S cookie:
   `curl -s -X POST http://localhost:8080/admin/ai/01 -H 'Cookie: redakce_session=<cookie>' -d article=demo -o /dev/null -w '%{http_code}'`
   (bez `_csrf`) → `403`; s `-d _csrf=abc` → `403`.
6. S cookie: `/admin/ai/00`, `/admin/ai/06`, `/admin/ai/1`, `/admin/ai/abc` → `404` „Stránka nenalezena“ (nikdy `500`).
7. S cookie a platným `_csrf` (z formuláře `/admin/ai/01`): `-d article=abc` → `422`, tělo obsahuje „Vyberte článek.“;
   na `/admin/ai/05` `-d article=demo -d model=gpt-4o` → `422`, „Vyberte model ze seznamu.“
8. MCP: `SELECT COUNT(*) FROM ai_calls` → stále N (žádné z výše uvedených volání AI nespustilo).
9. Tělo žádné odpovědi neobsahuje `SQLSTATE`, `Stack trace` ani `sk-ant-`.

### I3: přehled a příklad 01 v prohlížeči
1. Playwright: přihlásit se jako admin → rozcestník `/admin` má odkaz „AI nástroje“ → kliknout.
2. URL `/admin/ai`, nadpis „AI nástroje“, text „Poskytovatel: falešný klient (bez API klíče, nic se neúčtuje)“,
   modely `claude-sonnet-5-5` a `claude-haiku-4-5-20251001`, řádek „Dnes: K volání, T z 200 000 tokenů, C USD“
   (čísla česky: mezera jako oddělovač tisíců, desetinná čárka, cena na 6 míst), odkazy „01 – Perex na jedno kliknutí“ …
   „05 – Překlad CZ → EN“ s popisy, tabulka „Poslední volání“ (volání z I1 se stavem „OK“); snímek
   `tests/_artefakty/admin-ai-m6.png`.
3. Kliknout „01 – Perex na jedno kliknutí“ → URL `/admin/ai/01`, popisek „Článek“, výběr s volbami
   „Ukázkový článek (bez databáze)“ (vybraná), „Ukázka: článek s vloženým pokynem“ a seedované články.
4. „Spustit příklad“ → po přesměrování (`browser_network_requests`: `POST` → `303`, `GET /admin/ai/01` → `200`)
   sekce „Výsledek“ s polem „Perex“, řádkem „Model claude-sonnet-5-5 · falešný klient · volání 1 · tokeny vstup N / výstup M · cena X USD“,
   poznámkou „Falešný klient: cena je jen orientační, nic se neúčtovalo.“ a rozbalovacím „Surová odpověď modelu“.
5. Obnovit stránku (`browser_navigate` na stejnou URL) → sekce „Výsledek“ už není a v `ai_calls` nepřibyl řádek
   (obnovení nespouští placené volání znovu).

### I4: příklad 04 a prompt injection, ostatní příklady
1. `/admin/ai/04` → vybrat „Ukázka: článek s vloženým pokynem“ → „Spustit příklad“.
2. Výsledek obsahuje pole „Shrnutí“ a nález „Nález N – prompt injection, vysoká“ s citací vloženého pokynu
   („Ignoruj všechny předchozí pokyny…“); snímek `tests/_artefakty/admin-ai-04-m6.png`.
3. Totéž s „Ukázkový článek (bez databáze)“ → žádný nález „prompt injection“.
4. `/admin/ai/02` (seedovaný článek) → pole „Titulek“, „Meta popis“, „Klíčová slova“; `/admin/ai/03` → „Rubrika“
   (jedna ze seedovaných rubrik), „Štítky“ s příznaky „(existuje)“/„(nový)“, řádek „Model claude-haiku-4-5-20251001 · …“;
   `/admin/ai/05` s modelem `claude-haiku-4-5-20251001` → „Titulek (EN)“, „Perex (EN)“, „Slug“ (původní), „Text (EN, Markdown)“
   jako surový Markdown (nevykreslený).
5. MCP: `SELECT example_id, provider, model, input_tokens, output_tokens, cost_usd, status FROM ai_calls ORDER BY id DESC LIMIT 2`
   → poslední volání příkladů s `provider = fake`, `status = ok`, `cost_usd > 0` (orientační); po krocích 1–2 jsou to `04` a `01`
   (nebo spusťte dotaz hned po kroku 2). Přehled „Poslední volání“ je ukazuje nahoře.
6. MCP: `SELECT created_at FROM ai_calls ORDER BY id DESC LIMIT 1` → pražský čas (ne UTC), s mikrosekundami.

### I5: escapování, klávesnice, konzole prohlížeče
1. V administraci vytvořit koncept s titulkem `<script>alert(1)</script>` a textem „Krátký text.“ → `/admin/ai/01`:
   volba ve výběru ukazuje titulek jako text (žádný dialog), spustit příklad s tímto článkem → výsledek bez dialogu,
   surová odpověď v `<pre>` jako text. Koncept pak smazat (A5 krok 3–4).
2. Celý tok I3 projít jen klávesnicí: `Tab` na „AI nástroje“, `Enter`, `Tab` na odkaz příkladu, `Enter`, šipky ve výběru
   článku, `Tab` na „Spustit příklad“, `Enter`; „Surová odpověď modelu“ jde rozbalit `Enter`/mezerníkem; zaostření je vidět.
3. Chybová hláška (I2 krok 7 v prohlížeči: vybrat článek, přes `browser_evaluate` změnit hodnotu volby na `abc`
   a odeslat) je v `role="alert"`; formulář zůstane s výběrem.
4. `browser_console_messages` (level `error`) prázdné – žádná chyba CSP.
5. `browser_resize` na 375 px: tabulka „Poslední volání“ a `<pre>` se surovou odpovědí se zalamují/posouvají uvnitř
   svého obalu, stránka nemá vodorovný posuvník.

### I6: limit (volitelné, dev DB)
1. Dočasně `AI_DENNI_LIMIT_TOKENU=1000` jen pro jeden běh: `docker compose exec -e AI_DENNI_LIMIT_TOKENU=1000 app php bin/konzole ai:priklad 04`
   (příklad 04 si rezervuje 1 500 tokenů) → kód `1`, hláška „Denní limit AI tokenů (1 000) by byl překročen: dnes použito …,
   požadavek si rezervuje až 1 500. Zkuste to zítra nebo zvyšte AI_DENNI_LIMIT_TOKENU.“, v `ai_calls` nepřibyl řádek.
   Web (429) ověřuje unit test `AdminAiTest`.

### I7: živé API (jen člověk s klíčem, ne CI)
1. Do `.env` doplnit `AI_PROVIDER=anthropic` a `ANTHROPIC_API_KEY=…` (agent `.env` nečte ani nemění), `make up`.
2. `/admin/ai` ukazuje „Poskytovatel: Claude API (Anthropic)“; příklady 01 a 02 v prohlížeči vrátí odpověď,
   MCP `SELECT provider, cost_usd, request_id FROM ai_calls ORDER BY id DESC LIMIT 2` → `anthropic`, `cost_usd > 0`,
   `request_id` začíná `req_`.
3. `docker compose exec app vendor/bin/phpunit --group live` → `AnthropicLiveTest` projde (bez klíče je „skipped“);
   `make test` skupinu `live` nespouští.
4. Nastavit špatný klíč → příklad 01 vrátí stránku `502` s „AI odmítla API klíč (401). Zkontrolujte ANTHROPIC_API_KEY v .env.“,
   klíč se na stránce ani v `docker compose logs app` neobjeví. Po testu vrátit `AI_PROVIDER=falesny`.

### I8: kvalita a regrese (AC 33)
1. `make qa` → kód 0 (unit testy `AiSourceRulesTest` hlídají: `curl_` jen v `CurlHttpTransport`, `api.anthropic.com` jen
   v `AnthropicClient`, čtení `ANTHROPIC_API_KEY` jen v `AiConfig`, v `src/Ai` žádné `temperature`/`tool_choice`/`eval`/`exec`).
2. Ruční grep: šablony `templates/admin/ai/*.php` vypisují výstup modelu jen přes `e()`, žádný `MarkdownRenderer`;
   každý `<form method="post">` má `csrf_field`.
3. Regrese M5 (A7) a `/zdravi` → `200`.
