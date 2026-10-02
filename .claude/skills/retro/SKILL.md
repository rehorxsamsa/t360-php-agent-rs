---
name: retro
description: Retrospektiva po milníku — co agenti dělali špatně, co hooky zachytily, a zapsání poučení do CLAUDE.md, skillů a paměti agentů. Spouští člověk.
disable-model-invocation: true
---

# /retro — učící se tým

1. Projdi `git log` od posledního retra, `docs/plan/*`, `.claude/logs/audit.jsonl`
   (kolikrát hooky blokovaly a proč) a reporty security revizí.
2. Najdi **opakované** problémy (≥ 2×): špatná konvence, chybějící test, blokované příkazy,
   nejasné zadání pro subagenta.
3. Pro každý navrhni nejlevnější trvalou nápravu v tomto pořadí:
   pravidlo v `CLAUDE.md` (sekce Lekce) → úprava skillu → úprava promptu agenta → nový hook
   (když musí platit vždy) → úprava kontraktu (ADR).
4. Ukaž člověku diff navržených změn konfigurace. Po schválení je proveď a commitni
   (`chore(agenti): poučení z retra M?`).
5. Požádej `architekt`, `tester`, `security-reviewer`, ať si aktualizují svou paměť.
