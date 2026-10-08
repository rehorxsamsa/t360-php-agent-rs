---
name: ai-integrace
description: Referenční příručka AI části redakčního systému — rozhraní LlmClient, volání Claude Messages API z čistého PHP (cURL), structured output, tool use, streaming, prompt caching, embeddingy, náklady, testování bez klíče a zadání 10 AI příkladů. Načti před každou prací na AI.
---

# AI integrace

## Konfigurace (.env)
```
AI_PROVIDER=falesny            # falesny | anthropic (ollama přijde v M7)
ANTHROPIC_API_KEY=             # jen v .env, nikdy v repu
AI_MODEL=claude-sonnet-5-5     # generování textu (ověř aktuální názvy modelů v docs)
AI_MODEL_LEVNY=claude-haiku-4-5-20251001   # klasifikace, štítky
AI_DENNI_LIMIT_TOKENU=200000
EMBED_PROVIDER=falesny         # M7: falesny | ollama | voyage
EMBED_MODEL=nomic-embed-text   # Ollama, 768 dimenzí
OLLAMA_URL=http://ollama:11434
```

## Rozhraní (jádro, M6; ADR-0006)
```php
interface LlmClient
{
    /** @throws LlmCallFailed|AiBudgetExceeded */
    public function complete(LlmRequest $request): LlmResponse;      // jedno volání
}
final readonly class LlmRequest {
    /** @param list<array{role:'user'|'assistant',content:string}> $messages
     *  @param ?array<string,mixed> $jsonSchema */
    public function __construct(
        public string $model, public string $system, public array $messages,
        public int $maxTokens, public string $exampleId, public ?int $userId = null,
        public ?string $effort = null, public ?array $jsonSchema = null,
        public bool $cacheSystem = false,
    ) {}
}
```
Pod `LlmClient` kontejner vždy registruje `MeteredLlmClient` (denní limit tokenů, cena, záznam do
`ai_calls`), který obaluje `FakeLlmClient` nebo `AnthropicClient` (HTTP přes `HttpTransport`/cURL).
`FakeLlmClient`: deterministický, bez sítě, provider `fake`, orientační cena. **Všechny testy a CI
běží s ním.** Streaming a tool use (M7, ADR-0008): `StreamingLlmClient extends LlmClient` s
`stream(LlmRequest, callable(string): bool $onText): LlmResponse` (callback `false` = přerušení,
`stopReason 'aborted'`; implementují ho `AnthropicClient`, `FakeLlmClient`, `MeteredLlmClient`; `LlmClient`
beze změny). Tool use jsou data: `LlmRequest::$tools`, bloky obsahu ve zprávách, `LlmResponse::$content`
(surové bloky) a `$toolCalls`; smyčku řídí příklad. Embeddingy (M7b, ADR-0009): port `EmbeddingClient` s `OllamaEmbeddingClient` (model `embeddinggemma`, 768 dimenzí, profil `ai-local`) a deterministickým `FakeEmbeddingClient`; Anthropic vlastní embeddingy nemá, Voyage je v backlogu; citace přes nativní bloky `search_result`. Závazné signatury jsou
v `docs/plan/006-ai-jadro.md`, `docs/plan/008-streaming-a-nastroje.md` a `docs/plan/009-semanticke-vyhledavani-rag.md`.

## Claude Messages API — minimum (ověř aktuálnost přes context7 / docs.claude.com)
- `POST https://api.anthropic.com/v1/messages`, hlavičky `x-api-key`, `anthropic-version: 2023-06-01`,
  `content-type: application/json`. Tělo: `model`, `max_tokens`, `system`, `messages`, volitelně
  `tools`, `stream`, `output_config`. **Sonnet 5.5 vrací 400 při `temperature` i při vynuceném `tool_choice`** — neposílej je.
- **Prompt caching**: `system` jako pole bloků, poslední blok s `"cache_control": {"type": "ephemeral"}`.
- **Strukturovaný výstup**: `output_config.format = {"type":"json_schema","schema":{…}}` (vynucený nástroj
  Sonnet 5.5 nepodporuje). Vždy validuj proti schématu i v PHP, při chybě 1 opakování (`StructuredCall`).
  `effort` (`output_config.effort`) podporuje jen Sonnet, Haiku ne — modelu bez podpory ho neposílej.
- **Tool use smyčka**: dokud `stop_reason == "tool_use"`: vykonej nástroj (jen čtecí!), pošli zpět
  jednu zprávu `role:user` s bloky `{type:"tool_result", tool_use_id, content, is_error?}` (tool_result
  bloky jdou první). Neznámý nástroj nebo neplatný vstup = `tool_result` s `is_error`, nikdy výjimka ani
  jiná akce. Limit 5 kroků, 3 nástroje na krok, 60 s, výstup nástroje ≤ 8 000 znaků. `tool_choice` se
  neposílá (Sonnet 5.5 odmítá `any`/`tool`). **Bloky `thinking` (se `signature`) se musí vrátit
  nezměněné**, jinak 400. PHP past: `"input":{}` se při `json_encode` změní na `[]`, API ho odmítne;
  prázdný objekt zakóduj jako `new \stdClass`.
- **Streaming**: `stream:true` → SSE události `message_start` (usage se vstupem), `content_block_start/
  delta/stop` (`text_delta`, `thinking_delta`, `signature_delta`, `input_json_delta`), `message_delta`
  (`stop_reason`, usage kumulativně), `message_stop`, `ping`; chyba uprostřed proudu = `event: error`
  při HTTP 200, neznámé události ignoruj. V PHP: cURL `CURLOPT_WRITEFUNCTION` + vlastní `SseParser`
  (`event:`/`data:`, kousky rozdělené kdekoli, CRLF). Do prohlížeče jako SSE přes `fetch` s POST a CSRF
  (`EventSource` umí jen GET), na serveru `Response::stream(producent)`, uvolni zámek session
  (`Session::release()`), hlavička `X-Accel-Buffering: no`, text do DOM jen přes `textContent`.
- Chyby: retry jen 429 s `retry-after` ≤ 5 s a 500/529 (čekání 1 s, 2 s, max 3 pokusy); ostatní 4xx, 504 a timeout bez retry, srozumitelná česká hláška (`LlmErrorType::userMessage()`), klíč se nikdy nevypisuje.
- Náklady: z `usage.input_tokens`, `usage.output_tokens` (+ cache tokeny) × ceník v
  `config/ai-models.php` (hodnoty ověř na stránce s cenami, uveď datum ověření).

## Zadání 10 AI příkladů (každý = CLI + stránka v administraci + test + podklady pro tutoriál)
| NN | Příklad | Co se čtenář naučí |
|---|---|---|
| 01 | **Perex na jedno kliknutí** — z textu článku vygeneruje perex (≤ 300 znaků) | první volání API, system prompt, `max_tokens`, cena volání, `FakeLlmClient` |
| 02 | **SEO titulek a meta popis** — JSON `{titulek, meta_popis, klicova_slova[]}` | structured output, validace schématu, retry s chybou; rozšíření: hromadně přes Message Batches API |
| 03 | **Štítky a rubrika** — návrh 3–6 štítků + rubrika z existujícího výčtu | klasifikace levným modelem, enum v schématu, prompt caching seznamu rubrik |
| 04 | **Kontrola před publikací** — tón, osobní údaje (PII), faktická rizika + **demo prompt injection** (článek s vloženým „Ignoruj pokyny…“) | obrana proti injection, oddělení dat značkami, výstup jako seznam nálezů |
| 05 | **Překlad CZ → EN** se zachováním Markdownu a slugu | delší výstup, zachování formátu, porovnání modelů (kvalita × cena) |
| 06 | **Asistent psaní v editoru** — „pokračuj v odstavci / zkrať / zjednoduš“ živě | streaming SSE v PHP i v prohlížeči (fetch + POST), přerušení |
| 07 | **Zeptej se redakce** — chat nad obsahem webu s nástroji `hledej_clanky`, `nacti_clanek` | tool use, agentní smyčka, limit kroků, jen čtecí nástroje |
| 08 | **Sémantické vyhledávání (RAG)** — embeddingy článků v MariaDB `VECTOR`, odpověď s citacemi | embeddingy (Ollama, Voyage později), vektorový index, grounding, citace zdrojů |
| 09 | **AI redaktor (agent s člověkem ve smyčce)** — z tématu: osnova → koncept → sebekontrola → uložení jako *koncept*, publikuje jen admin | plánování, reflexe, nadměrná autonomie (LLM06), schvalování |
| 10 | **MCP server redakce** — CMS jako nástroj pro Claude Code/Desktop (`hledej_clanky`, `statistiky`, prompt `navrhni_clanek`) | Model Context Protocol, oficiální PHP SDK (ověř balíček `mcp/sdk`), STDIO transport, `claude mcp add` |

Ollama (embeddingy, lokální model) běží jen v dev profilu `ai-local` — na VPS (1 CPU) ne.

## Podklady pro tutoriál `docs/ai-priklady/NN.md`
Cíl · Diagram toku (Mermaid) · Prompt (celý) · Klíčový kód (≤ 40 řádků) · Spuštění (CLI + web) ·
Ukázkový výstup · Náklady · Bezpečnost · Co zkusit dál (2–3 úkoly pro čtenáře).
