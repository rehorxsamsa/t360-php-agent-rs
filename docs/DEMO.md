# Jak prezentovat aplikaci programátorům

Scénář prezentace redakčního systému `t360-php-agent-rs` pro **programátory**. Aplikace má dvě vrstvy,
které se dají ukázat zvlášť, a největší hodnota je v jejich propojení:

1. **Jak byla postavena:** tým AI agentů v Claude Code (role, skills, hooky, brány, paměť).
2. **Co obsahuje:** architektura AI v čistém PHP (port `LlmClient`, dekorátor, structured output, tool use, streaming, náklady, bezpečnost).

Doporučená délka: **60 minut** (45 min výklad a ukázky, 15 min diskuse). Zkrácená verze (15 min) je na konci,
k ní patří stručná prezentace [`t360-prezentace.pptx`](t360-prezentace.pptx).
Související dokumenty: [`ARCHITECTURE.md`](ARCHITECTURE.md) (architektura), [`../README.md`](../README.md)
(technologie), [`../CLAUDE.md`](../CLAUDE.md) a [`../AGENTS.md`](../AGENTS.md) (pravidla týmu), [`adr/`](adr/).

## 0. Hlavní sdělení

> Žádný rozumný programátor dnes nechce „AI, která napíše aplikaci“. Chce **řízený proces**, ve kterém AI
> dělá práci s jasnými rolemi, bránami a pojistkami, a **bezpečnou architekturu**, ve které je LLM
> jen zaměnitelná závislost za rozhraním.

Tři věci, které by si měl divák odnést:

1. **Agent není chatbot, ale role** s vlastním modelem, nástroji, pamětí a hranicemi (a dá se verzovat v gitu).
2. **Skill je znalost nebo postup**, hook je **deterministická pojistka**; kontrolu nikdy nesvěřuj jen promptu.
3. **LLM v aplikaci je port a adaptér**: dá se vyměnit, otestovat bez sítě, změřit a omezit.

## 1. Příprava (před prezentací)

```bash
git clone <repo> && cd t360-php-agent-rs
make up && make migrate && make seed         # prostředí, schéma, 16 ukázkových článků
make qa                                       # ukázat zelenou bránu (pozor: testy M7 jsou rozpracované)
docker compose exec -T app php bin/konzole admin:vytvor --email=admin@example.cz --jmeno=Demo --heslo=dlouhe-heslo-12
```

Otevřít předem: <http://localhost:8080/> (veřejný web), <http://localhost:8080/admin/ai> (AI nástroje),
Adminer <http://localhost:8081>, editor se složkou `.claude/`, terminál s `claude` spuštěným **z kořene repa**
(jinak se nenačtou `settings.json`, hooky a `.mcp.json`).

AI běží v režimu `AI_PROVIDER=falesny`: **demo funguje bez API klíče, bez sítě a zdarma**. Pokud chcete ukázat
skutečné volání, dejte do `.env` `AI_PROVIDER=anthropic` a `ANTHROPIC_API_KEY=…` (klíč nikdy nepromítat na plátno).

Co je potřeba vědět předem, ať vás nic nepřekvapí:

- M7 (příklady 06 a 07, streaming a tool use) je rozpracovaný. Třídy a testy existují, ale trasy `/admin/ai/06`
  a `/admin/ai/07` zatím nejsou zapojené a část testů padá. Ukažte je **v kódu a v testech**, ne v prohlížeči,
  nebo to otevřeně použijte jako ukázku „rozpracovaná funkce v pipeline“.
- Hooky `bash-strazce.sh` a `chran-soubory.sh` jsou v tomto repu záměrně ve **„DEMO REŽIMU“** (kontroly vypnuté,
  zůstává jen zákaz `git push`). Přísnější verze je v historii gitu. Řekněte to nahlas: pojistky jsou nastavitelné
  a v ostrém projektu patří zpět.

## 2. Osnova (60 minut)

| Čas | Blok | Co ukázat |
|---|---|---|
| 0–5 | Motivace a hlavní sdělení | jedna věta, co je aplikace a jak vznikla (52 commitů, 8 agentů) |
| 5–20 | **Agenti a skills** | složka `.claude/`, role týmu, pipeline `/feature`, brány |
| 20–30 | **Hooky, oprávnění, paměť** | pojistky, allowlist, audit stopa, `/retro` |
| 30–50 | **Architektura AI** | port `LlmClient`, dekorátor, příklady 01–07, náklady, bezpečnost |
| 50–60 | Živé demo a závěr | aplikace v prohlížeči, `/admin/ai`, spuštění z konzole, shrnutí |

## 3. Blok A: tým agentů a skills (15 min)

### 3.1 Princip: vedoucí týmu + specialisté
Hlavní relace Claude Code je **vedoucí (orchestrátor)**, ne programátor. Rozkládá požadavek, deleguje
subagentům, hlídá kvalitu a **zastavuje se u schvalovacích bran**. Člověk je product owner.
Zdroj pravdy: [`CLAUDE.md`](../CLAUDE.md) (orchestrace) a [`AGENTS.md`](../AGENTS.md) (pravidla pro každého
agenta, nástrojově nezávislý formát, který čtou i Codex, Cursor a Gemini CLI).

Ukažte složku:

```
.claude/
  agents/        8 subagentů (frontmatter: model, nástroje, paměť, skills, vlastní hooky a MCP)
  skills/        10 skills (4 postupy spouštěné člověkem + 6 znalostních příruček)
  rules/         pravidla načítaná jen u odpovídajících souborů (paths: src/**/*.php …)
  hooks/         deterministické pojistky (bash + jq)
  agent-memory/  trvalá paměť agentů (commituje se)
  settings.json  oprávnění allow/ask/deny + hooky + env
.mcp.json        MCP: context7 (aktuální dokumentace), github (jen čtení)
```

### 3.2 Role týmu a proč mají různé modely

| Agent | Model | Nástroje (zúžené) | Důvod |
|---|---|---|---|
| `architekt` | opus | čtení + zápis plánů a ADR, **nepíše kód** | návrh stojí na úsudku, ne na rychlosti |
| `programator` | opus | čtení, edit, bash, context7; **Stop hook** s rychlou kontrolou | kód podle schváleného plánu a padajících testů |
| `ai-inzenyr` | sonnet | + WebFetch, context7; skills `ai-integrace`, `bezpecnost-owasp` | vše kolem LLM (klient, prompty, tool use, streaming) |
| `databazista` | sonnet | **MCP `mariadb-cteni` (jen SELECT)**, skill `db-migrace` | schéma = migrace, přímé SQL jen čte |
| `tester` | opus | **MCP Playwright** (E2E v prohlížeči) | testy napřed (TDD) a ověření po implementaci |
| `security-reviewer` | opus | **jen čtení** (Read, Grep, Glob, Bash), skill `bezpecnost-owasp` | revize `git diff`, OWASP + OWASP LLM; nic neupravuje |
| `devops` | sonnet | skill `devops-kontrakt`; nepushuje, nenasazuje | Docker, Makefile, CI podle kontraktu |
| `technicky-spisovatel` | sonnet | skill `tutorial-kapitola` | tutoriál, README, slovníček |

Co zdůraznit programátorům:
- **Nejmenší nutná oprávnění:** revizor nemůže zapisovat, databázista nemůže psát SQL, architekt nepíše kód.
  Oddělení rolí je pojistka proti tomu, aby si agent „sám schválil vlastní práci“.
- **Model podle práce:** drahý model tam, kde se rozhoduje, levnější tam, kde se vykonává podle zadání.
- **Subagent nevidí konverzaci.** Vedoucí mu musí dát cíl, cestu k plánu, soubory, akceptační kritéria a
  co má vrátit. Je to stejná disciplína jako dobré zadání člověku.
- **Výstup agenta je tvrzení k ověření**, ne fakt. Ověřuje se testem nebo příkazem.
- Když subagent selže dvakrát, vedoucí **nezkouší potřetí stejně**: změní zadání nebo se zeptá člověka.

### 3.3 Skills: dva druhy

| Druh | Skills | Chování |
|---|---|---|
| **Postupy** (spouští člověk, `disable-model-invocation: true`) | `/feature`, `/commit`, `/audit`, `/retro` | opakovatelný proces s kroky a branami |
| **Znalosti** (načítá je agent podle popisu) | `ai-integrace`, `bezpecnost-owasp`, `php-oop-standardy`, `db-migrace`, `devops-kontrakt`, `tutorial-kapitola` | domluvené standardy a kontrakty v jednom místě |

`/feature <popis>` je pipeline jedné funkce. **Doporučené živé ukázky:** ukázat soubor
[`.claude/skills/feature/SKILL.md`](../.claude/skills/feature/SKILL.md), pak (volitelně) spustit na malé funkci:

```
1. Plán          architekt → docs/plan/NNN-nazev.md                  ⛔ BRÁNA 1 (schvaluje člověk)
2. Testy napřed  tester (padající testy z akceptačních kritérií) ∥ databazista (jen když se mění schéma)
3. Implementace  programator a/nebo ai-inzenyr (nezávislé části paralelně)
4. Ověření       tester: make qa + E2E v prohlížeči (selže → zpět implementátorovi, max 3 kola)
5. Revize        security-reviewer nad git diff (Kritické/Vysoké se opravují, max 2 kola)
6. Dokumentace   technicky-spisovatel
7. Report        co se změnilo, jak ověřit, výsledky testů, rizika    ⛔ BRÁNA 2
8. Commit        /commit (Conventional Commits, do main, bez push)
```

Ukázkový příběh z tohoto repa: v jedné relaci vznikly fulltextové vyhledávání, kód projektu v hlavičce,
README a architektura. Malé funkce šly mimo plnou pipeline (v `CLAUDE.md` je ale pipeline normou), **tuto
odchylku rovnou pojmenujte**: je to přesně rozhodnutí, které má dělat člověk, a ne agent.

### 3.4 Brány a eskalace
Vedoucí se **musí zeptat člověka**, když: je nutná nová závislost (composer/npm/docker image), mění se
architektura (ADR), maže se data nebo mění migrace už v `main`, mění se `.github/workflows`, je požadavek
nejednoznačný, nebo by řešení porušilo `AGENTS.md`.

### 3.5 Plány a ADR jako paměť projektu
`docs/plan/001…008` (každý se `Stav: návrh | schváleno | hotovo`) a `docs/adr/0001…0008` (rozhodnutí se
nemažou, nahrazují se novým ADR). Pro programátory je to nejcennější artefakt: agenti nezačínají od nuly,
čtou `docs/plan/` a `git log` (to dělá i hook `SessionStart`).

## 4. Blok B: hooky, oprávnění, paměť (10 min)

### 4.1 Tři různé mechanismy, tři různé záruky

| Mechanismus | Záruka | Příklad v repu |
|---|---|---|
| **Prompt / CLAUDE.md** | doporučení, model ho může obejít | „komunikuj česky“, konvence kódu |
| **Oprávnění** (`settings.json`) | tvrdá hranice nástrojů | `deny`: `git push`, `sudo`, `php`, `composer`, `npm`, čtení `.env`; `ask`: `git reset`, `docker compose down -v`, `WebFetch` |
| **Hooky** (skripty) | deterministická pojistka při události | `php-lint` po každém zápisu, `commit-brana` (skener tajemství) před commitem |

Zásada z `docs/plan/STAV.md`: **hooky jsou pojistka proti omylům, ne bezpečnostní hranice**. Skutečnou hranicí
je allowlist oprávnění a izolace v Dockeru (na hostiteli není PHP ani Node, vše běží přes `docker compose exec`).

### 4.2 Hooky v repu

| Událost | Hook | Co dělá |
|---|---|---|
| `SessionStart` | `kontext-relace` | vloží stav projektu (větev, commity, necommitnuté změny, rozpracované plány, kontejnery) |
| `PreToolUse(Bash)` | `bash-strazce` (v demo režimu uvolněný), `commit-brana` | hlídání příkazů, skener tajemství před `git commit` |
| `PreToolUse(Edit/Write)` | `chran-soubory` (v demo režimu vypnutý) | ochrana citlivých souborů |
| `PostToolUse(Edit/Write)` | `php-lint` | okamžitý `php -l` v kontejneru; exit 2 = agent chybu uvidí a opraví |
| `PostToolUse`, `Subagent*` | `audit-log` | asynchronní auditní stopa „kdo, co, kdy“ (podklad pro `/retro`) |
| Stop subagenta `programator` | `rychla-kontrola` | agent **nesmí skončit**, dokud neprojde kontrola (pojistka proti nekonečné smyčce: max 3 vrácení) |
| `Stop` | `pripominka-commit` | upozorní člověka na necommitnutou práci |
| `Notification` | `notifikace` | tichá desktopová notifikace |

Poučení k vyprávění: hook na `Stop` agenta mění „slíbil jsem, že to funguje“ na „nemůžu skončit, dokud to funguje“.

### 4.3 Paměť a učení týmu
- `.claude/agent-memory/<agent>/` je trvalá paměť, která se commituje (např. `project_ai-api-fakta.md`
  architekta, `project_opakovane-chyby-tymu.md` revizora).
- `/retro` po milníku vyhodnotí, co agenti dělali špatně a co hooky zachytily, a zapíše poučení do paměti,
  skills a `CLAUDE.md` (sekce „Lekce“, max. 20 řádků, nejstarší se mažou).
- `.claude/rules/*.md` se načítají jen u odpovídajících souborů (`paths:`), takže kontext zůstává malý.

### 4.4 MCP servery
`context7` (aktuální dokumentace knihoven místo paměti modelu), `playwright` (E2E, běží v Dockeru, profil `mcp`),
`mariadb-cteni` (účet jen se `SELECT`), `github` (jen čtení; tato relace ho nemá připojený kvůli tokenu, což je
dobrá ukázka toho, že pipeline na něm nestojí).

## 5. Blok C: architektura AI (20 min)

Podrobnosti viz [`ARCHITECTURE.md`](ARCHITECTURE.md), kapitola 7. Pro prezentaci stačí tento tok:

```
Controller / ai:priklad → ExampleRunner → Example01..07 → LlmClient (port)
                                                   │
                                   MeteredLlmClient (dekorátor: limit, cena, log ai_calls)
                                                   │
                      FakeLlmClient (výchozí, bez sítě) │ AnthropicClient → HttpTransport → cURL
```

### 5.1 Pět designových rozhodnutí, která stojí za vyprávění

1. **Port a adaptér.** Zbytek aplikace zná jen `LlmClient::complete()`. `StreamingLlmClient` přidává `stream()`
   bez rozbití starých příkladů. Výměna poskytovatele je změna jedné řádky v kompozičním kořeni.
2. **Dekorátor místo rozptýlené logiky.** Limit tokenů, výpočet ceny a audit volání jsou na jednom místě
   (`MeteredLlmClient`); nejde je obejít volbou poskytovatele. Rezervuje se `max_tokens`, přeteče-li denní limit,
   volání se vůbec neodešle. Do `ai_calls` jdou **jen metadata**, nikdy texty.
3. **Falešný klient jako first-class občan.** Celá aplikace i testy běží bez klíče a bez sítě. `FakeLlmClient`
   umí i streaming (po přírůstcích) a scénář tool use. To je to, co dělá AI testovatelnou.
4. **Vlastní tenká integrace místo SDK** (ADR-0006): cURL za rozhraním `HttpTransport`. Plná kontrola nad retry
   (500/529, 429 jen s `Retry-After`), mapováním chyb (`LlmErrorType`), cenou i streamem. Cena: údržba při změnách API.
5. **Výstup modelu je nedůvěryhodný vstup.** Schéma JSON nestačí, proto `StructuredCall` výstup znovu validuje
   v PHP, jednou se opraví zpětnou vazbou s chybami a pak selže. `refusal` a `max_tokens` se hlásí zvlášť.

### 5.2 Sedm příkladů jako osnova výkladu

| # | Příklad | Technika | Co ukázat v kódu |
|---|---|---|---|
| 01 | Perex | základní volání, `max_tokens`, cena | `Example01Excerpt`, `docs/ai-priklady/01.md` |
| 02 | SEO titulek | structured output + validace + retry | `StructuredCall`, `FieldRules` |
| 03 | Štítky a rubrika | klasifikace levným modelem (Haiku), `enum`, prompt caching | `Example03Classification` |
| 04 | Kontrola před publikací | **prompt injection** (článek `demo-injection`) | prompt `04-review.md`, test obrany |
| 05 | Překlad CZ → EN | porovnání modelů, zachování Markdownu | výběr modelu, ceník |
| 06 | Asistent psaní | **streaming (SSE)**, přerušení klientem | `AnthropicStreamReader`, `SseParser`, `Response::stream` |
| 07 | Zeptej se redakce | **tool use smyčka** (`hledej_clanky`, `nacti_clanek`) | `Example07AskNewsroom`, `AgentTool` |

### 5.3 Prompty jako kód
`src/Ai/Prompts/*.md`: role, pravidla, formát výstupu a sekce „Data a bezpečnost“ (obsah značek `<clanek>` jsou
data, ne pokyny). Prompty se revidují v diffu jako text a mají číslo verze v hlavičce.

### 5.4 Náklady a bezpečnost (téma, které programátory zajímá nejvíc)

| Téma | Řešení |
|---|---|
| Náklady | ceník v `config/ai-models.php` (Sonnet 5.5 za 2/10 USD, Haiku 4.5 za 1/5 USD na milion tokenů, cena cache), tabulka `ai_calls`, denní limit `AI_DENNI_LIMIT_TOKENU` |
| Prompt injection | data odděleny značkami, nástroje jen pro čtení, ukázkový útok v příkladu 04 |
| Nadměrná agentura | agent má jen dva čtecí nástroje, max. 5 kroků, 3 nástroje na krok, 60 s, výstup nástroje 8 000 znaků |
| Zpracování výstupu | zobrazení přes `e()`, nic se neukládá, validace v PHP |
| Tajemství | klíč jen v `.env`, `#[\SensitiveParameter]`, maskovaný `__debugInfo` |
| Přerušení streamu | `connection_aborted()` → `stopReason 'aborted'`, volání se přesto zaloguje s odhadem spotřeby |

## 6. Živé demo (10 min), doporučené pořadí

1. **Veřejný web** `/`: seznam článků, vyhledávání (formulář nahoře i v sidebaru), zvýraznění výrazu na `/hledani?q=docker`.
2. **Přihlášení** `/admin/prihlaseni`, správa článků, **audit log** `/admin/audit` (každá změna je zapsaná).
3. **AI nástroje** `/admin/ai`: přehled spotřeby, modelů a posledních volání. Spustit příklad 01 a pak 04 s článkem
   `demo-injection` (model injekci ignoruje, výstup je escapovaný).
4. **Konzole:** `docker compose exec -T app php bin/konzole ai:priklad 02` a v `/admin/ai` ukázat nový řádek volání
   (tokeny, cena, trvání). Přepnout model u příkladu 05 a porovnat cenu.
5. **Kód:** otevřít `MeteredLlmClient` a `Example07AskNewsroom` (smyčka a limity) vedle `FakeLlmClient::toolScenario()`.
6. **Test:** `docker compose exec -T app vendor/bin/phpunit tests/Unit/Ai` (bez sítě a bez klíče).
7. **Pipeline:** `docs/plan/008-streaming-a-nastroje.md` (stav „schváleno“) a `docs/adr/0008-…`: ukázat, že
   rozpracovaná funkce má plán, akceptační kritéria a rozhodnutí zapsané dřív než kód.

Plán B, když něco selže: AI demo běží bez sítě, takže jediná závislost je Docker. Nejde-li prohlížeč, použijte
konzoli (`ai:priklad`) a `curl -s localhost:8080/zdravi`.

## 7. Očekávané otázky a doporučené odpovědi

| Otázka | Odpověď |
|---|---|
| „Proč ne Laravel/Symfony a oficiální SDK?“ | Cílem je výuka a průhlednost: vidíte každou vrstvu. Rozhraní `LlmClient` je stejné, ať za ním stojí SDK nebo cURL; výměna je lokální. |
| „Co když agent udělá chybu?“ | Chyby zachytí vrstvy: padající testy napřed, `php-lint`, `rychla-kontrola`, revizor, brány člověka. Žádná vrstva sama nestačí. |
| „Je to bezpečné pouštět agenta s bash?“ | Hranicí je allowlist a Docker izolace, hooky jsou jen pojistka (viz `STAV.md`, tabulka známých rizik). V tomto demu jsou dva hooky záměrně uvolněné. |
| „Kolik to stojí?“ | AI v aplikaci: viz `ai_calls` a denní limit, demo je zdarma. Vývoj agenty: modely podle role (levnější na vykonávání). |
| „Jak se agenti naučí z chyb?“ | Paměť agentů + `/retro` + sekce Lekce v `CLAUDE.md`. |
| „Dá se to přenést na jiný jazyk?“ | Ano: agenti, skills a hooky jsou nezávislé na PHP; mění se standardy, kontrakty a příkazy kvality. |
| „Proč `LIKE` a ne vektorové vyhledávání?“ | Pro tento objem stačí; rozhraní `searchPublished` se dá nahradit FULLTEXT nebo vektory beze změny controlleru. |

## 8. Zkrácená verze (15 minut)

Použijte [`t360-prezentace.pptx`](t360-prezentace.pptx) (8 slidů) a z dema jen tyto kroky:

1. 2 min: složka `.claude/` a tabulka rolí (agenti, skills, hooky).
2. 3 min: pipeline `/feature` s branami (jedna věta o každém kroku).
3. 5 min: architektura AI (port, dekorátor, falešný klient) + jedno spuštění `ai:priklad` s tabulkou nákladů.
4. 3 min: bezpečnost (injection, limity agenta, hooky vs. oprávnění).
5. 2 min: závěr a otázky.

## 9. Kontrolní seznam před prezentací

- [ ] `make up`, `make migrate`, `make seed`, admin účet vytvořen
- [ ] `AI_PROVIDER=falesny` (nebo klíč jen v `.env`, mimo záběr)
- [ ] terminál s `claude` spuštěným z kořene repa, `/hooks` a `/mcp` ověřeny
- [ ] záložky: `/`, `/hledani?q=docker`, `/admin/ai`, `/admin/audit`, Adminer
- [ ] otevřené soubory: `.claude/agents/`, `.claude/skills/feature/SKILL.md`, `CLAUDE.md`, `MeteredLlmClient.php`, `Example07AskNewsroom.php`
- [ ] vědět, co je rozpracované (M7) a co je v demo režimu (dva hooky), a říct to dřív, než se někdo zeptá
