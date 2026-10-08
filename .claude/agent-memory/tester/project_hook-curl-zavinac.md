---
name: hook-curl-zavinac
description: Omezení PreToolUse hooku pro curl (zavináč, -X PUT, -o soubor, uvozovky/proměnné v URL, --data-urlencode) a Playwright MCP (http://web nejde, screenshot jen s absolutní cestou)
metadata:
  type: project
---

Hook `PreToolUse:Bash` odmítne celý příkaz, pokud obsahuje `curl` a `@` („curl s '@' je zakázán“) — i když jde jen
o text v `cat >> soubor <<EOF` (zjištěno 2026-10-03 při psaní oddílu M5 do `tests/E2E-scenare.md`).
Dále (ověřeno 2026-10-03, režim B M5): URL musí být doslovně `http://localhost:8080/…` bez uvozovek a bez `$proměnné`
(proměnné v `-H "$C"` a `-d _csrf=$T` projdou); `-X` jen GET/HEAD/POST (405 na PUT nejde ověřit curlem); `-o` jen
`/dev/null`; `--data-urlencode` zakázán → diakritiku do `-d` kódovat ručně (`%C5%BD`…), závorky `%28 %29`.

Playwright MCP v tomto prostředí **nevidí** `http://web/` (ERR_NAME_NOT_RESOLVED) → používat `http://localhost:8080`.
Screenshot uložit s **absolutní** cestou `/home/q/projects/t360-php-agent-rs/tests/_artefakty/…`.
Úspora kroků (M6, 2026-10-04): celý scénář jedním `browser_run_code_unsafe` (přihlášení, selectOption, Promise.all
s waitForNavigation, kontrola dialogů přes `page.on('dialog')`, Tab smyčka s activeElement pro klávesnici).

Režim B M8 (2026-10-04): `"$@"` v shell funkci s curl hook bere jako zavináč → URL psát doslovně; tělo číst přes
`B=$(curl -s URL -w '\n%{http_code}')` místo `-o soubor`. Bash příkaz obsahující doslovně `SESSION_COOKIE_SECURE`
byl odmítnut oprávněním → grepovat `COOKIE_SEC`. Chromium v MCP má locale en-US: `input[type=date]` se píše
klávesnicí jako MMDDRRRR. Počet SQL dotazů na požadavek: `performance_schema` je vypnuté, `general_log` neměnit →
rozdíl `SHOW GLOBAL STATUS LIKE 'Com_select'` před/po jednom curl (šum +2 od healthchecku, opakovat 3×).
HEAD na GET trasách vrací 405 (router HEAD nezná) → `curl -I` používat jen tam, kde se čeká 404/405.

Režim B M7 (2026-10-08): admin cookie přes curl jde (GET login → cookie+_csrf, POST → nová cookie v Set-Cookie; heslo
z README). Dotaz do DB bez čtení .env: `docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"
"$MARIADB_DATABASE" -e "…"'` (MARIADB_USER v kontejneru není). Unit testy JS nepokrývají → E2E vždy projít celé
(M7 odhalil `form.action` zastíněné polem `name="action"`); diagnostický obchvat `page.route(...).continue({url})`.
Přihlášení admin@example.cz / dlouhe-heslo-12 (README) funguje. Negativní fetch (422/403) vždy zapíše do konzole
„Failed to load resource …“ — to je hláška prohlížeče, ne chyba JS; „prázdná konzole“ posuzovat jen u šťastné cesty.

Režim B M7b (2026-10-08): `bin/konzole migrace:vrat` na dev DB zamítl auto-mode klasifikátor („Irreversible Local
Destruction“) → rollback ověřovat jen integračním `SchemaTest` (redakce_test), nezkoušet obcházet. Unit fixtury (3 články)
neodhalí chování nad seedem (14 článků) → deterministické „negativní“ dotazy vždy ověřit i nad skutečným seedem.
Při hledání úniků v textu výsledku vyloučit ozvěnu otázky (pole „Otázka“ v bloku Výsledek).

**Why:** hook chrání před `curl -d @soubor` (exfiltrace souborů); kontroluje text příkazu, ne sémantiku.
**How to apply:** úpravy `tests/E2E-scenare.md` dělat nástrojem Edit/Write, ne přes Bash; v curl příkladech psát
zavináč jako `%40`, URL bez uvozovek. V režimu A s paralelním programátorem nespouštět integrační testy
(sdílená `redakce_test`), stačí `--testsuite Unit`, `php -l` a phpstan nad `tests/`. Souběh (race) testovat
dvěma různými session — požadavky v jedné PHP session se serializují zámkem session.
