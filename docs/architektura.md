# Architektura — Redakční systém (t360)

> Udržuje agent `architekt`. Poslední aktualizace: 2026-10-03 (plán 001, M1 — stav **návrh**).
> Rozhodnutí: [ADR-0001](adr/0001-vyvoj-tymem-agentu.md) tým agentů ·
> [ADR-0002](adr/0002-vse-v-dockeru-vcetne-mcp.md) vše v Dockeru vč. MCP (navrženo) ·
> [ADR-0003](adr/0003-anglicke-identifikatory.md) anglické identifikátory (navrženo).

## 1. Vrstvy aplikace (cílový stav)
Závislosti míří **dovnitř** k `Domain`. `Infrastructure` implementuje rozhraní z `Domain`.

```mermaid
flowchart LR
    subgraph Http["App\\Http — kontrolery, middleware, Request/Response"]
        FC["public/index.php<br/>(front controller)"] --> K[Kernel]
        K --> MW["Middleware řetězec (M2–M3)<br/>bezp. hlavičky → session → CSRF<br/>→ autentizace → autorizace"]
        MW --> C[Controller]
    end
    subgraph App["App\\Application — use-cases"]
        UC["služby / use-cases (M2+)"]
    end
    subgraph Dom["App\\Domain — entity, VO, rozhraní repozitářů"]
        I["rozhraní: DatabaseHealth (M1),<br/>ArticleRepository… (M4+)"]
    end
    subgraph Inf["App\\Infrastructure — PDO, session, config"]
        R["Pdo*Repository<br/>ConnectionFactory, DatabaseConfig"]
    end
    subgraph Ai["App\\Ai (M6+)"]
        L["LlmClient: AnthropicClient,<br/>OllamaClient, FakeLlmClient"]
    end
    C --> UC
    C -.->|"M1: přímo, jen čtení"| I
    UC --> I
    R -.->|implementuje| I
    UC --> L
```

Stav po M1: existuje jen `Kernel` s pevně zadrátovanou cestou `/zdravi` (router, DI kontejner
a middleware přidá M2), rozhraní `Domain\Health\DatabaseHealth` a jeho PDO implementace.

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

## 4. Nástroje kvality (vše v kontejneru `app`)
`make check` = `php -l` + PHP-CS-Fixer `--dry-run` (PER-CS) + PHPStan level max ·
`make test` = PHPUnit (sady Unit, Integration nad DB `redakce_test`) ·
`make qa` = check + test + `composer audit`.
Hooky Claude Code (`php-lint`, `rychla-kontrola`) a git hook `pre-commit` volají tytéž nástroje
přes `docker compose exec -T app …`.
