---
name: audit
description: Celkový audit repozitáře paralelním týmem (bezpečnost, testy, architektura, výkon DB, dokumentace) s křížovým ověřením nálezů. Spouští člověk před releasem nebo po milníku.
disable-model-invocation: true
argument-hint: "[oblast nebo prázdné = vše]"
---

# /audit

Rozsah: **$ARGUMENTS** (když je prázdné, audituj celou aplikaci)

1. Spusť **paralelně** subagenty, každý se samostatným zadáním a výstupem do
   `docs/audit/YYYY-MM-DD-<oblast>.md`:
   - `security-reviewer` — celý `src/`, `templates/`, `public/`, `docker/`, `.github/`
   - `tester` — pokrytí akceptačních kritérií ze všech plánů testy, chybějící negativní testy
   - `architekt` — porušení vrstev, duplicity, mrtvý kód, ADR vs realita
   - `databazista` — chybějící indexy (EXPLAIN nad dotazy z repozitářů), integrita
   - `technicky-spisovatel` — příkazy v tutoriálu, které nefungují
2. Pokud je audit velký (stovky souborů), **použij dynamic workflow** (Claude Code workflows):
   jedna sada agentů hledá, druhá **nezávisle ověřuje** každý nález (méně falešných poplachů).
3. Sloučení: tabulka nálezů seřazená podle závažnosti, u každého „ověřeno / neověřeno“.
4. Navrhni člověku plán oprav jako sérii `/feature` úkolů. **Nic neopravuj bez schválení.**
