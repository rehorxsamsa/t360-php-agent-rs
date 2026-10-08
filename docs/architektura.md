# Architektura — Redakční systém (t360)

> Udržuje agent `architekt`. Poslední aktualizace: 2026-10-08 (plány 001–004, M1–M4 — hotovo;
> plán 005 M5 administrace článků — implementováno; plán 006 M6 AI jádro — hotovo;
> plán 007 M8 audit log, opravy, tutoriál — implementováno; plán 008 M7 příklady 06–07 — hotovo;
> plán 009 M7b příklad 08 RAG — návrh).
> Rozhodnutí: [ADR-0001](adr/0001-vyvoj-tymem-agentu.md) tým agentů ·
> [ADR-0002](adr/0002-vse-v-dockeru-vcetne-mcp.md) vše v Dockeru vč. MCP ·
> [ADR-0003](adr/0003-anglicke-identifikatory.md) anglické identifikátory ·
> [ADR-0004](adr/0004-anglicke-nazvy-v-databazi.md) anglické názvy v DB ·
> [ADR-0005](adr/0005-vlastni-markdown-renderer.md) vlastní Markdown renderer ·
> [ADR-0006](adr/0006-vlastni-llm-klient-curl.md) vlastní LLM klient přes cURL ·
> [ADR-0007](adr/0007-casy-v-databazi-utc-vs-praha.md) časy v DB: UTC vs. Europe/Prague (navrženo) ·
> [ADR-0008](adr/0008-streaming-a-nastroje-llm.md) streaming a tool use v LLM klientovi ·
> [ADR-0009](adr/0009-semanticke-vyhledavani-embeddingy-a-citace.md) embeddingy (Ollama), MariaDB VECTOR a citace (navrženo).

## 1. Vrstvy aplikace (cílový stav)
Závislosti míří **dovnitř** k `Domain`. `Infrastructure` implementuje rozhraní z `Domain`.

```mermaid
flowchart LR
    subgraph Root["Kompoziční kořen (M2)"]
        FC["public/index.php"]
        BK["bin/konzole"]
        CFG["config/container.php<br/>config/routes.php"]
        DI["App\\Container\\Container<br/>(autowiring přes reflexi)"]
        FC --> CFG
        BK --> CFG
        CFG --> DI
    end
    subgraph Http["App\\Http — Kernel, Routing, Middleware, View, Controller"]
        K[Kernel] --> MW["MiddlewarePipeline<br/>M3 (plán 003): SecurityHeaders → ErrorHandler<br/>→ Routing → Csrf → AdminAccess"]
        MW --> RT["RoutingMiddleware<br/>Router::match"]
        RT --> C["Controller"]
        S["Session (líná služba)<br/>CsrfToken, AuthSession, Flash (M5)"] -.-> MW
        C --> V["TemplateRenderer + e()<br/>templates/*.php<br/>MarkdownRenderer, czech_date (M4)"]
    end
    subgraph Con["App\\Console (M2)"]
        CA["ConsoleApplication → Command<br/>migrace:spust | vrat | stav<br/>admin:vytvor (M3), db:seed (M4)<br/>ai:priklad (M6)"]
    end
    subgraph App["App\\Application — use-cases"]
        UC["AdminAuthenticator, CreateAdmin (M3)<br/>PublishedArticles → ArticlePage (M4)<br/>AdminArticles, Create/Update/DeleteArticle,<br/>ArticleInputValidator (M5)<br/>AuditLogSearch → AuditLogPage (M8)"]
    end
    subgraph Dom["App\\Domain — entity, VO, rozhraní repozitářů"]
        I["rozhraní: DatabaseHealth (M1), UserRepository,<br/>AuditLogRepository (M3), ArticleRepository, Clock (M4)<br/>ArticleAdminRepository, CategoryRepository, TagRepository (M5)<br/>AiCallRepository + AiCall, TokenUsage (M6)<br/>AuditLogRepository::count/search, AuditLogFilter, AuditLogRecord (M8)<br/>ArticleEmbeddingRepository + Embedding, SimilarArticle (M7b)<br/>read modely ArticleSummary / ArticleDetail, Slug (M4)<br/>ArticleData, EditableArticle, AdminArticleSummary (M5)"]
    end
    subgraph Inf["App\\Infrastructure — PDO, migrace, config"]
        R["Pdo*Repository (vč. PdoArticleRepository M4,<br/>PdoArticleAdminRepository, PdoCategory/TagRepository M5,<br/>PdoAiCallRepository M6, PdoArticleEmbeddingRepository M7b)<br/>ConnectionFactory, DatabaseConfig<br/>SystemClock, NativeSession"]
        MG["Migration\\Migrator<br/>PdoMigrationRepository<br/>database/migrations/*.php<br/>Seed + database/seeds/*.php (M4)"]
    end
    subgraph Ai["App\\Ai (M6, plán 006 + ADR-0006)"]
        EX["Examples: ExampleRunner, ExampleRegistry,<br/>Example01…05, StructuredCall, PromptLibrary<br/>AiUsageReport, AiConfig, Cost\\ModelCatalog<br/>M7 (plán 008): Example06WritingAssistant (proud),<br/>Example07AskNewsroom (tool use smyčka)"]
        L["LlmClient + StreamingLlmClient (porty, ADR-0008)<br/>= MeteredLlmClient (denní limit + log ai_calls)<br/>→ FakeLlmClient | AnthropicClient (SseParser)<br/>→ HttpTransport post / stream (CurlHttpTransport)"]
        TL["Tools (M7): hledej_clanky, nacti_clanek<br/>jen čtení publikovaných"]
        EM["Embedding (M7b, ADR-0009): EmbeddingClient (port)<br/>→ FakeEmbeddingClient | OllamaEmbeddingClient<br/>Rag: ArticleIndexer (ai:indexuj)<br/>Example08SemanticSearch (search_result + citace)"]
        EX --> L
        EX --> TL
        EX --> EM
    end
    API["Claude Messages API<br/>api.anthropic.com"]
    OL["Ollama (profil ai-local)<br/>embeddinggemma, http://ollama:11434"]
    DI -.->|sestavuje| K
    DI -.->|sestavuje| CA
    K -.->|"get(controller)"| DI
    C --> UC
    C -.->|"M1–M2: přímo, jen čtení"| I
    UC --> I
    R -.->|implementuje| I
    CA --> MG
    C -->|"M6: Admin\\AiController"| EX
    CA -->|"ai:priklad"| EX
    EX --> I
    L --> I
    TL -->|"ArticleRepository (veřejné čtení)"| I
    EM -->|"ArticleEmbeddingRepository"| I
    L -.->|"HTTPS, jen s AI_PROVIDER=anthropic"| API
    EM -.->|"HTTP v síti Dockeru, jen s EMBED_PROVIDER=ollama"| OL
```

Stav po M1: `Kernel` s pevně zadrátovanou cestou `/zdravi`, rozhraní `Domain\Health\DatabaseHealth`
a jeho PDO implementace. M2 (plán 002) přidal kontejner, router, middleware, šablony, chybové
stránky, konzoli a migrátor. `App\Container` je technické jádro bez závislostí na doméně;
kontejner smí volat jen kompoziční kořen a `Kernel` (dispečer), controllery dostávají závislosti
konstruktorem.

M3 (plán 003, implementováno) — odchylky od zásady „bezp. hlavičky → session → CSRF → autentizace →
autorizace“: párování trasy se přesouvá do `RoutingMiddleware` **před** CSRF (zachová 404/405 pro
neexistující trasy); session není vrstva, ale líná služba (`Http\Session\Session`, implementace
`Infrastructure\Session\NativeSession`) — veřejné stránky nedostanou cookie; autentizace a autorizace
jsou jeden `AdminAccessMiddleware` (jediná role `admin`, ochrana podle prefixu `/admin`).

M4 (plán 004, implementováno) — veřejné čtení jde `Controller → PublishedArticles (Application) → ArticleRepository
(Domain) ← PdoArticleRepository`. Pravidlo „veřejně jen publikované a ne budoucí“ je v SQL repozitáře
(metody `*Published*`), čas dodává `Clock` (PHP `Europe/Prague`, ne `NOW()` v MariaDB/UTC). Repozitář
vrací read modely (`ArticleSummary` s rubrikou přes `JOIN`, `ArticleDetail` + štítky druhým dotazem) —
žádné N+1. Controller hlásí 404 výjimkou `Http\PageNotFound`, kterou `ErrorHandlerMiddleware` vykreslí
stejně jako neexistující trasu. Markdown → HTML jen přes `Http\View\MarkdownRenderer` (ADR-0005, jediný
výpis bez `e()` mimo `layout.php`). Seed (`db:seed`) je soubor `database/seeds/demo_content.php` vracející
objekt s rozhraním `Infrastructure\Seed\Seed` — obdoba migrací; běží jako `redakce_app`, nic nemaže.

M5 (plán 005, hotovo) — administrace článků jde `Admin\ArticleController → CreateArticle / UpdateArticle /
DeleteArticle, AdminArticles (Application) → ArticleAdminRepository, CategoryRepository, TagRepository,
AuditLogRepository (Domain) ← Pdo*`. Administrace má **vlastní rozhraní repozitáře** (všechny stavy + zápis),
veřejné `ArticleRepository` zůstává jen pro publikované. Validace formuláře je v `ArticleInputValidator`
(Application, české chyby → `InvalidArticleInput` → 422), slug počítají čisté funkce `Slug::fromText`
a `Slug::uniqueAmong` nad jedním dotazem `takenSlugs`. Článek a jeho štítky se ukládají v transakci
repozitáře, audit `article.*` až po ní. `created_at`/`updated_at` zapisuje repozitář z `Clock` (výchozí
hodnoty DB jsou v UTC). Admin URL používají ID (`/admin/clanky/{id}/upravit`), PRG + `Http\Session\Flash`.

M6 (plán 006, implementováno) — AI jde `Admin\AiController` / `ai:priklad` → `ExampleRunner` → příklad 01–05 →
`LlmClient`. Pod rozhraním `LlmClient` kontejner vždy registruje dekorátor `MeteredLlmClient` (denní limit tokenů
`AI_DENNI_LIMIT_TOKENU` s rezervací `max_tokens`, cena z `config/ai-models.php`, zápis metadat do `ai_calls`) nad
`FakeLlmClient` (bez sítě, výchozí) nebo `AnthropicClient` (cURL přes `HttpTransport`, retry jen 429/500/529).
Strukturovaný výstup přes `output_config.format` + validace v PHP (ADR-0006; vynucený nástroj `claude-sonnet-5-5`
odmítá). Článek jde do promptu v `<clanek>` značkách, výstup modelu se jen zobrazuje přes `e()` a nikam se neukládá;
výsledek přežije PRG v session (`ExampleResultStash`).

M7 (plán 008, hotovo, ADR-0008) — příklad 06 streamuje: `Admin\WritingAssistantController` (POST `/admin/ai/06/proud`
s CSRF) uvolní zámek session a vrátí `Response::stream(producent)`; producent běží až v `Response::send()` (mimo middleware,
chyby mění na SSE `error`) a přes `Example06WritingAssistant` → `StreamingLlmClient` (= `MeteredLlmClient`) → `AnthropicClient`
(`HttpTransport::stream`, `SseParser`) nebo `FakeLlmClient` posílá delty jako SSE (`X-Accel-Buffering: no`); prohlížeč je čte
přes `fetch` (`public/assets/ai-stream.js`), přerušení = `AbortController` → `connection_aborted()` → `stopReason 'aborted'`.
Příklad 07 (PRG jako 01–05) volá `LlmClient::complete()` v smyčce ≤ 5 kroků s nástroji `hledej_clanky`/`nacti_clanek`
(`App\Ai\Tools`, jen `ArticleRepository` = publikované); surové bloky odpovědi (vč. `thinking`) se vracejí nezměněné.
Schéma DB se nemění (krok = řádek `ai_calls`).

M7b (plán 009, **návrh**, ADR-0009) — příklad 08 (sémantické vyhledávání, RAG): `Admin\SemanticSearchController` / `ai:indexuj`
→ `ArticleIndexer` → `EmbeddingClient` (`FakeEmbeddingClient` bez sítě, nebo `OllamaEmbeddingClient` → Ollama `embeddinggemma`
v profilu Compose `ai-local`, HTTP jen uvnitř sítě Dockeru) → `ArticleEmbeddingRepository::save` (tabulka `article_embeddings`,
`VECTOR(768)` + HNSW index s kosinem). Dotaz: `Example08SemanticSearch` → `embedQuery` → `nearestPublished` (vnitřní poddotaz
přes vektorový index, **vnější** filtr „publikované a ne budoucí“ z `Clock` — `WHERE` v dotazu s indexem by filtroval až po `LIMIT`)
→ `LlmClient::complete()` se zdroji jako bloky `search_result` → citace `search_result_location` ze surových bloků odpovědi,
ověřené v PHP. Indexují se jen publikované články, změny pozná `source_hash` počítaný v SQL; use-cases administrace (M5) se nemění.
Embeddingy se nelogují do `ai_calls` (lokálně zdarma), volání Claude ano.

M8 (plán 007, implementováno) — audit log jde `Admin\AuditLogController` (jen `GET /admin/audit`, filtr jako GET formulář
bez CSRF) → `AuditLogSearch` (Application: validace `akce`/`od`/`do`, 50 na stránku) → `AuditLogRepository::count` +
`search` (Domain) ← `PdoAuditLogRepository` (dva dotazy, `LEFT JOIN users`, indexy `created_at` a `(action, created_at)`).
**Pravidlo časů (ADR-0007):** sloupec, který plní databáze (`DEFAULT CURRENT_TIMESTAMP`), je v UTC — `audit_log.created_at`,
`users.created_at`, `users.last_login_at`, `migrations.executed_at`; sloupec, který plní aplikace z `Clock`, je v Europe/Prague —
`articles.*_at`, `ai_calls.created_at` (seed od M8 vyplňuje časy článků explicitně). `ConnectionFactory` připíchne zónu
spojení na UTC; převod UTC → Praha dělá jen repozitář, který čas čte (dnes `PdoAuditLogRepository`).

## 2. Běhové prostředí (dev, `compose.yaml`, projekt `t360`)
Hostitel má jen `docker`, `git`, `bash`, `jq` (+ `make`, `curl` — čeká na schválení). Žádné PHP ani Node.

```mermaid
flowchart TB
    subgraph Host["Hostitel (WSL2 / Linux)"]
        B["Prohlížeč / curl"]
        CC["Claude Code<br/>(hooky: bash + jq)"]
        MK["make → docker compose"]
    end
    subgraph Net["Docker síť t360_default"]
        W["web · web-t360<br/>nginx 1.x · 8080:80"]
        A["app · app-t360<br/>PHP 8.4-FPM (target dev)<br/>repo → /app"]
        D[("db · db-t360<br/>MariaDB 11.8<br/>127.0.0.1:3307:3306")]
        AD["adminer · adminer-t360<br/>127.0.0.1:8081"]
        subgraph PL["profil ai-local — make ai-local (M7b, plán 009)"]
            O["ollama · ollama-t360<br/>ollama/ollama · bez portu na hostitele<br/>volume ollama_models (embeddinggemma)"]
        end
        subgraph P["profil mcp — spouští Claude Code přes compose run (stdio)"]
            PW["mcp-playwright<br/>Chromium headless"]
            MM["mcp-mariadb<br/>uživatel redakce_cteni"]
        end
    end
    C7["context7<br/>https://mcp.context7.com/mcp"]
    B -->|":8080"| W
    W -->|"FastCGI :9000"| A
    A -->|"redakce_app"| D
    A -.->|"HTTP :11434 /api/embed, jen EMBED_PROVIDER=ollama"| O
    AD --> D
    CC -->|"docker compose exec app php -l / composer"| A
    CC -->|"stdio"| PW
    CC -->|"stdio"| MM
    CC -->|"HTTPS"| C7
    PW -->|"http://web"| W
    MM -->|"db:3306, jen SELECT"| D
    MK --> Net
```

Uživatelé DB (init skript `docker/mariadb/init/`): `redakce_app` (DML na `redakce` a
`redakce_test`), `redakce_migrace` (DDL), `redakce_cteni` (jen `SELECT`, pro MCP).
Produkce (`compose.prod.yaml`, M9) se řídí skillem `devops-kontrakt` beze změny.

## 3. Tok požadavku `/zdravi` (M1)
```mermaid
sequenceDiagram
    participant K as Klient (curl / healthcheck)
    participant N as nginx (web)
    participant F as PHP-FPM (app) · public/index.php
    participant KE as Http\Kernel
    participant HC as HealthController
    participant H as PdoDatabaseHealthRepository
    participant DB as MariaDB
    K->>N: GET /zdravi
    N->>F: FastCGI (jen index.php)
    F->>KE: handle(Request::fromGlobals())
    KE->>HC: GET /zdravi
    HC->>H: isReachable()
    H->>DB: připojení (timeout 2 s) + SELECT 1
    alt DB odpovídá
        H-->>HC: true
        HC-->>K: 200 {"stav":"ok","db":"ok"}
    else DB nedostupná
        H-->>HC: false (chyba jen do logu, bez hesla)
        HC-->>K: 503 {"stav":"chyba","db":"chyba"}
    end
```

## 3a. Tok požadavku od M2 (plán 002)
```mermaid
sequenceDiagram
    participant F as public/index.php
    participant DI as Container
    participant KE as Kernel
    participant EH as ErrorHandlerMiddleware
    participant R as Router
    participant C as Controller
    participant T as TemplateRenderer
    F->>DI: require config/container.php
    F->>KE: get(Kernel)->handle(Request::fromGlobals())
    KE->>EH: MiddlewarePipeline (stav M2; od M3 jsou v řetězu i SecurityHeaders, Routing, Csrf a AdminAccess)
    EH->>KE: $next(request) → dispatch
    KE->>R: match(method, path)
    alt trasa nalezena
        R-->>KE: RouteMatch(handler, parametry)
        KE->>DI: get(ControllerClass)
        KE->>C: method(request s parametry)
        C->>T: render('home', data)
        C-->>EH: Response (HTML / JSON)
    else RouteNotFound / MethodNotAllowed / Throwable
        R--xEH: výjimka
        EH->>T: render('error', status)
        EH-->>F: 404 / 405 + Allow / 500 (detail jen do error_log)
    end
```

## 3b. Schéma databáze od M2 (ADR-0004, plán 002 §5)
```mermaid
erDiagram
    users ||--o{ articles : "created_by / updated_by (SET NULL)"
    users ||--o{ audit_log : "user_id (SET NULL)"
    categories ||--o{ articles : "category_id (RESTRICT)"
    articles ||--o{ article_tags : "CASCADE"
    tags ||--o{ article_tags : "CASCADE"
    users {
        bigint id PK
        varchar email UK
        varchar display_name
        varchar password_hash
        enum role "admin"
    }
    categories {
        bigint id PK
        varchar name
        varchar slug UK
    }
    tags {
        bigint id PK
        varchar name
        varchar slug UK
    }
    articles {
        bigint id PK
        bigint category_id FK
        varchar title
        varchar slug UK
        varchar excerpt
        mediumtext body
        enum status "draft, published, archived"
        datetime published_at
        bigint created_by FK
        bigint updated_by FK
    }
    article_tags {
        bigint article_id PK, FK
        bigint tag_id PK, FK
    }
    audit_log {
        bigint id PK
        bigint user_id FK
        varchar action
        varchar entity_type
        bigint entity_id
        varchar summary
        datetime created_at
    }
    migrations {
        varchar name PK
        datetime executed_at
    }
    users ||--o{ ai_calls : "user_id (SET NULL), M6"
    ai_calls {
        bigint id PK
        bigint user_id FK
        varchar example_id
        varchar provider
        varchar model
        int input_tokens
        int output_tokens
        int cache_creation_input_tokens
        int cache_read_input_tokens
        decimal cost_usd
        int duration_ms
        enum status "ok, error"
        datetime created_at
    }
    articles ||--o| article_embeddings : "CASCADE, M7b (plán 009)"
    article_embeddings {
        bigint article_id PK, FK
        varchar model
        char source_hash
        vector embedding "VECTOR(768), VECTOR INDEX cosine"
        datetime indexed_at
    }
```
Vše `InnoDB`, `utf8mb4_czech_ci`. Migrace spouští `bin/konzole migrace:spust` (`make migrate`)
jako uživatel `redakce_migrace`; aplikace pracuje jako `redakce_app` (jen DML).

## 4. Nástroje kvality (vše v kontejneru `app`)
`make check` = `php -l` + PHP-CS-Fixer `--dry-run` (PER-CS) + PHPStan level max ·
`make test` = PHPUnit (sady Unit, Integration nad DB `redakce_test`) ·
`make qa` = check + test + `composer audit`.
Hooky Claude Code (`php-lint`, `rychla-kontrola`) a git hook `pre-commit` volají tytéž nástroje
přes `docker compose exec -T app …`.
