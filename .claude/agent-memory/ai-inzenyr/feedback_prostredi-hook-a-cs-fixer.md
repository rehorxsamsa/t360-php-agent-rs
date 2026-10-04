---
name: feedback-prostredi-hook-a-cs-fixer
description: Praktické pasti prostředí t360 pro ai-inženýra — hook blokuje Bash s textem „php“ v heredocu, php-cs-fixer a nowdoc, spuštění ad-hoc PHP skriptu
metadata:
  type: feedback
---

- Bash hook (pravidlo 0) odmítne celý příkaz, jehož text (i heredoc se zdrojákem nebo ```php) vypadá jako spuštění php na hostiteli.
  **How to apply:** soubory se zdrojákem tvoř nástrojem Write; ad-hoc PHP skript ulož do scratchpadu a spusť
  `docker compose exec -T app php < /cesta/skript.php` (stdin funguje, hook to pustí).
- `php-cs-fixer fix <cesta>` přijímá jen cesty v jednom stromu; nowdoc/heredoc musí být odsazený (@PHP8x4Migration),
  multi-catch bez mezer kolem `|`. **How to apply:** po napsání spusť `make check` a opravy dělej ručně/pro `src/Ai`.
- `AiSourceRulesTest` hledá v `src/Ai` literál `exec(` — trefí `curl_exec(` v CurlHttpTransport; test je potřeba opravit na `\bexec(`.
- Plán 006 (ADR-0006) má přednost před skillem `ai-integrace`: `LlmClient::complete`, žádná `temperature`, `output_config.format`, `ai_calls`.

**Why:** ušetří opakované pokusy v dalších AI milnících (M7). Viz [[project-ai-api-fakta]] v paměti architekta.
