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

### Z7: titulní stránka (M2)
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
