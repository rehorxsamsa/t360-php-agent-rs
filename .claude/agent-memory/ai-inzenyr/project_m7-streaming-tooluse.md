---
name: project-m7-streaming-tooluse
description: M7 (plán 008) stav a úskalí implementace příkladů 06/07 — fallback StreamingLlmClient v kontejneru, zakázané literály v src/Ai, odchylka AC 11 (stop-slovo)
metadata:
  type: project
---

- `config/container.php`: `StreamingLlmClient` = tatáž instance jako `LlmClient`, ale nad ne-streamovacím klientem (testy s `ScriptedLlmClient`) se
  obalí `BufferedStreamingClient`. **Why:** `ExampleRegistry` staví `Example06` eagerly; striktní `instanceof` s výjimkou rozbil ~150 testů M6 (AC 1).
- `AiSourceRulesTest` zakazuje literál `tool_choice` i `temperature` kdekoli v `src/Ai` (i v komentáři) — v komentářích psát „volba nástroje“.
- `FakeLlmClient` scénář 07 odchylka od AC 11: slova začínající „redak“ se při hledání přeskakují, jinak by „Co redakce píše o Dockeru?“
  (shoda délky redakce/Dockeru, první vyhrává) hledalo „redak“ a AC 22 by selhal. Případně opravit plán.
- Ověřovací skripty bez zásahu do tests/: `docker compose exec -T app php < /scratchpad/verify.php` s `require '/app/vendor/autoload.php'` (třídy `App\Tests\…` jsou v autoloadu).
- Labely kroků v 07: „Krok {číslo volání LLM} – {nástroj}“ (ne pořadí volání nástroje).

**How to apply:** při dalších úpravách AI klienta (M7b/M7c) zachovat fallback i tyto konvence; po T1 (tester) ověřit, že jeho testy s plánem sedí.
