---
name: project-ai-api-fakta
description: Ověřená fakta Claude API pro t360 (2026-10-03/04) — modely, ceny, zakázané parametry, structured outputs, streaming SSE, tool use a vracení thinking bloků
metadata:
  type: project
---

Ověřeno 2026-10-03 (plán 006, ADR-0006) a znovu 2026-10-04 (plán 008, ADR-0008) na platform.claude.com:
- `claude-sonnet-5-5` 2/10 USD za MTok (cache zápis 5 min 2,50, čtení 0,20), effort ano (výchozí high), cache min 512 tok.;
  `claude-haiku-4-5-20251001` 1/5 (1,25/0,10), effort **ne**, cache min 4 096 tok., vyřazení „nejdříve 15. 10. 2026“.
  Existují i `claude-opus-5-5` (4/20) a `claude-fable-5-1` (10/50) — projekt je nepoužívá.
- Sonnet 5.5: `temperature/top_p/top_k` ≠ výchozí → 400; vynucený nástroj (`tool_choice` tool/any) → 400 (`auto`/`none` jdou);
  `thinking: disabled` → 400 (nejnižší `between_tools`); prefill nejde. Strukturovaný výstup = `output_config.format`
  json_schema (GA, oba modely), bez maxLength/maxItems, `additionalProperties: false` povinné.
- **Streaming:** `message_delta.usage` je **kumulativní** a může obsahovat i `input_tokens`/`cache_*`; `event: error` uprostřed
  proudu při HTTP 200; neznámé události ignorovat; `ping` kdykoli.
- **Tool use:** bloky `thinking` (se `signature`) se v tool use smyčce **musí vrátit nezměněné**, jinak 400 → `LlmResponse` nese
  surové bloky. PHP past: `json_decode(…, true)` udělá z `"input":{}` pole a `json_encode` z něj `[]` → 400.
  `tool_result` bloky první v `user` zprávě, všechny paralelní v jedné.
- Skill `ai-integrace` byl po M6 přepsán anglicky, ale o streamingu říká „rozhraní se rozšíří“ a „EventSource/fetch“; plán 008
  otázka 9 navrhuje úpravu podle ADR-0008 — ověř, zda proběhla.

**Why:** skill by vedl k chybám 400 a k porušení ADR-0003; ceny a modely se mění rychle.

**How to apply:** u AI plánů (M7b/M7c) vycházej z ADR-0006 + ADR-0008 a znovu ověř ceny/modely (WebFetch platform.claude.com;
context7 `/llmstxt/platform_claude_llms-full_txt` vrací jen úryvky). Viz [[project-omezeni-prostredi-pro-ac]], [[project-vyukovy-rezim]].
