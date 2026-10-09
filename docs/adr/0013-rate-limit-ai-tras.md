# ADR-0013: Rate limit AI tras – middleware za autentizací, klíč = admin, posuvné okno v tabulce MariaDB
- **Stav:** přijato
- **Datum:** 2026-10-09
- **Autor:** agent architekt
- **Souvisí:** [plán 013](../plan/013-rate-limit-ai-tras.md), [plán 006](../plan/006-ai-jadro.md) (`MeteredLlmClient`, denní limit),
  [ADR-0007](0007-casy-v-databazi-utc-vs-praha.md) (časy), [ADR-0008](0008-streaming-a-nastroje-llm.md) (06 proud mimo middleware),
  [ADR-0010](0010-ai-redaktor-workflow-se-schvalenim.md) (09), skill `bezpecnost-owasp` (LLM10: 10/min/admin)

## Kontext
- AI trasy v administraci (`POST /admin/ai/{01–05}`, `/06/proud`, `/07`, `/08`, `/08/indexace`, `/09`) chrání jen globální denní limit tokenů
  (`AI_DENNI_LIMIT_TOKENU`) v `MeteredLlmClient`. Jeden admin ho vyčerpá všem a nic nebrání dávce souběžných dlouhých běhů
  (09: 3–8 volání, 50–110 s). Revize plánu 010 to hodnotí jako středně závažný nález (OWASP LLM10).
- `ai_calls` má řádek na volání LLM a zapisuje se až po něm. Embeddingy (08) se nelogují.
- Řetěz middleware: `SecurityHeaders → ErrorHandler → Routing → Csrf → AdminAccess`. `RouteMatch` nese handler `[třída, metoda]`.
- Streamovaná odpověď 06 běží až v `Response::send()`, mimo middleware. Chybu po startu proudu lze poslat jen jako SSE `error` se stavem 200.
- Bez nových závislostí (APCu, Redis). `REMOTE_ADDR` v Dockeru obvykle nese bránu sítě a `TRUSTED_PROXIES` aplikace neimplementuje.
- Výukový režim, vše musí jít předvést a otestovat s falešným klientem a `Clock`.

## Rozhodnutí
**Rate limit AI tras vynucuje `AiRateLimitMiddleware` jako poslední článek řetězu (za CSRF a `AdminAccess`). Klíčem je ID přihlášeného
admina a algoritmem posuvné okno se záznamy v nové tabulce `ai_rate_limit_hits`. Záznam vzniká na začátku požadavku, odmítnutí vrací
429 + `Retry-After` a zapisuje audit `ai.rate_limited`.**

- **Kbelíky:** `ai` (běžné AI trasy, výchozí `AI_LIMIT_POZADAVKU=10/60`) a `ai_heavy` (09 návrh, `AI_LIMIT_NAROCNYCH=3/600`).
  Kbelík určuje mapa handlerů v middleware. Výslovné výjimky: `09/ulozit`, `09/zahodit` (nevolají LLM). Kontraktní test hlídá, že každá
  `POST /admin/ai…` trasa je v jedné z map.
- **Algoritmus:** `COUNT(*)`, `MIN(created_at)` za `created_at > now − okno`. Při `count ≥ limit` odmítnout s
  `Retry-After = ceil(oldest + okno − now)` (min. 1), jinak zapsat záznam. Odmítnutí se nezapisuje do okna.
- **Čas:** výhradně `Clock`, `created_at` plní aplikace (Europe/Prague dle ADR-0007), sloupec bez `DEFAULT`.
- **Odpověď:** HTML šablona `error` (429), u `WritingAssistantController::stream` JSON `{"error"}`. Kontrola proběhne před startem proudu.
- **Platí pro všechny poskytovatele** včetně falešného klienta. Vypínač se nezavádí.
- Obecný omezovač pro jiné účely (přihlášení) se nezavádí. Ten bude mít jiný klíč a může stát nad `audit_log`.

## Důsledky
+ Limit na uživatele: jeden admin nevyčerpá AI ostatním, dávku drahých běhů 09 omezuje vlastní kbelík.
+ Započítá i běžící požadavky (záznam na začátku), jednotkou je HTTP požadavek. `Retry-After` je přesný a testovatelný bez `sleep`.
+ Controllery a LLM vrstva se nemění. Nová AI trasa bez rozhodnutí o limitu neprojde testy.
+ Cizí web ani nepřihlášený nemůže adminovi limit „vypálit“ (za CSRF a autentizací).
− Nová tabulka a migrace, řádky přibývají bez úklidu (zatím zanedbatelný objem).
− Počet a zápis nejsou atomické: N souběžných session může limit překročit až o N−1 (přijato, tvrdou pojistkou nákladů zůstává denní limit).
− Do okna se počítají i požadavky, které controller odmítne (422/404), protože kontrola předchází validaci.
− Middleware se sestavuje pro každý požadavek: chybná konfigurace `AI_LIMIT_*` vrátí 500 i na veřejných stránkách.
− Stránka 429 je obecná, vyplněný text otázky v ní není (06 přes fetch vstup neztrácí).

## Zvažované alternativy
- **Počítat `ai_calls`** (bez migrace) – nevidí běžící požadavky, počítá volání místo požadavků (09 = 3–8), chybí indexace 08. Odmítnuto.
- **Záznamy v `audit_log`** (akce `ai.request`) – bez migrace a s indexem `(action, created_at)`, ale `created_at` plní DB (UTC, `Clock`
  ho v integračních testech neovládne) a audit by se zaplnil běžným provozem. Odmítnuto. Audit dostane jen odmítnutí.
- **Počítadlo v session** – obejde ho nové přihlášení nebo druhý prohlížeč. Odmítnuto.
- **Klíč podle IP** – v Dockeru mají všichni z hostitele stejnou adresu a za proxy by bylo potřeba `TRUSTED_PROXIES`. Odmítnuto.
- **APCu / Redis** – nová rozšíření nebo služba (brána člověka), data mimo DB. Odmítnuto (YAGNI).
- **Kontrola v `MeteredLlmClient`** – jednotkou je volání LLM a 06 by dostal chybu až uvnitř proudu (stav 200). Odmítnuto.
- **Kontrola v každém controlleru** – 429 přímo ve stránce se zachovaným vstupem a počítání až po validaci, ale 5 controllerů a riziko,
  že nová trasa kontrolu vynechá. Odloženo (UX vylepšení).
- **Atomické vynucení** (`SELECT … FROM users WHERE id = ? FOR UPDATE` v transakci, nebo `INSERT … SELECT … WHERE (SELECT COUNT(*)) < limit`)
  – přesné i při souběhu, ale transakce navíc a u `INSERT … SELECT` riziko deadlocku ze sdílených zámků mezer. Odloženo, dokud nebude víc adminů.
- **Pevné okno (počítadlo na minutu)** – jednodušší řádek na kbelík, ale na hranici oken propustí dvojnásobek. Odmítnuto.
- **`GET_LOCK` jako limit souběhu** – bez tabulky, ale řeší jen souběh, ne frekvenci. Mimo rozsah (možné doplnění).
