# ADR-0001: Aplikaci vyvíjí výhradně tým agentů Claude Code
- **Stav:** přijato
- **Datum:** 2026-10-02
- **Autor:** člověk (product owner)

## Kontext
Projekt je výukový: cílem je naučit se vývoj s agenty dnes a připravit se na to, jak se
bude programovat zítra. Člověk nemá psát kód — jen zadávat a schvalovat.

## Rozhodnutí
- Kód, testy, infrastrukturu i dokumentaci píší subagenti definovaní v `.claude/agents/`.
- Hlavní relace Claude Code je orchestrátor; řídí se `CLAUDE.md` a skillem `/feature`.
- Člověk schvaluje na dvou branách (plán, výsledek) a sám dělá `git push` a nasazení.
- Pravidla, která musí platit vždy, vynucují **hooky a permissions**, ne jen prompty.

## Důsledky
+ Opakovatelný, auditovatelný proces (plány, ADR, audit log, Conventional Commits).
+ Bezpečnost: agenti nemají přístup k produkci ani k tajemstvím.
− Vyšší spotřeba tokenů (víc agentů, revize). Řešíme volbou modelů podle role.
− Agenti chybují — proto testy napřed, nezávislá revize a retrospektivy.

## Zvažované alternativy
- Jeden univerzální agent – jednodušší, ale bez oddělení odpovědností a nezávislé kontroly.
- Plně autonomní agenti včetně push/deploy – odmítnuto: člověk musí mít poslední slovo.
