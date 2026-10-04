---
name: project-ai-api-fakta
description: Ověřená fakta Claude API pro t360 (2026-10-03) a rozpory se skillem ai-integrace — modely, ceny, zakázané parametry, structured outputs
metadata:
  type: project
---

Ověřeno 2026-10-03 na platform.claude.com (plán 006, ADR-0006):
- `claude-sonnet-5-5` 2/10 USD za MTok (cache zápis 5 min 2,50, čtení 0,20), effort ano, cache min 512 tok.;
  `claude-haiku-4-5-20251001` 1/5 (1,25/0,10), effort **ne**, cache min 4 096 tok., vyřazení „nejdříve 15. 10. 2026“.
- Sonnet 5.5: `temperature/top_p/top_k` ≠ výchozí → 400; vynucený nástroj (`tool_choice` tool/any) → 400;
  `thinking: disabled` → 400 (jen `between_tools`); prefill nejde. Strukturovaný výstup = `output_config.format`
  json_schema (GA, oba modely), bez maxLength/maxItems, `additionalProperties: false` povinné.
- Skill `ai-integrace` a agent `ai-inzenyr` jsou zastaralé (české názvy, vynucený nástroj, `teplota`, `ai_volani`);
  plán 006 otázka 10 navrhuje úpravu `.claude/` — ověř, zda proběhla.

**Why:** skill by vedl k chybám 400 a k porušení ADR-0003; ceny a modely se mění rychle.

**How to apply:** u AI plánů (M7) vycházej z ADR-0006 a znovu ověř ceny/modely (context7 + WebFetch
platform.claude.com, ne paměť). Viz [[project-omezeni-prostredi-pro-ac]], [[project-vyukovy-rezim]].
