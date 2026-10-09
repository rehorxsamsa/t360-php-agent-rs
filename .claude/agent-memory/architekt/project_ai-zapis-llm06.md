---
name: project-ai-zapis-llm06
description: Rozhodnutí t360 o zápisu výstupu AI do DB (ADR-0010, plán 010, 2026-10-08) — workflow bez nástrojů, návrh v session, jediný zápis POST admina přes SaveAiDraft
metadata:
  type: project
---

Příklad 09 (AI redaktor) = první místo, kde výstup AI končí v DB. Navrženo (ADR-0010, plán 010, 2026-10-08):
- Pevný workflow v PHP (osnova → koncept → sebekontrola → ≤ 1 přepracování), každý krok `StructuredCall`, **žádné `tools`**.
  `AgentLoop` z 07 se **nevyčleňuje** (YAGNI) — STAV.md backlog to dřív sliboval.
- Návrh jen v session (`AiDraftStash`), zápis jen `POST /admin/ai/09/ulozit` → `SaveAiDraft` (vynutí draft) → `CreateArticle`
  s novým volitelným parametrem `AuditAction` (`article.ai_draft_saved`). `src/Ai` nesmí záviset na zápisu (grep test).
- Do plánu zařazena **zúžená security revize** i ve výukovém režimu (vedoucí ji výslovně chtěl v pořadí agentů; první zápis AI).
- Vedoucí chtěl hlavičku `Stav: ke schválení`, pravidlo `.claude/rules/docs.md` zná jen `návrh | schváleno | hotovo` — použito podle
  zadání vedoucího a nahlášeno.

**Why:** LLM06 má být vyřešené konstrukcí, ne promptem; jedna HTTP cesta zápisu se snadno reviduje.

**How to apply:** další AI funkce se zápisem (např. asistent v editoru, M7d MCP nástroje se zápisem) navrhuj stejně: AI jen navrhuje,
zápis = explicitní akce admina přes use-case + audit; pokud přibude druhá tool-use smyčka, teprve pak `AgentLoop`.
Viz [[project-ai-api-fakta]], [[project-vyukovy-rezim]].
