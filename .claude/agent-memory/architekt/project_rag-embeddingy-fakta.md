---
name: project-rag-embeddingy-fakta
description: Ověřená fakta pro RAG v t360 (2026-10-08, plán 009/ADR-0009) — MariaDB VECTOR post-filtr, Ollama /api/embed a embeddinggemma, Voyage přes Atlas, Claude search_result citace
metadata:
  type: project
---

Ověřeno 2026-10-08 (context7 `/websites/mariadb`, `/websites/ollama_api`, mariadb.com, ollama.com, platform.claude.com):
- **MariaDB 11.8 VECTOR:** float32, `VEC_FromText('[…]')`, `VEC_DISTANCE_COSINE/EUCLIDEAN`; `VECTOR INDEX (c) M=3..200 DISTANCE=cosine`,
  sloupec NOT NULL, **jeden vektorový index na tabulku**. Index jen pro `ORDER BY VEC_DISTANCE_*(…)` ASC + `LIMIT`; **`WHERE` filtruje
  až řádky vrácené indexem v rámci LIMIT** → filtr „publikované“ patří do vnějšího dotazu nad poddotazem s kandidáty.
- **Ollama:** `POST /api/embed {model, input: string|string[], truncate}` → `{embeddings: number[][], prompt_eval_count}`; obraz
  `ollama/ollama:0.40.1` ~3,8 GB. `embeddinggemma` 622 MB, 768 dim. (MRL 512/256/128), kontext 2K, prefixy
  `task: search result | query: …` / `title: … | text: …`. `nomic-embed-text` ze skillu je anglický.
- **Voyage:** Anthropic embeddingy nemá, odkazuje na Voyage; klíč z MongoDB Atlas, `https://ai.mongodb.com/v1/embeddings`,
  `voyage-4-lite` 0,02 USD/MTok, dimenze 1024/256/512/2048 (**ne 768**).
- **Claude `search_result` bloky** (GA, všechny aktivní modely, jen ve zprávě user) → citace `search_result_location`
  s `cited_text`, `search_result_index` (od 0). `AnthropicClient` je posílá i vrací beze změny (ADR-0008) — bez úpravy klienta.
- `CurlHttpTransport` povoluje jen HTTPS → Ollama (HTTP v síti Dockeru) potřebuje volbu `allowPlainHttp` jen pro svou instanci.

**Why:** plán 009 na tom stojí; skill `db-migrace` má zavádějící ukázku (`WHERE status` v dotazu s indexem), skill `ai-integrace` nomic.

**How to apply:** u M7b implementace a navazujících plánů (Voyage, chunking, M7c MCP `hledej_clanky`) vycházej z ADR-0009 a ověř
tagy obrazů/ceny znovu. Viz [[project-ai-api-fakta]], [[project-db-nazvy-a-migrace]].
