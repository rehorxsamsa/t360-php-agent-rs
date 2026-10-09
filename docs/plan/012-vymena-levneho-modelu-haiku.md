# 012 – Výměna levného modelu: Haiku 4.5 → Haiku 5.5 a oprava ceny cache u Sonnet 5.5
Stav: hotovo

- **Milník:** údržba AI jádra (navazuje na plán 006, M6) · **Režim:** výukový (viz `docs/plan/STAV.md`), MVP
- **Autor:** agent architekt · **Datum:** 2026-10-09
- **Souvisí:** [plán 006](006-ai-jadro.md) (katalog modelů, `AiConfig`, příklady 03 a 05), [ADR-0006](../adr/0006-vlastni-llm-klient-curl.md)
  (vlastní klient, `effort` jen podle katalogu), [plán 010](010-ai-redaktor-agent.md) otázka 10 (sem odložená výměna Haiku a cena cache),
  skill `ai-integrace`
- **Schéma DB se nemění, nová závislost žádná, ADR nevzniká.** Výměnu modelu vrátíte změnou jedné proměnné prostředí a jednoho
  záznamu v katalogu. Vrstvy ani rozhraní se nemění, takže `docs/architektura.md` zůstává, jak je.

### Ověřená fakta (2026-10-09, oficiální dokumentace platform.claude.com)
| Téma | Zjištění | Zdroj |
|---|---|---|
| ID nového modelu | `claude-haiku-5-5`: pevné ID bez data a bez aliasu, vydaný 7. 10. 2026, stav „Active (latest)“, vyřazení „not sooner than October 7, 2027“ | [models/overview](https://platform.claude.com/docs/en/about-claude/models/overview), [haiku-5-5/overview](https://platform.claude.com/docs/en/models/haiku-5-5/overview) |
| Ceny Haiku 5.5 (prompt ≤ 100 000 tokenů) | vstup **0,10**, zápis do cache na 5 min **0,125**, čtení z cache **0,01**, výstup **0,50** USD/MTok | [pricing](https://platform.claude.com/docs/en/about-claude/pricing) (Model pricing) |
| Ceny Haiku 5.5 (prompt > 100 000 tokenů) | 0,50 / 0,625 / 0,05 / 2,50 USD/MTok. O pásmu rozhoduje celý vstup jednoho požadavku včetně cache. | pricing (Long context pricing) |
| Haiku 4.5 | `claude-haiku-4-5-20251001` je v přehledu modelů mezi „Legacy models (still available)“. V tabulce vyřazování má stav **Active** a sloupec Deprecated „N/A“, vyřazení „not sooner than October 15, 2026“. Anthropic slibuje **aspoň 60 dní předem** oznámení, a protože oznámení zatím nepřišlo, model reálně skončí nejdřív kolem poloviny prosince 2026. Ceny 1 / 1,25 / 0,10 / 5 USD/MTok. | [model-deprecations](https://platform.claude.com/docs/en/about-claude/model-deprecations), models/overview |
| **Sonnet 5.5, čtení z cache** | **0,10 USD/MTok**. Uvádí to řádek tabulky („$0.10 / MTok²“), poznámka pod čarou č. 2 („0.05x the base input price“, tedy 2 × 0,05) i oddíl Prompt caching („$0.10 USD on Claude Sonnet 5.5“). Hodnota 0,20 v `config/ai-models.php` je **chyba**, vznikla obvyklým násobkem 0,1× (stejnou cenu má Sonnet 5). Platí tvrzení architekta z 2026-10-08. | pricing (Model pricing, Prompt caching) |
| `effort` | Haiku 5.5 **podporuje** všech pět úrovní, výchozí je `medium`. Haiku 4.5 v seznamu podporovaných modelů není. | [effort](https://platform.claude.com/docs/en/build-with-claude/effort) |
| Přemýšlení (thinking) | Haiku 5.5 má adaptivní přemýšlení **zapnuté ve výchozím stavu**. Tokeny přemýšlení se počítají do `max_tokens`, takže malé `max_tokens` může skončit `stop_reason: "max_tokens"` dřív, než model napíše text. Bloky `thinking` mají prázdné pole `thinking` a nesou jen `signature`. `thinking: {type: "enabled", budget_tokens}` vrátí 400, `disabled` projde jen do úrovně `high`. | [haiku-5-5/migration-guide](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide), effort |
| Zakázané parametry | `temperature`, `top_p` a `top_k` s jinou než výchozí hodnotou vrátí 400, stejně jako prefill (poslední zpráva od asistenta). Vynucený `tool_choice` model přijme, ale pak bez thinking. Při `refusal` nemá serverovou zálohu. | migration-guide |
| Strukturovaný výstup | `output_config.format` podporuje `claude-haiku-5-5` i `claude-haiku-4-5-20251001` | [structured-outputs](https://platform.claude.com/docs/en/build-with-claude/structured-outputs) |
| Minimum pro cache | Haiku 5.5 a Sonnet 5.5 **512 tokenů**, Haiku 4.5 4 096 tokenů. Kratší prompt se do cache neuloží a chyba nevznikne (`cache_*` = 0). | [prompt-caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching) |
| Tokenizer | Haiku 5.5 používá stejný novější tokenizer jako modely od 4.7 (a tedy i Sonnet 5.5). Stejný text dá zhruba o 30 % víc tokenů než u Haiku 4.5. | migration-guide, pricing |

Context7 se k těmto údajům nepoužil, protože vrací jen úryvky (viz paměť architekta). Stránky výše jsem stáhl přímo přes WebFetch.

## Cíl
Admin spouští příklady 03 (štítky a rubrika) a 05 (překlad, levnější varianta) na aktuálním levném modelu `claude-haiku-5-5`.
Je zhruba desetkrát levnější než Haiku 4.5, který přechází do legacy. Ceny v přehledu `/admin/ai` a v tabulce `ai_calls` odpovídají
skutečnému ceníku, i u čtení z cache Sonnet 5.5.

## Akceptační kritéria
Všechna kritéria kromě AC 19 ověří PHPUnit (falešný klient, `ScriptedHttpTransport`, `InMemoryAiCallRepository`), `grep` nebo
Playwright **bez API klíče a bez sítě**. Kritéria AC 5, AC 8 a AC 14b platí při doporučené variantě A z otázky 1.

**Katalog a konfigurace**
1. **Given** prázdné prostředí, **When** `AiConfig::fromEnvironment([])`, **Then** `cheapModel === 'claude-haiku-5-5'`,
   `model === 'claude-sonnet-5-5'` a `AiConfig::DEFAULT_CHEAP_MODEL === 'claude-haiku-5-5'`.
2. **Given** katalog z `config/ai-models.php`, **When** `get('claude-haiku-5-5')`, **Then** ceny jsou 0.10 / 0.50 / 0.125 / 0.01
   (vstup / výstup / zápis do cache / čtení z cache) a `supportsEffort === true`.
3. **Given** Haiku 5.5, **When** `cost(new TokenUsage(100, 50, 2000, 3000))`, **Then** výsledek je `0.000315`, tedy desetina
   dnešní hodnoty `0.00315` u Haiku 4.5.
4. **Given** Sonnet 5.5, **Then** `cacheReadPerMTok === 0.1`. Ostatní ceny (2 / 10 / 2,5) se nemění, `cost(TokenUsage(1000, 500, 0, 0))`
   je dál `0.007` a `cost(TokenUsage(0, 0, 0, 1_000_000))` vrátí `0.1`.
5. **Given** legacy záznam `claude-haiku-4-5-20251001`, **Then** zůstávají ceny 1 / 5 / 1,25 / 0,10, `supportsEffort === false`
   a cena `0.00315` (AC 3 plánu 006) se nemění.
6. **Given** zdroj `config/ai-models.php`, **Then** obsahuje datum `2026-10-09`, adresu `https://platform.claude.com/docs/en/about-claude/pricing`
   a poznámku k cenovému pásmu Haiku 5.5 nad `100 000` tokeny. Upraví se tím existující test s datem `2026-10-03`.
7. **Given** soubory `compose.yaml` a `.env.example`, **Then** výchozí hodnoty `AI_MODEL` a `AI_MODEL_LEVNY` se rovnají
   `AiConfig::DEFAULT_MODEL` a `DEFAULT_CHEAP_MODEL` a obě jsou v katalogu. Nový test proti rozjetí tří míst čte oba soubory
   jako text.

**Klient Anthropic** (`ScriptedHttpTransport`)
8. **Given** požadavek na `claude-haiku-5-5` s `effort 'low'` a JSON schématem, **When** `complete()`, **Then** tělo má
   `"model":"claude-haiku-5-5"` a `output_config` = `{effort: 'low', format: {type: 'json_schema', schema: …}}`. Klíče
   `temperature`, `top_p`, `top_k`, `thinking`, `tool_choice` ani `metadata` v něm nejsou.
9. **Given** legacy `claude-haiku-4-5-20251001` a `effort 'low'`, **Then** bez schématu chybí `output_config` úplně a se schématem
   obsahuje jen `format`. Jde o dnešní dva testy přesměrované na konstantu `AiFixtures::LEGACY_HAIKU`.

**Příklady 03 a 05, CLI, administrace**
10. **Given** příklad 03, **When** se spustí, **Then** `LlmRequest` má `model 'claude-haiku-5-5'`, `effort 'low'`, `maxTokens 1000`
    (otázka 2), `cacheSystem true` a schéma s `enum`. Kontrakt v `ExampleRequestContractTest` se upraví.
11. **Given** příklad 05, **Then** `modelChoices() === ['claude-sonnet-5-5', 'claude-haiku-5-5']`. Spuštění s `claude-haiku-5-5` pošle
    tento model. Spuštění s `claude-haiku-4-5-20251001`, který není nakonfigurovaný, skončí `InvalidExampleInput`
    „Vyberte model ze seznamu.“ a do `ai_calls` nic nezapíše.
12. **Given** CLI, **When** `ai:priklad 05 --model=claude-haiku-5-5`, **Then** skončí kódem 0, výstup obsahuje řádek začínající
    `Model claude-haiku-5-5 · ` a záznam v `ai_calls` má `model = 'claude-haiku-5-5'`.
13. **Given** `MeteredLlmClient` nad falešným klientem se skriptovanou spotřebou (1000, 500), **When** se volá model `claude-haiku-5-5`,
    **Then** `costUsd === 0.00035` a stejná cena i model se zapíšou do `ai_calls`.
14. **Given** `/admin/ai`, **Then** stránka ukazuje levný model `claude-haiku-5-5` a výběr modelu na `/admin/ai/05` má právě
    dvě volby `claude-sonnet-5-5` a `claude-haiku-5-5`. POST s `model=claude-haiku-5-5` vrátí 303 a v `ai_calls` je nový model.
    **(b)** **Given** dřívější řádek `ai_calls` s modelem `claude-haiku-4-5-20251001` a `cost_usd 0.000766`, **Then** tabulka
    „Poslední volání“ ho ukáže s původním modelem a původní cenou. Nic se nepřepočítává a nevzniká žádná migrace
    (`git diff --stat -- database/` je prázdný).

**Úklid a brána kvality**
15. **Given** `grep -rn 'claude-haiku-4-5' src templates bin public compose.yaml .env.example README.md docs/DEMO.md`, **Then** vrátí
    0 řádků. Výjimkou je jediný legacy záznam v `config/ai-models.php`, který tento grep nezahrnuje.
16. **Given** `grep -rn 'claude-haiku-4-5' tests/Unit tests/Integration`, **Then** shody jsou jen v definici `AiFixtures::LEGACY_HAIKU`.
    Testy legacy větve (AC 5, 9) používají konstantu.
17. **Given** `docs/ai-priklady/03.md`, `05.md` a kapitola M6 v `docs/tutorial.html`, **Then** dokumenty uvádějí `claude-haiku-5-5`,
    ceny 0,10 / 0,50 USD a čtení z cache Sonnet 5.5 za 0,10 USD a datum ověření 2026-10-09. Dál vysvětlují, že Haiku 5.5 `effort`
    dostane a že přemýšlení se počítá do `max_tokens`, a uvádějí minimum cache 512 tokenů (ne 4 096). Ukázkové výstupy CLI
    a tabulka `ai_calls` jsou z nového běhu falešného klienta. Starý model zmiňují nejvýše jednou na soubor, jako legacy.
18. `make qa` je zelené (check, test, composer audit). Scénáře v `tests/E2E-scenare.md` (kroky 291–297, 321, 339–340) uvádějí nový model.
19. *(Jen člověk, volitelné, ≈ 0,005 USD)* **Given** `AI_PROVIDER=anthropic`, platný klíč a `AI_MODEL_LEVNY=claude-haiku-5-5`
    v `.env`, **When** `ai:priklad 03` a `ai:priklad 05 --model=claude-haiku-5-5`, **Then** nepřijde 400,
    `stop_reason` je `end_turn`, řádky `ai_calls` stojí méně než 0,001 USD (03) a 0,005 USD (05). Hodnotu `cache_creation_input_tokens`
    u 03 zapíše technicky-spisovatel do podkladu 03.

## Návrh

### Změny (žádná nová třída ani rozhraní)
| Místo | Změna |
|---|---|
| `config/ai-models.php` | Hlavička: „Ověřeno 2026-10-09“ a odkazy na pricing, models/overview a model-deprecations. Pořadí záznamů: `claude-sonnet-5-5` (`cache_read_per_mtok` **0.10**), nový `claude-haiku-5-5` (0.10 / 0.50 / 0.125 / 0.01, `supports_effort` true), `claude-haiku-4-5-20251001` jako **legacy** s komentářem „jen pro starší `.env`, odstranit po ohlášení vyřazení“. Komentář k pásmu nad 100 000 tokeny: katalog drží jen nižší pásmo, protože vstupy příkladů jsou nejvýše asi 15 000 tokenů (článek ≤ 30 000 znaků, u 05 ≤ 10 000). |
| `src/Ai/AiConfig.php` | `DEFAULT_CHEAP_MODEL = 'claude-haiku-5-5'` |
| `src/Ai/Examples/Example03Classification.php` | `MAX_TOKENS` 500 → **1000** (otázka 2), aby zbylo místo na přemýšlení. Jde jen o rezervu v denním limitu, platí se skutečně spotřebované tokeny. |
| `src/Ai/Client/AnthropicClient.php` | Jen komentář na ř. 231: „Model bez `supports_effort` (dnes legacy Haiku 4.5) `effort` nezná…“. Logika zůstává. |
| `compose.yaml`, `.env.example` | výchozí `AI_MODEL_LEVNY` = `claude-haiku-5-5` |
| `tests/Unit/Support/AiFixtures.php` | `HAIKU = 'claude-haiku-5-5'`, nová `LEGACY_HAIKU = 'claude-haiku-4-5-20251001'` |

### Tok požadavku
Tok požadavku zůstává stejný: `Example03/05` → `StructuredCall` → `MeteredLlmClient` (katalog → cena → `ai_calls`) → `AnthropicClient`
(posílá `effort`, jen když ho model podle katalogu podporuje) nebo `FakeLlmClient`, který na modelu nezávisí. Rozdíl je jediný:
Haiku 5.5 má `supportsEffort = true`, takže příklad 03 a levná varianta 05 nově pošlou `output_config.effort = 'low'`.

Klient je s Haiku 5.5 kompatibilní bez úprav kódu:
- `temperature`, `top_*`, `thinking` ani `tool_choice` nikdy neposílá, což hlídá existující test.
- Opakování ve `StructuredCall` končí zprávou `user`, takže nejde o prefill.
- Text skládá jen z bloků `text`.
- `refusal` a `max_tokens` zachytí `StructuredCall::assertComplete()`.

### Proč beze změny DB a bez ADR
- `MeteredLlmClient` ukládá `cost_usd` v okamžiku volání a `model` jako `VARCHAR(100)`. `AiUsageReport` ani `PdoAiCallRepository`
  katalog nečtou. Staré řádky s Haiku 4.5 proto zůstávají správné, protože tehdy se skutečně platilo 1 / 5 USD, a přepisovat je
  by falšovalo historii.
- Ani oprava ceny Sonnet nevyžaduje přepočet historie: cache používá jen příklad 03 (Haiku) a falešný klient cache tokeny nevykazuje.
- ADR se zakládá u rozhodnutí, které se špatně vrací. Tady jde o jednu konfigurační hodnotu a jeden záznam v katalogu.
  Pravidlo z ADR-0006 („`effort` jen podle katalogu“) platí dál beze změny.

### Doporučené commity (každý zelený sám o sobě)
1. `fix(ai): cena čtení z cache Sonnet 5.5 0,10 USD za milion tokenů`: katalog, `ModelCatalogTest`, datum ověření.
2. `feat(ai): levný model Claude Haiku 5.5 pro příklady 03 a 05`: katalog, `AiConfig`, 03 `MAX_TOKENS`, compose, `.env.example`, testy.
3. `docs(ai): Haiku 5.5 v podkladech 03 a 05, tutoriálu, README a E2E scénářích`
4. `docs(plan): plán 012 a stav`

## Dotčené soubory
**Nové:** `tests/Unit/Ai/ModelDefaultsConsistencyTest.php` (AC 7).

**Změněné, kód a konfigurace:** `config/ai-models.php`, `src/Ai/AiConfig.php`, `src/Ai/Examples/Example03Classification.php`,
`src/Ai/Client/AnthropicClient.php` (komentář), `compose.yaml`, `.env.example`.

**Změněné, testy:** `tests/Unit/Support/AiFixtures.php`, `tests/Unit/Ai/ModelCatalogTest.php`, `tests/Unit/Ai/AiConfigTest.php`,
`tests/Unit/Ai/Client/AnthropicClientTest.php`, `tests/Unit/Ai/Client/MeteredLlmClientTest.php` (AC 13),
`tests/Unit/Ai/Examples/Example03ClassificationTest.php`, `Example05TranslationTest.php`, `ExampleRequestContractTest.php`,
`tests/Unit/Console/AiExampleCommandTest.php`, `tests/Unit/Http/AdminAiTest.php`, `tests/Unit/Http/ExampleResultStashTest.php`
(řetězec je tam libovolný, přepsat kvůli AC 16), `tests/E2E-scenare.md`.

**Změněné, dokumentace:** `docs/ai-priklady/03.md`, `docs/ai-priklady/05.md`, `docs/tutorial.html` (kapitola M6: krok 4 „effort jen
modelu, který ho zná“, příklad 03 cache, tabulka příkladů, „Kolik to stojí“, „Vyzkoušej sám“), `README.md` (ř. 38, 173, 184),
`docs/DEMO.md` (ř. 225), `docs/plan/STAV.md` (vedoucí).

**Nemění se:** `database/`, `docs/architektura.md`, ADR, historické plány 006–011 (jsou to záznamy tehdejšího stavu).
`.claude/skills/ai-integrace/SKILL.md` (ř. 13 a 55) se mění jen po souhlasu člověka (otázka 4).

## Úkoly pro agenty
| # | Agent | Úkol | Výstup | Paralelně |
|---|---|---|---|---|
| T1 | `tester` | Testy napřed pro AC 1–16: úpravy a nové testy podle „Dotčených souborů“, `AiFixtures::HAIKU`/`LEGACY_HAIKU`, `ModelDefaultsConsistencyTest`, scénáře v `tests/E2E-scenare.md` | seznam padajících testů a důvod pádu | — |
| T2 | `ai-inzenyr` | Implementace podle tabulky „Změny“: katalog (2 commity, viz výše), `AiConfig`, 03 `MAX_TOKENS`, komentář v klientu, `compose.yaml` a `.env.example` (jednořádková změna výchozí hodnoty, devops netřeba). `make qa`. | diff a výsledek `make qa` | po T1 |
| T3 | `tester` | Ověření: `make qa`; CLI `ai:priklad 03` a `ai:priklad 05 --model=claude-haiku-5-5` (falešný klient) s uloženým výstupem; Playwright `/admin/ai` a `/admin/ai/05` (dvě volby); grepy z AC 15 a 16; `git diff --stat -- database/` | protokol AC 1–18 a výstupy CLI pro spisovatele | po T2 |
| T4 | `technicky-spisovatel` | Úprava dokumentů podle AC 17: `docs/ai-priklady/03.md`, `05.md`, kapitola M6 v `tutorial.html`, README, DEMO. Ceny přepočítat (typicky 03 ≈ 0,0002 USD, 05 krátký článek ≈ 0,0005 USD, článek na limitu ≈ 0,003 USD; Sonnet je u obou cen přesně 20× dražší). Cache u 03 popsat podmíněně, viz riziko R8. | diff dokumentů | ∥ T3 (výstupy CLI si spustí sám, nebo je převezme z T3) |
| T5 | vedoucí | `STAV.md`: místo „vyřazení od 15. 10.“ uvést „nejdříve 15. 10. 2026, zatím neohlášeno, ≥ 60 dní předem“, odškrtnout cenu cache a výměnu Haiku. Požádat člověka o úpravu `.env` (otázka 6). | diff | po T3, T4 |

**Bezpečnostní revize (`security-reviewer`) se vynechává.** Důvody:
- Mění se jen konstanty (ID modelu, ceny, `max_tokens`) a komentáře.
- Nevzniká nový vstup, výstup, trasa, dotaz ani závislost (žádné riziko pro dodavatelský řetězec, LLM03).
- Seznam povolených modelů dál vynucuje katalog a AC 11 výslovně testuje odmítnutí nenakonfigurovaného modelu (A05/LLM10).
- Validace výstupu modelu v PHP (LLM05) a značky `<clanek>` (LLM01) se nemění.
- Riziko nákladů (LLM10) se zmenšuje, protože model je desetkrát levnější. Projekt je navíc ve výukovém režimu.

Revizi nahrazují grepy z T3 (AC 15, 16) a kontrola, že diff nezasahuje mimo „Dotčené soubory“.
**Databazista ani devops** úkol nemají.

## Rizika a bezpečnost
- **R1 Parametry požadavku:** Haiku 5.5 vrací 400 na `temperature`/`top_*`, prefill a `thinking.enabled`. Klient nic z toho neposílá
  (AC 8). Kombinaci `effort` + `format` + `cache_control` na Haiku 5.5 ověří naživo až člověk (AC 19). Dokumentace ji podporuje
  (effort i structured outputs mají v seznamu podporovaných modelů `claude-haiku-5-5`).
- **R2 Přemýšlení spotřebuje `max_tokens`:** při 500 tokenech by 03 mohl skončit `max_tokens` a `StructuredCall` by vyhodil „Odpověď byla useknuta“
  (bez opakování). Proto 1000. U 05 (6000) se nic nemění, stejný tokenizer i přemýšlení už má Sonnet 5.5.
- **R3 Tokenizer +30 %:** spotřeba a odhady v dokumentech napsané pro Haiku 4.5 neplatí, T4 je přepočítá. Denní limit 200 000 tokenů
  se na stejný text vyčerpá rychleji, ale v USD je to výrazně levnější.
- **R4 Cenové pásmo nad 100 000 tokenů:** katalog má jen nižší pásmo. U vstupu nad 100 000 tokenů by cena vyšla pětkrát nižší, než je
  skutečná. Příklady na Haiku takový vstup poslat nemohou (≤ ~15 000 tokenů), pravidlo zaznamená komentář v katalogu. Logika pásem je mimo rozsah.
- **R5 Historie `ai_calls`:** řádky se nepřepisují (AC 14b). Ruční `UPDATE` by zfalšoval skutečně účtované ceny a mazání nebo změna dat
  vyžaduje souhlas člověka.
- **R6 Starý `.env`:** `make up` vytváří `.env` jen tam, kde chybí, takže lokální `.env` (i u klonů) dál obsahuje `AI_MODEL_LEVNY=claude-haiku-4-5-20251001`.
  Ve variantě A se tiše dál používá starý model. Ve variantě B by příklady 03 a 05 skončily chybou konfigurace (model není v katalogu).
  Krok E2E `printenv` (ř. 291–292) projde až po úpravě `.env`. Agenti `.env` měnit nesmí (deny), udělá to člověk (otázka 6).
- **R7 Termín vyřazení:** Haiku 4.5 zatím **není deprecated** a vyřazení přijde nejdřív 60 dní po oznámení. Nejde tedy o havárii
  k 15. 10. 2026, ale výměna se vyplatí kvůli ceně. `STAV.md` dnes píše „od 15. 10.“, opraví to T5.
- **R8 Cache u 03:** minimum klesá ze 4 096 na 512 tokenů. System prompt 03 má asi 1 300 znaků, což je v češtině s novým tokenizerem kolem této
  hranice, takže cache se uložit může, ale nemusí. Dokumenty nesmí tvrdit „`cache_*` je vždy 0“. Mají popsat, jak to poznat (`cache_creation_input_tokens`
  v `ai_calls`). Částky jsou v obou případech zanedbatelné.
- **R9 Kvalita a odmítnutí:** Haiku 5.5 je nový model s bezpečnostními klasifikátory a bez serverové zálohy při `refusal`. `StructuredCall` hlásí
  „Model odmítl odpovědět“ a validace v PHP (enum rubrik, 3–6 štítků, délky) zůstává (LLM05). Kvalitu porovná člověk v AC 19.
- **OWASP / LLM:** A05 chybná konfigurace (seznam povolených modelů v katalogu, AC 7 a 11), LLM10 neomezená spotřeba (levnější model,
  `max_tokens` 1000 je dál malé), LLM05 beze změny.

## Mimo rozsah
- Logika cenových pásem (nad 100 000 tokenů) a odhad vstupu před voláním.
- Odstranění příznaku `supports_effort` (ve variantě A má reálné použití) a nový parametr `thinking` v `LlmRequest`.
- Změna `AI_MODEL` (Sonnet 5.5) a převedení příkladů 01, 02, 04, 06–09 na Haiku.
- Přepočet nebo mazání řádků `ai_calls`, včetně ~170 testovacích řádků falešného klienta v dev DB.
- Úprava skillu `ai-integrace` a paměti ostatních agentů bez souhlasu člověka (otázka 4).
- Živé volání API agenty, to dělá jen člověk (AC 19).

## Otázky pro člověka
1. **Ponechat Haiku 4.5 v katalogu jako legacy?** Doporučuji **A: ano, do ohlášení vyřazení.** Starší `.env` dál funguje (R6),
   větev „model bez `effort`“ má reálné použití i test a stojí to sedm řádků konfigurace. Varianta B (odstranit) zjednoduší katalog,
   ale starý `.env` pak příklady 03 a 05 rozbije chybou konfigurace a test větve bez `effort` by potřeboval umělý katalog.
2. **`max_tokens` příkladu 03 zvýšit z 500 na 1000?** Doporučuji **ano** (R2). Výstup má kolem 50–100 tokenů, zbytek je rezerva na přemýšlení,
   platí se jen skutečně spotřebované tokeny.
3. **Opravit cenu cache Sonnet 5.5 (0,20 → 0,10) v tomto plánu jako samostatný první commit `fix(ai)`?** Doporučuji **ano**.
   Údaj je ověřený na třech místech dokumentace a s výměnou Haiku věcně souvisí (stejný soubor, stejné datum ověření).
4. **Upravit skill `.claude/skills/ai-integrace/SKILL.md`** (ř. 13 výchozí `AI_MODEL_LEVNY`, ř. 55 „Haiku `effort` nepodporuje“ →
   „Haiku 4.5 ne, Haiku 5.5 ano“, případně i zastaralý `EMBED_MODEL=nomic-embed-text` na ř. 16)? Změna v `.claude/` čeká na souhlas.
   Doporučuji **ano**, jako malý samostatný `chore(claude)` po bráně 2, jinak skill povede agenty k chybnému tvrzení.
5. **Vynechat bezpečnostní revizi?** Doporučuji **ano** (zdůvodnění v „Úkolech pro agenty“), místo ní grepy z AC 15 a 16 v T3.
6. **Úprava vašeho `.env`** (agenti ji dělat nesmí): změňte řádek na `AI_MODEL_LEVNY=claude-haiku-5-5`, pak `make up`. Doporučuji
   **změnit hodnotu, ne mazat řádek**, aby `.env` odpovídal `.env.example`. Bez toho běžící aplikace dál používá Haiku 4.5 a krok E2E `printenv` neprojde.
7. **Živý běh (AC 19, ≈ 0,005 USD, jen s vaším klíčem):** chcete ho provést před bránou 2? Doporučuji **ano**, je to jediné ověření
   kombinace `effort` + `format` + cache na Haiku 5.5 a zjistí, zda se system prompt 03 vejde do cache.
