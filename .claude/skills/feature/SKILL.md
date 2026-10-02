---
name: feature
description: Kompletní vývojový cyklus jedné funkce týmem agentů — plán, testy napřed, implementace, ověření, bezpečnostní revize, dokumentace, report a commit po schválení.
disable-model-invocation: true
argument-hint: "<popis funkce nebo odkaz na bod zadání>"
---

# /feature — pipeline jedné funkce

Požadavek člověka: **$ARGUMENTS**

Postupuj přesně podle pipeline v `CLAUDE.md`. Průběh veď v úkolech (TodoWrite), ať člověk
vidí stav. Každý krok deleguj na správného subagenta a dej mu úplné zadání.

1. **Plán** → `architekt`: „Vytvoř plán pro: $ARGUMENTS. Vrať cestu k plánu a otázky.“
   Pak člověku ukaž: cíl, akceptační kritéria, úkoly, rizika, otázky.
   **STOP – BRÁNA 1.** Pokračuj až po výslovném „schvaluji“ (nebo zapracuj připomínky).
2. **Testy napřed** → `tester` (režim A). Paralelně `databazista`, pokud plán mění schéma.
3. **Implementace** → `programator` a/nebo `ai-inzenyr` podle sekce „Úkoly pro agenty“.
   Nezávislé části spusť paralelně.
4. **Ověření** → `tester` (režim B). FAIL → vrať konkrétní selhání implementátorovi (max 3 kola).
5. **Revize** → `security-reviewer` nad `git diff`. `VRÁTIT` → oprava → nová revize (max 2 kola).
6. **Dokumentace** → `technicky-spisovatel` (kapitola + slovníček, pokud se týká tutoriálu).
7. **Report člověku** (česky, stručně):
   - co je hotovo (odrážky po akceptačních kritériích ✔/✘),
   - jak to ověřit sám (URL, příkazy, testovací přihlášení),
   - výsledky `make qa`, verdikt security revize, otevřená rizika, náklady AI volání (pokud byly).
   **STOP – BRÁNA 2.**
8. Po schválení spusť `/commit` se zprávou odvozenou z plánu. Plán označ jako `Stav: hotovo`.
