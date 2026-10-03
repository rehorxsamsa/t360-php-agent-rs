---
name: project-nazvy-anglicky
description: Rozpor v pojmenování — workspace a AGENTS.md chtějí anglické identifikátory, skills/rules/definice agentů používají české (Clanek, Pozadavek, LlmKlient); ADR-0003
metadata:
  type: project
---

Workspace pravidlo i AGENTS.md: identifikátory v kódu výhradně anglicky. Skill `php-oop-standardy`,
`.claude/rules/php.md`, skill `db-migrace` (tabulky česky) a definice agenta architekt
(`LlmKlient`, `FalesnyKlient`) ale používají češtinu. ADR-0003 (2026-10-03, stav navrženo) navrhuje
angličtinu s mapováním (Clanek→Article, Pozadavek→Request, LlmKlient→LlmClient, FalesnyKlient→FakeLlmClient…).
Česky zůstávají URL, texty UI, komentáře a zamčený kontrakt (`/zdravi` JSON `stav`/`db`,
`bin/konzole migrace:spust`, env proměnné). Pojmenování DB rozhodne člověk před M2.

**Why:** přejmenování napříč vrstvami se špatně vrací; M1 je první kód.

**How to apply:** v plánech používej anglické názvy tříd a metod, i když tvoje vlastní instrukce
uvádějí české (`LlmKlient`); před M2 zkontroluj, zda člověk ADR-0003 přijal a jak rozhodl o DB.
