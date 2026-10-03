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

### Z5: web servíruje jen public/ (negativní)
1. `curl -s http://localhost:8080/vendor/autoload.php -w '\n%{http_code}\n'` → `404`.
2. `curl -s --path-as-is http://localhost:8080/../composer.json -w '\n%{http_code}\n'` → `404`.
3. `curl -s http://localhost:8080/index.php/zdravi -w '\n%{http_code}\n'` nesmí vrátit zdrojový kód
   ani `500` (očekává se `404`).
4. Tělo žádné odpovědi neobsahuje `<?php`.

### Z6: nepřihlášený / CSRF / role
Netýká se M1 (žádné admin URL ani POST formuláře kromě `/zdravi`). Přibude s M3.
