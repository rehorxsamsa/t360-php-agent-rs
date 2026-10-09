---
name: project-m7c-priklad-09
description: M7c (plán 010) příklad 09 AI redaktor — rozhodnutí mimo AC (štítky Sebekontrola, Průběh, fake scénář), zakázané řetězce v src/Ai/Editor
metadata:
  type: project
---

- T3 hotovo 2026-10-09: `src/Ai/Editor/*`, `Example09AiEditor`, prompty `09-editor-*`, `PromptData::block`, scénář 09 ve `FakeLlmClient`, `ai:priklad 09 --tema`, `docs/ai-priklady/09.md`.
- AC 13 (grep) zakazuje v `Example09AiEditor.php` a `src/Ai/Editor/*.php` i v komentářích řetězce `Session`, `tools:`, `SaveAiDraft`, `CreateArticle`, `ArticleRepository` — psát „úložiště“, „nástroje“.
- Rozhodnutí mimo AC: štítek „Sebekontrola (před přepracováním)“ se použije, když po zobrazené sebekontrole proběhlo přepracování (jinak „Sebekontrola“);
  „Průběh“ píše „volání“ jen u prvního kroku; fake scénář vybírá poslední zprávu `user` obsahující `<tema>` (kvůli opakování `StructuredCall`, kde poslední zpráva značky nemá).
- Při `ok` + nález `prompt_injection` fake vrací verdikt `ok` (nález se jen zobrazí). `DraftProposal::fromArray` nemění hodnoty konceptu (round-trip rovnocenný).
- Živé ověření (AC 30) neproběhlo — sekce Náklady v 09.md je odhad. Cena cache read Sonnet 5.5 v `config/ai-models.php` (0,20 → 0,10) čeká na samostatný `fix(ai)`.

**Why:** navazující úpravy (živý běh, Haiku 5.5) vědět, co je rozhodnutí a co plán.
**How to apply:** při změně textů `Přepracování`/`Průběh` zkontrolovat testy Example09AiEditorTest a šablonu `ai-editor.php`.
