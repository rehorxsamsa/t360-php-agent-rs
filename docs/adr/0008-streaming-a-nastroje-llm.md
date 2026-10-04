# ADR-0008: Streaming a tool use v LLM klientovi, streamovaná HTTP odpověď přes fetch + SSE
- **Stav:** navrženo
- **Datum:** 2026-10-04
- **Autor:** agent architekt
- **Souvisí:** [plán 008](../plan/008-streaming-a-nastroje.md), [ADR-0006](0006-vlastni-llm-klient-curl.md)
  (rozšiřuje, nenahrazuje), [ADR-0003](0003-anglicke-identifikatory.md), skilly `ai-integrace`, `bezpecnost-owasp`

## Kontext
M7 (zúžený rozsah) přidává AI příklad 06 (asistent psaní, odpověď se zobrazuje živě a jde přerušit) a 07
(„Zeptej se redakce“, model volá čtecí nástroje v agentní smyčce). Platí:
- Jádro z M6 (ADR-0006) má jen `LlmClient::complete()`; na rozhraní stojí 5 příkladů, `MeteredLlmClient`
  a testovací dvojníci (`ScriptedLlmClient`, `ScriptedHttpTransport`). Změna nesmí rozbít M6.
- Claude Messages API (ověřeno 2026-10-04 na platform.claude.com, stránky Streaming, Define tools,
  Handle tool calls, Thinking): `"stream": true` → SSE události `message_start` (usage se vstupem),
  `content_block_start/delta/stop` (`text_delta`, `thinking_delta`, `signature_delta`, `input_json_delta`),
  `message_delta` (`stop_reason`, usage **kumulativně**), `message_stop`, libovolně `ping`, chyba uprostřed proudu
  jako `event: error` (např. `overloaded_error`) při HTTP 200; neznámé události se mají ignorovat.
  Tool use: `stop_reason == "tool_use"`, bloky `tool_use {id, name, input}`; odpověď v další zprávě `user`
  s bloky `tool_result {tool_use_id, content, is_error?}`, které jdou **první** a všechny v jedné zprávě.
  `claude-sonnet-5-5` odmítá vynucený nástroj (`tool_choice` `any`/`tool` → 400), `auto` je výchozí.
  Sonnet 5.5 má adaptivní přemýšlení zapnuté a **bloky `thinking` se v tool use smyčce musí vrátit
  nezměněné** (včetně `signature`), jinak 400.
- Prohlížeč: `EventSource` umí jen GET bez těla; placené volání ale musí být POST s CSRF (AGENTS.md).
- `Http\Response` má tělo jako řetězec a posílá se až po průchodu middleware; nativní PHP session drží
  zámek souboru po celou dobu požadavku; nginx bufferuje odpověď FastCGI, `fastcgi_read_timeout` je 120 s.
- Bez nové composer závislosti; projekt je výukový (čitelnost před úplností).

## Rozhodnutí
**Streaming je samostatné rozhraní `StreamingLlmClient extends LlmClient` s metodou
`stream(LlmRequest, callable(string): bool $onText): LlmResponse`; tool use se přidává jako volitelná data
v `LlmRequest`/`LlmResponse` (definice nástrojů, bloky obsahu) a smyčku řídí příklad. Do prohlížeče jde
proud jako SSE přes `fetch()` (POST s CSRF), na serveru přes `Response` s producentem (closure), který
se spustí v `send()` až po middleware.**

- **Streaming v klientovi:** `StreamingLlmClient` implementují `AnthropicClient`, `FakeLlmClient`
  a `MeteredLlmClient`; `LlmClient` zůstává beze změny (dvojníci M6 platí dál). Callback dostává jen textové
  delty; vrácené `false` = přerušení (klient přestane číst, cURL spojení zavře). Výsledkem je běžné
  `LlmResponse` (celý text, `stopReason`, usage), takže `MeteredLlmClient` loguje streamované volání stejně
  jako `complete()` — limit se rezervuje před voláním, usage se skládá z `message_start` a přepisuje
  kumulativními hodnotami z `message_delta`.
- **Přerušení** není chyba: `stopReason = 'aborted'`, `status ok`; výstupní tokeny = max(poslední známá
  hodnota, odhad znaky/4 z odeslaného textu), protože finální `message_delta` nepřišla. Chyba uprostřed proudu
  (`event: error`) = `LlmCallFailed` bez opakování (část textu už uživatel viděl); HTTP chyba před prvním bajtem
  proudu se opakuje podle ADR-0006.
- **HTTP transport:** `HttpTransport` dostane druhou metodu `stream(url, headers, body, callable(string): bool):
  HttpResult` (2xx tělo jde po kouscích do callbacku, jiný status vrátí celé tělo pro mapování chyb).
  Vlastní `SseParser` (řádky `event:`/`data:`, kousky rozdělené kdekoli, CRLF, komentáře).
- **Tool use:** `LlmRequest::$tools` (definice ve tvaru API: `name`, `description`, `input_schema`) a zprávy
  s obsahem buď řetězec, nebo seznam bloků. `LlmResponse::$content` nese **surové bloky odpovědi** (včetně
  `thinking` se `signature`), aby je smyčka vrátila nezměněné; `LlmResponse::$toolCalls` je seznam `ToolCall`.
  `tool_choice` se neposílá (výchozí `auto`). Streaming s nástroji se v M7 nepodporuje (`\LogicException`).
- **Bezpečnost nástrojů (LLM06):** nástroje jsou jen čtecí a dostávají jen rozhraní `ArticleRepository`
  (veřejné čtení = publikované a ne budoucí). Neznámý nástroj nebo neplatný vstup → `tool_result` s `is_error`,
  nikdy výjimka ani jiná akce. Limit kroků, souběžných volání, velikosti výstupu nástroje a času běhu.
- **HTTP vrstva:** `Response` dostane volitelný `?\Closure $producer` (`Response::stream(...)`); `send()` pošle
  hlavičky (`Content-Type: text/event-stream`, `Cache-Control: no-store`, `X-Accel-Buffering: no` — nginx tím
  vypne bufferování jen pro tuto odpověď), vyprázdní output buffery, nastaví `ignore_user_abort(true)` a spustí
  producenta nad `StreamOutput` (`PhpStreamOutput`: `echo` + `flush()`, přerušení přes `connection_aborted()`).
  Controller před vrácením proudu uvolní zámek session (`Session::release()`). Výjimky uvnitř producenta se
  mění na SSE událost `error` (middleware už neběží).
- **Prohlížeč:** externí `public/assets/ai-stream.js` (CSP beze změny): `fetch` s `FormData` (včetně `_csrf`),
  čtení `response.body` po kouscích, `AbortController` pro tlačítko „Přerušit“, text jen přes `textContent`.
- **Schéma DB se nemění:** každý krok agenta = jeden řádek `ai_calls`, přerušení = `stop_reason 'aborted'`.

## Důsledky
+ M6 kód i testy zůstávají platné; streaming a nástroje dostanou limit a log z `MeteredLlmClient` zadarmo.
+ Čtenář tutoriálu vidí celý tok: SSE z API → PHP parser → SSE do prohlížeče → `fetch` reader; i agentní
  smyčku bez SDK.
+ Testovatelnost bez sítě: skriptované kousky proudu (`ScriptedHttpTransport::stream`), falešný klient umí
  proud i tool use scénář, `BufferStreamOutput` simuluje přerušení.
− `HttpTransport` má novou metodu → testovací dvojník `ScriptedHttpTransport` se musí doplnit.
− Streamovaná odpověď obchází `ErrorHandlerMiddleware` (producent běží až v `send()`), chyby musí ošetřit sám.
− Přerušené volání má jen odhad výstupních tokenů; chyba uprostřed proudu se loguje s nulovou spotřebou
  (podhodnocení limitu, přijato pro výuku).
− Proud drží PHP-FPM workera po celou dobu generování (dev pool má málo workerů).
− Odchylka od skillu `ai-integrace` (říká „metoda `stream()` v `LlmClient`“, „EventSource/fetch“) — skill je
  potřeba upravit (změna `.claude/`, souhlas člověka).

## Zvažované alternativy
- **`stream()` přímo v `LlmClient`** — jednodušší typy, ale rozbije všechny implementace a dvojníky M6
  (`ScriptedLlmClient`) a příklady 01–05 streaming nepotřebují. Odmítnuto.
- **`EventSource` + GET endpoint** — nativní reconnect, ale GET by spouštěl placené volání (odkaz, prefetch,
  obnovení stránky) a nenese CSRF token v těle. Odmítnuto ve prospěch `fetch` + POST.
- **Dvoufázově: POST uloží úlohu, GET ji streamuje** — čisté HTTP sémantiky, ale potřebuje úložiště úloh
  a jednorázové tokeny. Odmítnuto pro MVP (backlog, pokud bude potřeba reconnect).
- **Oddělené rozhraní `StreamingHttpTransport`** — nerozbije dvojníka, ale `AnthropicClient` by potřeboval
  dva transporty. Odmítnuto (jediná reálná implementace je cURL).
- **Oficiální PHP SDK** (`createStream()`, `MessageAccumulator`) — nová závislost a černá skříňka pro výuku
  (viz ADR-0006). Odmítnuto.
- **Vynutit odpověď bez nástrojů v posledním kroku (`tool_choice: none`)** — API to u Sonnet 5.5 dovoluje,
  ale porušilo by to pravidlo „žádné `tool_choice` v `src/Ai`“ a přidalo větev; při dosažení limitu kroků se
  místo toho vrátí dosavadní text s varováním. Backlog.
- **Sloupec `steps`/`run_id` v `ai_calls`** — umožní seskupit kroky jednoho dotazu, ale vyžaduje migraci;
  počet volání ukáže výsledek příkladu. Odmítnuto pro MVP.
