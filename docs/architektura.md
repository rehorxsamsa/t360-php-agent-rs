# Architektura — Redakční systém (t360)

> Udržuje agent `architekt`. Poslední aktualizace: 2026-10-03 (plán 001 M1 — hotovo;
> plán 002 M2 — implementováno, části označené „M2“ existují v kódu).
> Rozhodnutí: [ADR-0001](adr/0001-vyvoj-tymem-agentu.md) tým agentů ·
> [ADR-0002](adr/0002-vse-v-dockeru-vcetne-mcp.md) vše v Dockeru vč. MCP ·
> [ADR-0003](adr/0003-anglicke-identifikatory.md) anglické identifikátory ·
> [ADR-0004](adr/0004-anglicke-nazvy-v-databazi.md) anglické názvy v DB (navrženo).

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
        K[Kernel] --> MW["MiddlewarePipeline<br/>M2: ErrorHandler<br/>M3: bezp. hlavičky → session → CSRF<br/>→ autentizace → autorizace"]
        MW --> RT["Router::match"]
        RT --> C["Controller"]
        C --> V["TemplateRenderer + e()<br/>templates/*.php"]
    end
    subgraph Con["App\\Console (M2)"]
        CA["ConsoleApplication → Command<br/>migrace:spust | vrat | stav"]
    end
    subgraph App["App\\Application — use-cases"]
        UC["služby / use-cases (M4+)"]
    end
    subgraph Dom["App\\Domain — entity, VO, rozhraní repozitářů"]
        I["rozhraní: DatabaseHealth (M1),<br/>ArticleRepository… (M4+)"]
    end
    subgraph Inf["App\\Infrastructure — PDO, migrace, config"]
        R["Pdo*Repository<br/>ConnectionFactory, DatabaseConfig"]
        MG["Migration\\Migrator<br/>PdoMigrationRepository<br/>database/migrations/*.php"]
    end
    subgraph Ai["App\\Ai (M6+)"]
        L["LlmClient: AnthropicClient,<br/>OllamaClient, FakeLlmClient"]
    end
    DI -.->|sestavuje| K
    DI -.->|sestavuje| CA
    K -.->|"get(controller)"| DI
    C --> UC
    C -.->|"M1–M2: přímo, jen čtení"| I
    UC --> I
    R -.->|implementuje| I
    CA --> MG
    UC --> L
```

Stav po M1: `Kernel` s pevně zadrátovanou cestou `/zdravi`, rozhraní `Domain\Health\DatabaseHealth`
a jeho PDO implementace. M2 (plán 002) přidal kontejner, router, middleware, šablony, chybové
stránky, konzoli a migrátor. `App\Container` je technické jádro bez závislostí na doméně;
kontejner smí volat jen kompoziční kořen a `Kernel` (dispečer), controllery dostávají závislosti
konstruktorem.

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
        subgraph P["profil mcp — spouští Claude Code přes compose run (stdio)"]
            PW["mcp-playwright<br/>Chromium headless"]
            MM["mcp-mariadb<br/>uživatel redakce_cteni"]
        end
    end
    C7["context7<br/>https://mcp.context7.com/mcp"]
    B -->|":8080"| W
    W -->|"FastCGI :9000"| A
    A -->|"redakce_app"| D
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
    KE->>EH: MiddlewarePipeline (M3 přidá další vrstvy)
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
```
Vše `InnoDB`, `utf8mb4_czech_ci`. Migrace spouští `bin/konzole migrace:spust` (`make migrate`)
jako uživatel `redakce_migrace`; aplikace pracuje jako `redakce_app` (jen DML).

## 4. Nástroje kvality (vše v kontejneru `app`)
`make check` = `php -l` + PHP-CS-Fixer `--dry-run` (PER-CS) + PHPStan level max ·
`make test` = PHPUnit (sady Unit, Integration nad DB `redakce_test`) ·
`make qa` = check + test + `composer audit`.
Hooky Claude Code (`php-lint`, `rychla-kontrola`) a git hook `pre-commit` volají tytéž nástroje
přes `docker compose exec -T app …`.
