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

**Why:** hook chrání před `curl -d @soubor` (exfiltrace souborů); kontroluje text příkazu, ne sémantiku.
**How to apply:** úpravy `tests/E2E-scenare.md` dělat nástrojem Edit/Write, ne přes Bash; v curl příkladech psát
zavináč jako `%40`, URL bez uvozovek. V režimu A s paralelním programátorem nespouštět integrační testy
(sdílená `redakce_test`), stačí `--testsuite Unit`, `php -l` a phpstan nad `tests/`. Souběh (race) testovat
dvěma různými session — požadavky v jedné PHP session se serializují zámkem session.
