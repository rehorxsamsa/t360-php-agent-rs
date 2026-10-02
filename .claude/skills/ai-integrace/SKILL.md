---
name: ai-integrace
description: Referenční příručka AI části redakčního systému — rozhraní LlmKlient, volání Claude Messages API z čistého PHP (cURL), structured output, tool use, streaming, prompt caching, embeddingy, náklady, testování bez klíče a zadání 10 AI příkladů. Načti před každou prací na AI.
---

# AI integrace

## Konfigurace (.env)
```
AI_PROVIDER=falesny            # falesny | anthropic | ollama
ANTHROPIC_API_KEY=             # jen v .env, nikdy v repu
AI_MODEL=claude-sonnet-5-5     # generování textu (ověř aktuální názvy modelů v docs)
AI_MODEL_LEVNY=claude-haiku-4-5-20251001   # klasifikace, štítky
AI_DENNI_LIMIT_TOKENU=200000
EMBED_PROVIDER=falesny         # falesny | ollama | voyage
EMBED_MODEL=nomic-embed-text   # Ollama, 768 dimenzí
OLLAMA_URL=http://ollama:11434
```

## Rozhraní (jádro, M5)
```php
interface LlmKlient
{
    public function zprava(LlmPozadavek $p): LlmOdpoved;              // jedno volání
    /** @return \Generator<int, string> */
    public function streamuj(LlmPozadavek $p): \Generator;            // SSE text po kouscích
}
final readonly class LlmPozadavek {
    /** @param list<array{role:string,content:mixed}> $zpravy  @param list<array<string,mixed>> $nastroje */
    public function __construct(
        public string $model, public string $system, public array $zpravy,
        public int $maxTokenu = 1024, public float $teplota = 0.3,
        public array $nastroje = [], public ?array $vynucenyNastroj = null,
        public bool $cacheSystem = false, public string $priklad = '00',
    ) {}
}
interface EmbeddingKlient { /** @return list<float> */ public function vektor(string $text): array; }
```
`FalesnyKlient`: deterministický (hash vstupu → předpřipravená odpověď z `tests/Fixtures/ai/*.json`),
umí i `tool_use` scénáře a stream po slovech. **Všechny testy a CI běží s ním.**

## Claude Messages API — minimum (ověř aktuálnost přes context7 / docs.claude.com)
- `POST https://api.anthropic.com/v1/messages`, hlavičky `x-api-key`, `anthropic-version: 2023-06-01`,
  `content-type: application/json`. Tělo: `model`, `max_tokens`, `system`, `messages`, volitelně
  `tools`, `tool_choice`, `stream`, `temperature`.
- **Prompt caching**: `system` jako pole bloků, poslední blok s `"cache_control": {"type": "ephemeral"}`.
- **Strukturovaný výstup**: (a) vynucený nástroj — `tools:[{name:"vysledek", input_schema:{…}}]`,
  `tool_choice:{type:"tool", name:"vysledek"}` a čti `content[].input`; nebo (b) nativní structured
  outputs, pokud je dostupné — ověř v docs. Vždy validuj proti schématu i v PHP.
- **Tool use smyčka**: dokud `stop_reason == "tool_use"`: vykonej nástroj (jen čtecí!), pošli zpět
  zprávu `role:user` s blokem `{type:"tool_result", tool_use_id, content}`. Limit 5 kroků.
- **Streaming**: `stream:true` → SSE události `message_start`, `content_block_delta`
  (`delta.type == "text_delta"`), `message_delta` (usage), `message_stop`. V PHP: cURL
  `CURLOPT_WRITEFUNCTION` + parser řádků `event:`/`data:`; prohlížeči přeposílej jako SSE.
- Chyby: 429 a 529 → retry s backoff (1 s, 2 s, 4 s), 400/401/403 → bez retry, srozumitelná hláška.
- Náklady: z `usage.input_tokens`, `usage.output_tokens` (+ cache tokeny) × ceník v
  `config/ai-ceny.php` (hodnoty ověř na stránce s cenami, uveď datum ověření).

## Zadání 10 AI příkladů (každý = CLI + stránka v administraci + test + podklady pro tutoriál)
| NN | Příklad | Co se čtenář naučí |
|---|---|---|
| 01 | **Perex na jedno kliknutí** — z textu článku vygeneruje perex (≤ 300 znaků) | první volání API, system prompt, `max_tokens`, cena volání, `FalesnyKlient` |
| 02 | **SEO titulek a meta popis** — JSON `{titulek, meta_popis, klicova_slova[]}` | structured output, validace schématu, retry s chybou; rozšíření: hromadně přes Message Batches API |
| 03 | **Štítky a rubrika** — návrh 3–6 štítků + rubrika z existujícího výčtu | klasifikace levným modelem, enum v schématu, prompt caching seznamu rubrik |
| 04 | **Kontrola před publikací** — tón, osobní údaje (PII), faktická rizika + **demo prompt injection** (článek s vloženým „Ignoruj pokyny…“) | obrana proti injection, oddělení dat značkami, výstup jako seznam nálezů |
| 05 | **Překlad CZ → EN** se zachováním Markdownu a slugu | delší výstup, zachování formátu, porovnání modelů (kvalita × cena) |
| 06 | **Asistent psaní v editoru** — „pokračuj v odstavci / zkrať / zjednoduš“ živě | streaming SSE v PHP i v prohlížeči (EventSource/fetch), přerušení |
| 07 | **Zeptej se redakce** — chat nad obsahem webu s nástroji `hledej_clanky`, `nacti_clanek` | tool use, agentní smyčka, limit kroků, jen čtecí nástroje |
| 08 | **Sémantické vyhledávání (RAG)** — embeddingy článků v MariaDB `VECTOR`, odpověď s citacemi | embeddingy (Ollama/Voyage), vektorový index, grounding, citace zdrojů |
| 09 | **AI redaktor (agent s člověkem ve smyčce)** — z tématu: osnova → koncept → sebekontrola → uložení jako *koncept*, publikuje jen admin | plánování, reflexe, nadměrná autonomie (LLM06), schvalování |
| 10 | **MCP server redakce** — CMS jako nástroj pro Claude Code/Desktop (`hledej_clanky`, `statistiky`, prompt `navrhni_clanek`) | Model Context Protocol, oficiální PHP SDK (ověř balíček `mcp/sdk`), STDIO transport, `claude mcp add` |

Ollama (embeddingy, lokální model) běží jen v dev profilu `ai-local` — na VPS (1 CPU) ne.

## Podklady pro tutoriál `docs/ai-priklady/NN.md`
Cíl · Diagram toku (Mermaid) · Prompt (celý) · Klíčový kód (≤ 40 řádků) · Spuštění (CLI + web) ·
Ukázkový výstup · Náklady · Bezpečnost · Co zkusit dál (2–3 úkoly pro čtenáře).
