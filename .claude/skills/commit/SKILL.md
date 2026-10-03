---
name: commit
description: Bezpečný commit do main — kvalitní brána (make qa), kontrola tajemství, Conventional Commits česky. Nikdy nepushuje. Použij po schválení výsledku člověkem.
argument-hint: "[volitelná zpráva commitu]"
disable-model-invocation: true
allowed-tools: Bash(git status *) Bash(git diff --stat*) Bash(git add *) Bash(git commit *) Bash(git log --oneline*) Bash(make qa)
---

# /commit

1. `git status --short` a `git diff --stat`. Pokud jsou v diffu soubory, které s úkolem
   nesouvisí, **necommituj je** — vypiš je člověku.
2. Kvalitní brána: `make qa`. Při chybě STOP.
3. Přidej soubory **jmenovitě** (`git add cesta …`), nikdy `git add -A` naslepo.
   Nikdy nepřidávej `.env*` (kromě `.env.example`), `*.key`, `tests/_artefakty/`.
4. Zpráva (Conventional Commits, česky, rozkazovací/trpný tvar, ≤ 72 znaků v 1. řádku):
   ```
   typ(oblast): stručný popis

   - co a proč (2–5 odrážek)
   Plán: docs/plan/NNN-nazev.md
   ```
   Typy: `feat fix refactor test docs build ci chore perf security`.
   Pokud zadal zprávu člověk ($ARGUMENTS), použij ji.
5. `git commit` (hook ověří formát a tajemství). Pak `git log --oneline -3`.
6. **Nepushuj.** Napiš člověku: „Commit je v lokální `main`. Push a nasazení jsou na tobě.“
