---
name: project-vyukovy-rezim
description: Od 2026-10-03 projekt t360 běží ve výukovém režimu — plány MVP, bez security review kola, rizika se jen zapisují
metadata:
  type: project
---

Od 2026-10-03 je aplikace jen lokální výuková (Docker na localhostu, nikdy produkce). Člověk chce
rychle spustit a ukázat práci agentů; nemusí být na 100 %.

**Why:** rozhodnutí člověka zapsané v `docs/plan/STAV.md` po M1 (T6 security review přeskočen).

**How to apply:** plány drž malé (MVP), krok `security-reviewer` v tabulce úkolů vynech a uveď
důvod; rizika pouze vyjmenuj v sekci Rizika. Nové composer závislosti stále = otázka pro člověka,
preferuj vlastní implementaci. Člověk má trvalý souhlas s doporučeními (kromě push, hooků
a mazání dat), proto u každé otázky vždy napiš doporučení. Viz [[project-db-nazvy-a-migrace]].
