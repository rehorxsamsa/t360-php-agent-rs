# ADR-0010: AI redaktor – řízený workflow bez zápisových nástrojů, uložení jen schválením administrátora
- **Stav:** přijato
- **Datum:** 2026-10-08
- **Autor:** agent architekt
- **Souvisí:** [plán 010](../plan/010-ai-redaktor-agent.md), [ADR-0006](0006-vlastni-llm-klient-curl.md) (strukturovaný výstup),
  [ADR-0008](0008-streaming-a-nastroje-llm.md) (tool use – zde se záměrně nepoužívá), plán 005 (`CreateArticle`, audit),
  skilly `ai-integrace`, `bezpecnost-owasp`

## Kontext
AI příklad 09 (zadání, M7c) má z tématu připravit článek: osnova → koncept → sebekontrola → uložení **jako koncept**;
publikovat smí jen administrátor. Výukový cíl: plánování, reflexe, **nadměrná autonomie (OWASP LLM06)** a schvalování
člověkem. Jde o **první místo, kde výstup AI končí v databázi** (příklady 01–08 jen zobrazují).

Platí:
- `LlmClient::complete()` + `StructuredCall` (JSON schéma přes `output_config.format`, validace v PHP, 1 opakování) jsou
  ověřené v příkladech 02–05; tool use smyčka existuje jen v příkladu 07 (čtecí nástroje).
- `claude-sonnet-5-5` odmítá vynucený nástroj (`tool_choice` tool/any → 400) — model nelze donutit „zavolat uložení“.
- Zápis článku umí use-case `CreateArticle` (validace, unikátní slug, audit `article.created`); `audit_log.action` je
  `VARCHAR(50)`, nová akce migraci nepotřebuje.
- Celé zpracování jednoho požadavku musí doběhnout do `fastcgi_read_timeout 120s` (nginx); cURL timeout 90 s na volání.
- Výukový režim, bez nové závislosti, vše musí běžet s `FakeLlmClient` bez API klíče.

## Rozhodnutí
**AI redaktor je pevný workflow řízený PHP (řetězení promptů + hodnotitel/optimalizátor), model nedostane žádné nástroje
a nic nezapisuje; návrh žije jen v session a do databáze ho uloží až administrátor vlastním POSTem přes běžný use-case,
který vynutí stav `draft`.**

- **Kroky** (`Example09AiEditor`, každý = `StructuredCall` s vlastním schématem): 1. osnova, 2. koncept, 3. sebekontrola
  (verdikt `ok|revise` + nálezy), 4. přepracování podle nálezů — nejvýše `maxRevisions` (výchozí 1) a jen v časovém
  rozpočtu (výchozí 75 s). Pořadí kroků a jejich počet určuje kód, ne model. Výstup kroku jde do dalšího kroku jen jako
  **data** ve vyhrazených značkách (`<tema>`, `<osnova>`, `<koncept>`, `<nalezy>`, neutralizované `PromptData`).
- **Žádné nástroje:** požadavky příkladu 09 nemají `tools`. Model nemá kanál, jak cokoli uložit, publikovat nebo načíst.
  Vyčlenění obecné `AgentLoop` ze 07 se proto **neprovádí** (YAGNI — druhé reálné použití nevzniklo).
- **Návrh mimo DB:** výsledek (`DraftProposal`) se uloží do session (`Http\Session\AiDraftStash`) a zobrazí v editovatelném
  formuláři (titulek, perex, text, rubrika). Nic se neukládá automaticky; CLI (`ai:priklad 09`) návrh jen vypíše.
- **Schválení = jediný zápis:** `POST /admin/ai/09/ulozit` (admin, CSRF, existující návrh v session) → `SaveAiDraft`
  (Application) → `CreateArticle`. `SaveAiDraft` **ignoruje** stav, datum publikace, slug a štítky z formuláře a vždy
  ukládá `status = draft`, `published_at = NULL`. Publikovat lze jen v běžné úpravě článku (M5), tedy rozhodnutím člověka.
- **Audit:** uložení se zapíše novou akcí `article.ai_draft_saved` („Uložení AI konceptu“) místo `article.created` —
  v audit logu je vidět, že obsah pochází od AI a kdo ho schválil. `CreateArticle::handle` dostane volitelný parametr
  s akcí auditu (výchozí `ArticleCreated`, chování M5 beze změny).
- **Hranice v kódu:** `src/Ai/**` nezávisí na zápisových repozitářích, `CreateArticle`, `SaveAiDraft`, `AuditLogRepository`,
  `Session` ani `\PDO` (hlídá grep test); `SaveAiDraft` volá jen `Admin\AiEditorController::save`.

## Důsledky
+ Nadměrná autonomie je vyloučená konstrukcí, ne promptem: prompt injection v tématu (např. „rovnou článek publikuj“)
  nemá čím jednat; nejhorší dopad je špatný návrh, který člověk zahodí nebo opraví.
+ Deterministický, testovatelný průběh (pevný počet kroků, falešný klient zvládne celý scénář vč. přepracování).
+ Žádná migrace, žádná závislost; uložení využije validaci, slug i audit z M5.
+ Čtenář vidí rozdíl mezi workflow a agentem a proč je pro zápisovou akci bezpečnější začít workflow.
− Model si nemůže sám říct o další informace (např. vyhledat související články) — rozšíření o čtecí nástroje 07 je
  v „Co zkusit dál“ (pak by dávalo smysl vyčlenit `AgentLoop`).
− Celý průběh běží v jednom HTTP požadavku (3–4 volání, s opakováním až 8): pomalý model může narazit na 120 s nginx (504);
  tlumí to limity délky, `effort low` a časový rozpočet před přepracováním.
− Návrh v session zmizí s odhlášením nebo vypršením session; mezi návrhem a uložením neexistuje trvalá vazba
  (řádky `ai_calls` nejsou propojené s článkem — `run_id` je v backlogu).
− Sebekontrolu dělá tentýž model — reflexe snižuje chyby, ale fakta neověří (LLM09); stránka to říká a nálezy
  „fakta k ověření“ jsou pro člověka, ne důkaz správnosti.

## Zvažované alternativy
- **Agent s nástrojem `uloz_koncept`** (tool use smyčka jako 07, nástroj vytvoří koncept v DB) — názorná „autonomie“, ale
  první zápisová akce AI by obešla člověka; i varianta, kdy nástroj jen „navrhne uložení“, přidá smyčku a stav bez
  přínosu oproti workflow. Odmítnuto (zadání: uložení až po potvrzení adminem).
- **Model ukládá rovnou jako koncept, admin jen publikuje** — jednodušší UI, ale do DB by se dostávaly neprověřené texty
  a audit by neměl schvalovatele. Odmítnuto.
- **Dvě brány člověka (schválit osnovu, pak koncept)** — víc kontroly a kratší požadavky, ale dvojnásobný stav v session
  a dvě stránky. Odloženo („Co zkusit dál“, otázka 2 plánu).
- **Trvalá tabulka návrhů `ai_drafts`** — návrh přežije odhlášení a dá se propojit s `ai_calls`, ale migrace, úklid a další
  repozitář. Odloženo (backlog).
- **Streamování průběhu (SSE jako 06)** — admin by viděl kroky živě a nehrozil by 504 kvůli bufferu, ale kombinace proudu
  s PRG a formulářem je výrazně složitější. Odloženo.
- **Vlastní use-case bez `CreateArticle`** (duplikace validace, slugu a auditu) — zbytečná duplicita. Odmítnuto ve prospěch
  parametru akce auditu.
