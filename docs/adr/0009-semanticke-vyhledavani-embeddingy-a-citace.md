# ADR-0009: Sémantické vyhledávání – embeddingy přes Ollamu, MariaDB VECTOR a nativní citace Claude
- **Stav:** přijato
- **Datum:** 2026-10-08
- **Autor:** agent architekt
- **Souvisí:** [plán 009](../plan/009-semanticke-vyhledavani-rag.md), [ADR-0006](0006-vlastni-llm-klient-curl.md),
  [ADR-0008](0008-streaming-a-nastroje-llm.md) (rozšiřuje, nenahrazuje), [ADR-0004](0004-anglicke-nazvy-v-databazi.md),
  [ADR-0007](0007-casy-v-databazi-utc-vs-praha.md), skilly `ai-integrace`, `db-migrace`

## Kontext
AI příklad 08 (zadání, M7b) má najít publikované články **podle významu** (ne podle shody slov jako `hledej_clanky`
v příkladu 07) a odpovědět s citacemi. Platí:
- **Anthropic vlastní model embeddingů nenabízí**; jeho dokumentace (platform.claude.com, Embeddings, ověřeno 2026-10-08)
  odkazuje na Voyage AI. Voyage je dnes za účtem MongoDB Atlas (`https://ai.mongodb.com/v1/embeddings`), modely `voyage-4*`
  mají dimenze 1024 (výchozí), 256, 512, 2048; `voyage-4-lite` 0,02 USD/MTok, prvních 200 M tokenů zdarma.
- **Ollama** (ověřeno 2026-10-08): `POST /api/embed` s `{"model", "input": string | string[], "truncate": true}` → `{"model",
  "embeddings": number[][], "prompt_eval_count", …}`; obraz `ollama/ollama:0.40.1` má ~3,8 GB (CUDA knihovny).
  Model `embeddinggemma` (622 MB, vyžaduje Ollama ≥ 0.11.10): **768 dimenzí** (Matryoshka 512/256/128), kontext 2K,
  trénink na 100+ jazycích (čeština výslovně neuvedena), doporučené prefixy `task: search result | query: {dotaz}` a
  `title: {titulek} | text: {text}`. Skill `ai-integrace` dosud uvádí `nomic-embed-text` (zaměřený na angličtinu).
- **MariaDB 11.8** (projekt má `mariadb:11.8.9`, ověřeno 2026-10-08): `VECTOR(N)` = float32, `VEC_FromText('[…]')`,
  `VEC_DISTANCE_COSINE`, `VEC_DISTANCE_EUCLIDEAN`; `VECTOR INDEX (col) M=3..200 DISTANCE=euclidean|cosine` (HNSW), sloupec
  `NOT NULL`, **jeden vektorový index na tabulku**. Index se použije jen pro `ORDER BY VEC_DISTANCE_*(sloupec, vektor)`
  (nebo alias) vzestupně s `LIMIT`; **`WHERE` se aplikuje až na řádky, které index vrátil v rámci `LIMIT`** (post-filtr) —
  filtr „jen publikované“ uvnitř dotazu s indexem by tak vracel méně výsledků.
- **Claude Messages API** (ověřeno 2026-10-08, stránka Search results): bloky `search_result` (`source`, `title`, `content`
  = textové bloky, `citations.enabled`) jsou GA bez beta hlavičky pro všechny aktivní modely; odpověď nese u textových bloků
  `citations` typu `search_result_location` (`source`, `title`, `cited_text`, `search_result_index` od 0, `start_block_index`,
  `end_block_index`). `search_result` smí být jen ve zprávě `user`. `AnthropicClient` už posílá bloky beze změny a vrací
  surové bloky odpovědi v `LlmResponse::$content` (ADR-0008).
- Výukový režim, bez nové composer závislosti; aplikace musí běžet i bez Ollamy a bez API klíče (falešní klienti).

## Rozhodnutí
**Embeddingy počítá lokální Ollama s modelem `embeddinggemma` (768 dimenzí) za novým portem `EmbeddingClient`
(implementace `OllamaEmbeddingClient` a deterministický `FakeEmbeddingClient`); každý publikovaný článek má jeden vektor
v tabulce `article_embeddings` (`VECTOR(768)`, vektorový index s kosinovou vzdáleností); odpověď s citacemi vzniká nativními
bloky `search_result` Claude API.**

- **Port `EmbeddingClient`** (`App\Ai\Embedding`): `embedDocuments(list<EmbeddingDocument>)` a `embedQuery(string)` —
  rozlišení dokument × dotaz je skutečný koncept obou poskytovatelů (prefixy embeddinggemma, `input_type` u Voyage),
  `model()` určuje „prostor vektorů“ uložený u každého řádku. Výběr implementace `EMBED_PROVIDER=falesny|ollama`
  (výchozí `falesny`). Ollama běží jen v dev profilu Compose `ai-local`; komunikace uvnitř sítě Dockeru je HTTP, proto
  `CurlHttpTransport` dostane volbu `allowPlainHttp` (zapnutou **jen** pro instanci Ollamy; Claude zůstává jen HTTPS).
- **Schéma:** `article_embeddings (article_id PK/FK CASCADE, model, source_hash, embedding VECTOR(768) NOT NULL, indexed_at)`
  + `VECTOR INDEX … M=8 DISTANCE=cosine`. Dimenze je součást schématu: jiný model s jinou dimenzí = nová migrace + nová indexace.
  Jeden vektor na článek z titulku, perexu a začátku textu (≤ 4 000 znaků); dělení na úseky (chunking) ne.
- **„Jen publikované“ ve třech vrstvách:** indexují se jen články ve stavu `published`; každá aktualizace indexu maže vektory
  článků, které už publikované nejsou (a vektory jiného modelu); vyhledávací dotaz vybere ve vnitřním poddotazu kandidáty
  přes vektorový index (`LIMIT` = 20) a **vnější dotaz** je spojí s `articles` a filtruje stejnou podmínkou jako veřejný web
  (`status = 'published' AND published_at <= now` z `Clock`). Text pro model se bere z `articles` (aktuální), ne z indexu.
- **Aktuálnost indexu:** `source_hash = SHA2(titulek, perex, text)` počítá SQL v repozitáři; indexace (`ai:indexuj` nebo tlačítko
  „Aktualizovat index“ na `/admin/ai/08`) zpracuje jen chybějící a změněné články. Use-cases administrace článků (M5) se
  **nemění** — žádné volání embeddingů při uložení článku.
- **Citace:** příklad 08 pošle nalezené články jako bloky `search_result` (`source = /clanek/{slug}`, obsah = perex a odstavce)
  s `citations.enabled`, otázku jako poslední textový blok; citace čte ze surových bloků odpovědi a ověří (index v rozsahu,
  `source` odpovídá). `FakeLlmClient` citace emuluje. Bez nástrojů, bez strukturovaného výstupu, bez streamování.
- **Měření:** volání LLM jde přes `MeteredLlmClient` (limit, cena, `ai_calls`) jako dosud; volání embeddingů se do
  `ai_calls` **nezapisují** (lokální model je zdarma; tokeny a čas ukazuje výsledek příkladu a výpis indexace).

## Důsledky
+ RAG běží celý lokálně a zdarma (kromě volitelného volání Claude); bez Ollamy i bez klíče jde všechno předvést s falešnými
  klienty, testy a CI nepotřebují síť.
+ Čtenář vidí celý řetězec: text → vektor → `VECTOR` + HNSW index → nejbližší sousedé → grounding → ověřené citace.
+ Citace jsou strojově ověřitelné (`cited_text` je doslovný úsek zdroje), ne volný text modelu; sloupec „Zdroje“ vzniká z citací.
+ Žádná nová composer závislost; `AnthropicClient` se nemění.
− Nový obraz `ollama/ollama` (~3,8 GB) a model (622 MB) — jen v profilu `ai-local`, stahuje se na vyžádání.
− Dimenze 768 je zamčená schématem: Voyage (1024/512) nebo `bge-m3` (1024) = nová migrace a přeindexování.
− Post-filtr vektorového indexu: když mezi 20 nejbližšími kandidáty převažují nepublikované (mezi indexacemi), vrátí se méně
  zdrojů. Při velikosti redakce přijatelné.
− Jeden vektor na článek: dlouhé články reprezentuje jen jejich začátek (chunking = backlog).
− Citace přes `search_result` umí jen Claude (a falešný klient); budoucí lokální LLM by potřeboval citace v promptu.
− Index může být neaktuální (článek upravený po indexaci): stránka i výsledek to hlásí, opraví ho tlačítko nebo `ai:indexuj`.

## Zvažované alternativy
- **Voyage AI** (`voyage-4-lite`, doporučení dokumentace Claude) — kvalitní, vícejazyčné, levné, ale druhý placený klíč (účet
  MongoDB Atlas), odesílání dat ven, nutnost měřit cenu embeddingů a dimenze 1024/512 ≠ 768. Odloženo do backlogu (vhodné pro
  produkci na VPS, kde Ollama s 1 CPU neběží); port `EmbeddingClient` je na ni připravený.
- **`nomic-embed-text`** (skill, 768 dimenzí) — malý, ale zaměřený na angličtinu; obsah redakce je česky. **`bge-m3`** / `qwen3-embedding`
  (1024 dimenzí, vícejazyčné) — 2× větší model a větší vektory. Odmítnuto ve prospěch `embeddinggemma`.
- **Vektory v PHP** (JSON sloupec + kosinus v aplikaci) — bez závislosti na verzi DB, ale nenaučí `VECTOR` a index a neškáluje.
  Odmítnuto (zadání výslovně chce MariaDB `VECTOR`).
- **Filtrovat „publikované“ přímo v dotazu s vektorovým indexem** — kvůli post-filtru by vracel méně výsledků; **indexovat všechny
  stavy** — koncepty by zabíraly kandidáty a riskoval by se únik. Odmítnuto.
- **Indexace synchronně při uložení článku** (v `CreateArticle`/`UpdateArticle`) — index vždy aktuální, ale ukládání článku by
  záviselo na běžící Ollamě (latence, chyby) a M5 testy by potřebovaly dalšího dvojníka. Odloženo (backlog).
- **Citace značkami `[n]` v promptu** — funguje s libovolným modelem, ale model může citovat cokoli a PHP jen hádá podle čísel;
  nativní `search_result` dává doslovný citovaný text. Zmíní se v tutoriálu jako alternativa.
- **Dělení článků na úseky (chunking)** — přesnější vyhledávání v dlouhých textech, ale tabulka 1:N, slučování výsledků a více
  testů. Backlog.
- **Logovat embeddingy do `ai_calls`** — jednotný přehled, ale ceník a limit pro modely embeddingů (katalog dnes zná jen Claude).
  Backlog spolu s Voyage.
