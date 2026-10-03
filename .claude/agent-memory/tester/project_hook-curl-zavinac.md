---
name: hook-curl-zavinac
description: PreToolUse hook blokuje každý Bash příkaz, kde je text „curl“ spolu se znakem @ (i v heredoc) – E2E scénáře psát přes Edit/Write
metadata:
  type: project
---

Hook `PreToolUse:Bash` odmítne celý příkaz, pokud obsahuje `curl` a `@` („curl s '@' je zakázán“) — i když jde jen
o text v `cat >> soubor <<EOF` (zjištěno 2026-10-03 při psaní oddílu M5 do `tests/E2E-scenare.md`).

**Why:** hook chrání před `curl -d @soubor` (exfiltrace souborů); kontroluje text příkazu, ne sémantiku.
**How to apply:** úpravy `tests/E2E-scenare.md` dělat nástrojem Edit/Write, ne přes Bash; v curl příkladech psát
zavináč jako `%40`, URL bez uvozovek. V režimu A s paralelním programátorem nespouštět integrační testy
(sdílená `redakce_test`), stačí `--testsuite Unit`, `php -l` a phpstan nad `tests/`.
