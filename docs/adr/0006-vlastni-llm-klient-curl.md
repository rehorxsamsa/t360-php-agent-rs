# ADR-0006: Vlastní klient Claude Messages API přes cURL, strukturovaný výstup přes `output_config.format`
- **Stav:** navrženo (schvaluje člověk na bráně 1 plánu 006)
- **Datum:** 2026-10-03
- **Autor:** agent architekt
- **Souvisí:** [plán 006](../plan/006-ai-jadro.md), [ADR-0003](0003-anglicke-identifikatory.md), skilly
  `ai-integrace`, `bezpecnost-owasp`

## Kontext
M6 zavádí AI jádro, na kterém stojí všech 10 AI příkladů (M6: 01–05, M7: 06–10). Platí tato omezení:
- Zadání: vše běží bez API klíče (`AI_PROVIDER=falesny`), s klíčem se volá Claude API. Projekt je
  výukový, kód má být čitelný a bez „černých skříněk“; nová composer závislost je brána pro člověka.
- Dokumentace Claude API (ověřeno 2026-10-03 na platform.claude.com, modely, ceny, chyby, effort,
  structured outputs) ukazuje u aktuálních modelů změny, se kterými skill `ai-integrace` nepočítá:
  - `claude-sonnet-5-5` (a další modely od 4.7) vrací **400** při nastavení `temperature`/`top_p`/`top_k`;
  - `claude-sonnet-5-5` **nepodporuje vynucený nástroj** (`tool_choice` typu `tool`/`any` → 400), takže
    skillem doporučený strukturovaný výstup „přes vynucený nástroj“ nefunguje;
  - strukturovaný výstup je GA jako `output_config.format = {type: "json_schema", schema}` u Sonnet 5.5
    i Haiku 4.5 (bez beta hlavičky), ale schéma nepodporuje `maxLength`, `maxItems`, `minimum` aj.;
  - `output_config.effort` podporuje Sonnet 5.5, **Haiku 4.5 ne**; Sonnet 5.5 má adaptivní přemýšlení
    zapnuté ve výchozím stavu (v odpovědi mohou být bloky `thinking`, tokeny se účtují jako výstup).
- Volání modelu je síťová operace s výpadky (429, 500, 529, 504) a cenou za každé volání.

## Rozhodnutí
**Píšeme vlastní tenkého klienta `App\Ai\Client\AnthropicClient` nad rozhraním `App\Ai\LlmClient`;
HTTP jde přes malé rozhraní `HttpTransport` (implementace `CurlHttpTransport`), strukturovaný výstup
přes `output_config.format` s validací v PHP, náklady a limity v dekorátoru `MeteredLlmClient`.**

- `LlmClient::complete(LlmRequest): LlmResponse` — jediná metoda v M6; streaming (`stream()`) přidá M7.
  Implementace: `AnthropicClient` (cURL) a `FakeLlmClient` (deterministický, bez sítě). `OllamaClient`
  vznikne, až bude mít reálné použití (YAGNI).
- `LlmRequest` neobsahuje teplotu ani vynucený nástroj. Volitelné `effort` se posílá jen modelům, které
  ho podle katalogu modelů podporují. Odpověď skládá text jen z bloků `text` (bloky `thinking` ignoruje).
- Strukturovaný výstup = `output_config.format` + **vlastní validace tvaru i délek v PHP** + jedno
  opakování se zprávou o chybě. Schéma nese jen to, co API podporuje.
- Katalog modelů `config/ai-models.php` (ceny za MTok s datem ověření, podpora `effort`). Model mimo
  katalog se nevolá — bez ceny nelze hlídat limit.
- `MeteredLlmClient` (dekorátor nad skutečným klientem): denní limit tokenů před voláním, zápis metadat
  volání (tokeny, cena, trvání, stav) do tabulky `ai_calls` po volání. Obsah promptů a odpovědí se neukládá.
- Retry jen tam, kde nehrozí dvojí účtování ani překročení časového limitu webu: 429 s `retry-after` ≤ 5 s,
  500 a 529, nejvýše 2 opakování (1 s, 2 s). Bez opakování: ostatní 4xx, 504 a vypršení času.
- Adresa API je konstanta v kódu (žádná proměnná prostředí → žádné SSRF), klíč jen z prostředí
  (`#[\SensitiveParameter]`), nikdy v logu ani ve výjimce.

## Důsledky
+ Žádná nová composer závislost; čtenář tutoriálu vidí celé volání API (hlavičky, JSON, chyby, retry).
+ Testovatelnost bez sítě: `AnthropicClient` se testuje proti skriptovanému `HttpTransport`, zbytek
  aplikace proti `FakeLlmClient`.
+ Měření nákladů a limit jsou na jednom místě a platí automaticky i pro příklady 06–10 (M7).
− Udržujeme si sami mapování API (nové parametry, nové modely, změny chyb). Katalog modelů a ceník se
  musí ručně aktualizovat (datum ověření je v souboru).
− Odchylka od skillu `ai-integrace` (české identifikátory, vynucený nástroj, `teplota`, `ai_volani`) a od
  definice agenta `ai-inzenyr`; jejich úprava je změna `.claude/` → souhlas člověka. Do té doby má
  přednost tento ADR a plán 006.
− Streaming (M7) bude vyžadovat druhou metodu rozhraní a SSE parser nad `CURLOPT_WRITEFUNCTION`.

## Zvažované alternativy
- **Oficiální PHP SDK Anthropic** (dokumentace ukazuje třídu `Anthropic\Client`) — řeší retry, typy
  i streaming, ale je to nová závislost (brána člověka) a pro výuku černá skříňka; přechod je později
  snadný (jedna implementace `LlmClient`). Odmítnuto pro M6.
- **Strukturovaný výstup přes vynucený nástroj** (dle skillu) — `claude-sonnet-5-5` ho odmítá (400).
  Odmítnuto.
- **Strukturovaný výstup jen promptem („odpověz JSONem“)** — funguje všude, ale bez záruky tvaru; jako
  výukový kontrast zmíní tutoriál. Odmítnuto jako výchozí cesta.
- **`file_get_contents` se stream kontextem místo cURL** — horší kontrola timeoutů a hlaviček, žádná
  cesta ke streamingu. Odmítnuto.
- **Měření a limit uvnitř každého příkladu** — duplicita a snadno se zapomene. Odmítnuto ve prospěch
  dekorátoru.
