# Redakční systém, který napsali agenti

Jednoduchý redakční systém (CMS) v čistém PHP 8.4, MariaDB 11.8 a Dockeru. Celý ho staví
tým agentů v Claude Code, člověk zadává a schvaluje. Podrobný návod je v
[`docs/tutorial.html`](docs/tutorial.html), pravidla pro agenty v [`AGENTS.md`](AGENTS.md)
a [`CLAUDE.md`](CLAUDE.md).

## Použité technologie, funkcionality a dovednosti
Řazeno od nejdůležitějšího (to, bez čeho aplikace nefunguje a co ji definuje) po podpůrné nástroje.
Verze jsou ty, které běží v prostředí (`composer.json`, `compose.yaml`, `docker/`).

### 1. Jádro aplikace
| Technologie | Verze | K čemu |
|---|---|---|
| PHP | 8.4 (`~8.4.1`, v kontejneru 8.4.26), PHP-FPM na Debianu 13 (trixie) | celá aplikace: čisté OOP bez frameworku, `strict_types`, `final readonly` třídy, typované konstanty, enumy |
| MariaDB | 11.8.9 | databáze (články, rubriky, štítky, uživatelé, audit log, záznamy AI volání), kolace `utf8mb4_czech_ci` |
| PDO + `pdo_mysql` | součást PHP | přístup k databázi, výhradně prepared statements, SQL jen ve třídách `*Repository` |
| Docker + Docker Compose | – | celé prostředí (PHP, nginx, DB, Adminer, MCP servery); na hostiteli není PHP ani Node |
| nginx | 1.30.5 (alpine) | webový server před PHP-FPM, port 8080 |

### 2. Funkcionality aplikace
1. **Veřejná část:** titulní stránka se stránkováním (10 článků na stranu), detail článku, kontrola zdraví `/zdravi`.
2. **Fulltextové vyhledávání** (`/hledani`) v titulku, perexu i textu článků se zvýrazněním výrazu; formulář na hlavní stránce i v sidebaru všech stránek.
3. **Administrace** (jen role `admin`): seznam, vytvoření, úprava a smazání článků (koncept, publikováno, archiv, datum zveřejnění), každý článek má rubriku a štítky.
4. **Přihlášení:** hesla `password_hash` s Argon2id, omezení pokusů o přihlášení, účet admina vytváří jen konzole.
5. **Audit log:** záznam změn s filtrem podle akce a data (časy v UTC, zobrazení v Europe/Prague).
6. **Vlastní Markdown renderer** pro text článků (ADR-0005), bezpečné HTML.
7. **Vlastní infrastruktura bez frameworku:** DI kontejner, router, pipeline middlewarů, migrátor databáze, šablony v čistém PHP s escapováním `e()`.
8. **Konzole** `bin/konzole`: migrace, `admin:vytvor`, `db:seed`, `ai:priklad NN`, `ai:indexuj`, `mcp:server`.
9. **Bezpečnost:** CSRF token u každého POST, session cookie `HttpOnly; SameSite=Strict`, bezpečnostní hlavičky, tři databázové účty s odstupňovanými právy (DML, DDL, jen čtení).

### 3. AI část (zvláštní kapitola)
Vlastní klient bez SDK a bez Composeru: čisté PHP + cURL (ADR-0006, streamování a nástroje ADR-0008).

| Oblast | Konkrétně |
|---|---|
| Poskytovatel | **Claude API (Anthropic) – Messages API**, hlavička `anthropic-version: 2023-06-01`; přepínač `AI_PROVIDER=falesny\|anthropic` |
| Modely | `claude-sonnet-5-5` (generování textu, 2 / 10 USD za milion vstupních / výstupních tokenů), `claude-haiku-4-5-20251001` (levná klasifikace, 1 / 5 USD); ceník v `config/ai-models.php` |
| Falešný klient | `FakeLlmClient` – všechny příklady i testy se dají spustit bez API klíče a bez sítě |
| Rozhraní | `LlmClient` a `StreamingLlmClient`; dekorátor `MeteredLlmClient` měří tokeny a cenu |
| Structured output | JSON podle schématu + validace v PHP a opakování s chybovou zprávou (`StructuredCall`) |
| Tool use | agent s nástroji `hledej_clanky` a `nacti_clanek` (jen čtení), smyčka volání nástrojů |
| Streaming | SSE (Server-Sent Events): `AnthropicStreamReader`, `SseParser`, `SseWriter`; text se zobrazuje průběžně |
| Prompt caching | `cache_control: ephemeral` na systémovém promptu; cena zahrnuje zápis i čtení cache |
| Prompty | verzované Markdown soubory v `src/Ai/Prompts/` (role, pravidla, formát výstupu), načítá `PromptLibrary` |
| Náklady | tabulka `ai_calls` (jen metadata: tokeny, cena, trvání, stav – nikdy texty), denní limit tokenů `AI_DENNI_LIMIT_TOKENU` |
| Bezpečnost LLM | obsah článků i výstup modelu je nedůvěryhodný vstup (obrana proti prompt injection), validace a escapování výstupu, nástroje jen čtou |

**Deset AI příkladů** (`docs/ai-priklady/`, konzole `ai:priklad NN`, v prohlížeči `/admin/ai`):

| # | Příklad | Co ukazuje |
|---|---|---|
| 01 | Perex na jedno kliknutí | první volání API, system prompt, `max_tokens`, cena |
| 02 | SEO titulek a meta popis | structured output (JSON), validace, opakování |
| 03 | Štítky a rubrika | klasifikace levným modelem, enum ve schématu, prompt caching |
| 04 | Kontrola před publikací | tón, osobní údaje, faktická rizika; obrana proti prompt injection |
| 05 | Překlad CZ → EN | zachování Markdownu, porovnání silnějšího a levnějšího modelu |
| 06 | Asistent psaní | streaming odpovědi (SSE) |
| 07 | Zeptej se redakce | tool use: model sám hledá a čte články |
| 08 | Sémantické vyhledávání | embeddingy ve sloupci `VECTOR`, vektorový index, odpověď s ověřenými citacemi (RAG) |
| 09 | AI redaktor | workflow osnova → koncept → sebekontrola → přepracování; člověk ve smyčce, uložení jen jako koncept (LLM06) |
| 10 | MCP server redakce | redakce jako server Model Context Protocol (STDIO): Claude Code čte publikované články třemi nástroji a promptem; model běží v klientovi |

### 4. Kvalita a testování
| Nástroj | Verze | K čemu |
|---|---|---|
| PHPUnit | 13.4 | unit a integrační testy (testy napřed, TDD) |
| PHPStan | 2.2 (level max) | statická analýza |
| PHP-CS-Fixer | 3.95 | styl kódu PSR-12 / PER-CS |
| Composer | 2.x | závislosti, `composer audit` (bezpečnost balíčků), skripty `check`, `test`, `qa` |
| Playwright MCP | 0.0.82 | E2E scénáře v prohlížeči |
| Makefile, git hooky | – | `make up/qa/migrate/seed`, kontrola formátu commitu a PHP před commitem |

### 5. Vývoj týmem AI agentů (Claude Code)
| Prvek | Popis |
|---|---|
| Claude Code | hlavní relace jako vedoucí týmu (orchestrátor), člověk jako product owner schvaluje brány |
| 8 subagentů (`.claude/agents/`) | architekt, databazista, programator, ai-inzenyr, tester, security-reviewer, devops, technicky-spisovatel |
| Skills (`.claude/skills/`) | `feature`, `commit`, `audit`, `retro`, `ai-integrace`, `bezpecnost-owasp`, `db-migrace`, `devops-kontrakt`, `php-oop-standardy`, `tutorial-kapitola` |
| Hooky (`.claude/hooks/`) | hlídání nebezpečných příkazů a chráněných souborů, PHP lint, brána před commitem, DB jen pro čtení |
| MCP servery | context7 (aktuální dokumentace knihoven), Playwright (prohlížeč), MariaDB jen pro čtení (Node 24, Docker) |
| Postup | `AGENTS.md` / `CLAUDE.md`, plán → testy → implementace → ověření → revize → dokumentace, ADR v `docs/adr/` |

### 6. Dovednosti, které projekt procvičuje
1. Objektově orientované PHP 8.4 bez frameworku (vrstvy Domain / Application / Infrastructure / Http / Ai, DI, repository, middleware).
2. Integrace LLM: prompt engineering, structured output, tool use, streaming, řízení nákladů.
3. Bezpečnost webu a LLM aplikací (OWASP Top 10, OWASP Top 10 pro LLM, ASVS).
4. Návrh relačního schématu, migrace a práce s MariaDB.
5. Docker, Docker Compose a nasazovací kontrakt (VPS Debian 13).
6. Testování: TDD, statická analýza, E2E v prohlížeči.
7. Práce s týmem AI agentů: delegace, schvalovací brány, revize, dokumentace (ADR, tutoriál).

## Požadavky na hostiteli
Na svém počítači (Linux nebo WSL2) potřebuješ jen:

- `docker` (s pluginem `docker compose`)
- `git`
- `bash`
- `jq`
- `make`
- `curl`

PHP, Composer, Node ani npm **neinstaluj**. Všechno ostatní běží v kontejnerech.
Repozitář měj v Linuxovém souborovém systému (ve WSL2 ne v `/mnt/c`).

## Rychlý start
```bash
git clone <adresa-repozitáře> redakcni-system
cd redakcni-system
git config core.hooksPath .githooks   # git hooky (formát commitu, kontrola PHP)
make up                               # .env, sestavení obrazů, composer install, start služeb
make qa                               # php -l, PHP-CS-Fixer, PHPStan, PHPUnit, composer audit
make migrate                          # vytvoří databázové schéma (migrace z database/migrations/)
make seed                             # nahraje ukázková data (16 článků; jen dev, opakovat lze bezpečně)
make mcp                              # jednorázově předstáhne obrazy MCP serverů (Playwright, MariaDB)
```

`make up` při prvním spuštění zkopíruje `.env.example` do `.env` a počká, až jsou všechny
služby zdravé. Další cíle vypíše `make help`.

| Co | Adresa |
|---|---|
| Aplikace (titulní stránka, 10 článků na stranu) | <http://localhost:8080/> |
| Starší články (stránkování) | <http://localhost:8080/?strana=2> |
| Detail článku | <http://localhost:8080/clanek/ukazka-markdownu> |
| Kontrola zdraví | <http://localhost:8080/zdravi> |
| Administrace (jen přihlášený admin) | <http://localhost:8080/admin> |
| Přihlášení do administrace | <http://localhost:8080/admin/prihlaseni> |
| Správa článků (seznam, úprava, smazání) | <http://localhost:8080/admin/clanky> |
| Nový článek | <http://localhost:8080/admin/clanky/novy> |
| Audit log (jen admin, filtr podle akce a data) | <http://localhost:8080/admin/audit> |
| AI nástroje (přehled, spotřeba tokenů, poslední volání) | <http://localhost:8080/admin/ai> |
| AI příklad 01–05 (např. perex) | <http://localhost:8080/admin/ai/01> |
| AI příklad 06, asistent psaní se streamováním (admin) | <http://localhost:8080/admin/ai/06> |
| AI příklad 07, zeptej se redakce (admin) | <http://localhost:8080/admin/ai/07> |
| AI příklad 08, sémantické vyhledávání s citacemi (admin) | <http://localhost:8080/admin/ai/08> |
| AI příklad 09, AI redaktor s člověkem ve smyčce (admin) | <http://localhost:8080/admin/ai/09> |
| AI příklad 10, MCP server redakce: návod k připojení (admin, jen informace) | <http://localhost:8080/admin/ai/10> |
| Adminer (správa databáze) | <http://localhost:8081> |

Správná odpověď aplikace je `{"stav":"ok","db":"ok"}`. Do Admineru se přihlásíš
serverem `db`, uživatelem `redakce_app` a heslem `DB_PASSWORD` z `.env`.

## První administrátor
Do administrace se nelze zaregistrovat, účet vytvoří příkaz v konzoli (po `make migrate`):

```bash
docker compose exec -T app php bin/konzole admin:vytvor --email=admin@example.cz --jmeno=Administrátor --heslo=dlouhe-heslo-12
```

Heslo musí mít aspoň 12 znaků. Bez `--heslo` se heslo vygeneruje a vypíše jen jednou. Pak se přihlas na
<http://localhost:8080/admin/prihlaseni>. Heslo zadané přes `--heslo` zůstane v historii shellu, pro skutečné
nasazení proto použij vygenerované.

Session cookie má v dev režimu `HttpOnly; SameSite=Strict`, ale ne `Secure` (vývoj běží přes HTTP).
Je to záměr: dev compose proměnnou `SESSION_COOKIE_SECURE` kontejneru `app` vůbec nepředává a soubor `.env`
kontejner nevidí (hodnoty dostává jen přes `environment:` v `compose.yaml`), takže řádek v `.env` by nic nezměnil.
Produkční `compose.prod.yaml` (M9) za HTTPS musí dát `SESSION_COOKIE_SECURE: "1"` do `environment:` služby `app`
(čte ji jen `config/container.php`), jinak prohlížeč cookie posílá i po HTTP.

**Časy:** audit log (`audit_log.created_at`) se v databázi ukládá v UTC, protože čas doplňuje databáze. Na stránce
`/admin/audit` se zobrazuje v pražském čase (Europe/Prague), takže se hodnota v Admineru liší o 1–2 hodiny.
Pravidlo, proč a kde se čas převádí, je v [ADR-0007](docs/adr/0007-casy-v-databazi-utc-vs-praha.md).

## AI příklady (M6)
Prvních pět AI příkladů (perex, SEO, štítky a rubrika, kontrola před publikací, překlad) běží **bez API klíče**
přes falešný klient (`AI_PROVIDER=falesny`, nic se neúčtuje, cena je jen orientační). Spuštění z konzole
(po `make migrate`):

```bash
docker compose exec -T app php bin/konzole ai:priklad 01
docker compose exec -T app php bin/konzole ai:priklad 04 --clanek=demo-injection
docker compose exec -T app php bin/konzole ai:priklad 05 --model=claude-haiku-4-5-20251001
```

Volby: `--clanek=demo|demo-injection|ID` (výchozí `demo`), `--model=ID` (jen příklad 05). V prohlížeči
se přihlas jako admin a otevři `/admin/ai`. Proměnné v `.env` (výchozí hodnoty jsou v `.env.example`):

| Proměnná | Význam |
|---|---|
| `AI_PROVIDER` | `falesny` (výchozí, bez sítě) nebo `anthropic` (skutečné Claude API) |
| `ANTHROPIC_API_KEY` | klíč k API, potřebný jen pro `anthropic`; **jen v `.env`, nikdy v repozitáři** |
| `AI_MODEL` | model pro generování textu (výchozí `claude-sonnet-5-5`) |
| `AI_MODEL_LEVNY` | levnější model pro klasifikaci (výchozí `claude-haiku-4-5-20251001`) |
| `AI_DENNI_LIMIT_TOKENU` | denní limit tokenů všech volání (výchozí `200000`) |

Skutečné API zapneš tak, že do `.env` doplníš `AI_PROVIDER=anthropic` a `ANTHROPIC_API_KEY=…` a spustíš `make up`.
Kvůli ceně se po každém volání zapisují do tabulky `ai_calls` jen metadata (tokeny, cena, trvání), nikdy texty.
Výklad je v kapitole „AI jádro“ v [`docs/tutorial.html`](docs/tutorial.html).

## AI příklady 06 a 07: streaming a nástroje (M7)
Příklad 06 (asistent psaní) vypisuje odpověď modelu **živě** přes Server-Sent Events a jde přerušit. Příklad 07
(„Zeptej se redakce“) nechá model samotný hledat a číst publikované články dvěma **čtecími** nástroji.
Oba běží bez API klíče přes falešný klient (spuštění po `make migrate`, příklad 07 chce `make seed`):

```bash
docker compose exec -T app php bin/konzole ai:priklad 06 --akce=zkrat
docker compose exec -T app php bin/konzole ai:priklad 06 --akce=zjednodus --text="Vlastní odstavec."
docker compose exec -T app php bin/konzole ai:priklad 07
docker compose exec -T app php bin/konzole ai:priklad 07 --otazka="Co redakce píše o Dockeru?"
```

Volby: `--akce=pokracuj|zkrat|zjednodus` a `--text=…` (06, text nejvýše 5 000 znaků), `--otazka=…` (07, 3 až 500 znaků).
V prohlížeči se přihlas jako admin a otevři `/admin/ai/06` (živý výstup s tlačítkem „Přerušit“, vyžaduje JavaScript)
nebo `/admin/ai/07` (odpověď s kroky agenta a zdroji).

Co je dobré vědět:

- Proud jde do prohlížeče přes `fetch` s `POST` (ne `EventSource`, ten umí jen `GET`). Před streamem se uvolní zámek session
  a vypne bufferování (PHP i nginx).
- Přerušené volání se v `ai_calls` zapíše se stavem „Přerušeno“ a výstupní tokeny se jen odhadují (znaky / 4).
- Příklad 07 je omezený: 5 volání modelu, 3 nástroje na krok, 60 s. Nástroje jen čtou publikované články; text článků je
  nedůvěryhodný (nepřímá prompt injection), proto nic nezapisují.
- Rate limit těchto endpointů zatím chybí (backlog).

Výklad je v kapitole „Streaming a nástroje“ v [`docs/tutorial.html`](docs/tutorial.html#m7), rozhodnutí v
[ADR-0008](docs/adr/0008-streaming-a-nastroje-llm.md), podklady v `docs/ai-priklady/06.md` a `07.md`.

## AI příklad 08: sémantické vyhledávání s citacemi (M7b)
Příklad 08 najde publikované články podle **významu** otázky (vektory ve sloupci MariaDB `VECTOR`) a Claude z nich odpoví s ověřenými
citacemi. Běží bez API klíče i bez Ollamy (falešní klienti; falešný embedding rozumí jen shodě slov). Po `make migrate` a `make seed`:

```bash
make index                                  # = ai:indexuj, zaindexuje publikované články (opakovat lze, přepočítá jen změněné)
docker compose exec -T app php bin/konzole ai:priklad 08
docker compose exec -T app php bin/konzole ai:priklad 08 --otazka="Proč se mám před zkouškou pořádně vyspat?"
```

V prohlížeči (admin): <http://localhost:8080/admin/ai/08>, tlačítko „Aktualizovat index“ a pak „Najít a odpovědět“.

Skutečné embeddingy přes lokální Ollamu (profil `ai-local`, stáhne obraz ~3,8 GB a model `embeddinggemma` 622 MB):

```bash
make ai-local                 # spustí Ollamu a stáhne model
# do .env doplň EMBED_PROVIDER=ollama, pak:
make up
make index                    # po změně EMBED_PROVIDER vždy zaindexuj znovu
```

Živé ověření s Ollamou zatím nebylo provedeno. Výklad je v kapitole [M7b](docs/tutorial.html#m7b), rozhodnutí v
[ADR-0009](docs/adr/0009-semanticke-vyhledavani-embeddingy-a-citace.md), podklady v `docs/ai-priklady/08.md`.

## AI příklad 09: AI redaktor s člověkem ve smyčce (M7c)
Příklad 09 z tématu navrhne osnovu, napíše koncept, zkontroluje ho a jednou přepracuje. Kroky určuje kód, model nemá žádné nástroje
a **nic neukládá**: uložit návrh jako koncept smí jen admin tlačítkem a publikovat jen v úpravě článku (OWASP LLM06). Běží bez API klíče
(falešný klient):

```bash
docker compose exec app php bin/konzole ai:priklad 09
docker compose exec app php bin/konzole ai:priklad 09 --tema="Jak Docker usnadňuje práci malé redakce"
```

V prohlížeči (admin): <http://localhost:8080/admin/ai/09>, „Navrhnout koncept“ a pak „Uložit jako koncept“. Se skutečným API zatím neměřeno
(odhad ≈ 0,06 USD a 50 až 90 s za návrh). Výklad je v kapitole [M7c](docs/tutorial.html#m7c), rozhodnutí v
[ADR-0010](docs/adr/0010-ai-redaktor-workflow-se-schvalenim.md), podklady v `docs/ai-priklady/09.md`.

## AI příklad 10: MCP server redakce (M7d)
Příklad 10 udělá z redakce server **Model Context Protocol** přes STDIO. Claude Code (model běží u klienta, ne v aplikaci) smí číst **jen publikované
články** třemi nástroji (`hledej_clanky`, `nacti_clanek`, `statistiky`) a promptem `navrhni_clanek`. Nic nezapisuje a nevolá žádné AI API. Je to první
běhová závislost projektu: oficiální SDK `mcp/sdk` (0.x, experimentální), izolované v `src/Mcp/`. Ruční zkouška bez Claude Code:

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"ukazka","version":"1.0"}}}' \
  '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"statistiky","arguments":{}}}' \
  | docker compose exec -T app php bin/konzole mcp:server
```

Do Claude Code ho připojí člověk sám, v samostatném prázdném adresáři `~/redakce-mcp` (rozsah `local` platí pro všechny relace Claude Code spuštěné v daném
adresáři, takže rozhoduje izolace adresářem; `.mcp.json` se nemění, server nepatří do relací s automaticky povoleným Bashem ani do relací agentů v repozitáři):

```bash
KOREN="$PWD"
mkdir -p ~/redakce-mcp && cd ~/redakce-mcp
claude mcp add --transport stdio --scope local redakce -- docker compose -f "$KOREN/compose.yaml" exec -T app php bin/konzole mcp:server
cd ~/redakce-mcp && claude mcp list     # ověření; Claude Code se k serveru spouští z tohoto adresáře
```

Na stdout smí jít jen protokol (logy na stderr). Text článků je nedůvěryhodný a jde do silnějšího agenta než v příkladu 07, proto platí izolace adresářem
a ruční potvrzování volání. **Živý test v Claude Code zatím neproběhl.** Návod je na <http://localhost:8080/admin/ai/10>, výklad v kapitole
[M7d](docs/tutorial.html#m7d), rozhodnutí v [ADR-0011](docs/adr/0011-mcp-server-redakce-sdk-a-stdio.md), podklady v `docs/ai-priklady/10.md`.

Testy: `make test` (nebo `make qa`). Integrační testy schématu mažou a znovu vytvářejí tabulky
v databázi `redakce_test`, proto dvě sady testů nesmí běžet paralelně nad `redakce_test`.

Zastavení prostředí: `make down`. Data databáze zůstanou v Docker volume `t360_db_data`.

## Claude Code
**Claude Code spouštěj z kořene repa** (`cd redakcni-system && claude`). Jen tam se načte
`.claude/settings.json` s oprávněními a hooky a `.mcp.json` s MCP servery. Spustíš-li ho jinde,
pojistky neplatí. Po spuštění ověř `/hooks` a `/mcp`. Více v [`START-ZDE.md`](START-ZDE.md).

Agenti i hooky používají PHP jen v kontejneru `app`, takže při práci s agenty musí
prostředí běžet (`make up`).

## Databáze: init skript a hesla
Při **prvním** vytvoření volume `t360_db_data` spustí MariaDB skript
[`docker/mariadb/init/01-uzivatele.sh`](docker/mariadb/init/01-uzivatele.sh). Vytvoří databáze
`redakce` a `redakce_test` (kolace `utf8mb4_czech_ci`) a tři uživatele: `redakce_app` (DML),
`redakce_migrace` (DDL) a `redakce_cteni` (jen `SELECT`). Hesla bere z `.env`.

Skript už podruhé neběží. Změna hesla v `.env` po vytvoření databáze proto **nezmění**
heslo v databázi a aplikace se přestane připojovat. Hesla v `.env.example` jsou ukázková
a pro vývoj stačí. Chceš-li začít znovu s novými hesly, smaž volume: `make down` a
`docker volume rm t360_db_data` (smažeš tím všechna data).

## Kde co leží
| Cesta | Obsah |
|---|---|
| `src/`, `public/`, `tests/` | aplikace, front controller, testy |
| `config/` | kompoziční kořen: `container.php` (kontejner), `routes.php` (trasy) |
| `templates/` | PHP šablony (`layout`, `home`, `article`, `error`, `admin/`, `admin/articles/`, `admin/ai/`), výstup přes `e()` |
| `database/seeds/` | ukázková data (`demo_content.php`), nahrává je `make seed` |
| `database/migrations/` | migrace schématu (`RRRRMMDDHHMM_popis.php`) |
| `bin/konzole` | CLI: `migrace:spust`, `migrace:vrat [--kroky=N]`, `migrace:stav`, `admin:vytvor`, `db:seed`, `ai:priklad NN`, `ai:indexuj`, `mcp:server` |
| `docker/`, `compose.yaml`, `Makefile` | prostředí v Dockeru |
| `.claude/`, `.mcp.json`, `.githooks/` | tým agentů, hooky, MCP servery |
| `docs/` | zadání, architektura, ADR, plány, tutoriál |
