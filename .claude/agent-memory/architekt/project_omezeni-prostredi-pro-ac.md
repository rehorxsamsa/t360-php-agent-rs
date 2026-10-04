---
name: project-omezeni-prostredi-pro-ac
description: Omezení prostředí t360, která ovlivňují návrh AC a HTTP/session řešení (curl hook, Playwright na http://web, PHPUnit a session, PHPStan)
metadata:
  type: project
---

- **curl hook** (`.claude/hooks/bash-strazce.sh`): jen jedna URL `http://localhost:8080/…`, volby
  `-s -S -i -I -D -d -H -X GET|HEAD|POST -w -m -o /dev/null --path-as-is`; žádné `-c/-b` (cookie jar)
  ani `@`. HTTP AC se session pište s ručním `-H 'Cookie: …'`.
- **Playwright MCP** jde na `http://web/` (ne localhost) → `Secure` cookie by prohlížeč zahodil;
  v dev proto cookie bez `Secure` (env `SESSION_COOKIE_SECURE`, M9 zapne), bez `__Host-` prefixu.
- **Nativní PHP session v PHPUnit** selže (headers already sent) → v testech vždy dvojník
  `ArraySession` za rozhraním `Http\Session\Session`; v `KernelTest` ho nahradit v kontejneru.
- **PHPStan max** hlásí vždy pravdivá porovnání — kontrola role u enumu s jedním případem (`Role::Admin`)
  neprojde; autorizace se slučuje s autentizací, dokud nepřibude druhá role.
- **CSRF před routerem** by změnilo kontrakt 404/405 z M2 → plán 003 přesunul `Router::match`
  do `RoutingMiddleware` před `CsrfMiddleware`.

- **Unit testy přes Kernel** (`KernelTest`, `TestContainer`) berou skutečný `config/container.php` a env
  `DB_NAME=redakce_test` → každý nový repozitář volaný z veřejné stránky musí mít dvojníka v paměti,
  jinak `GET /` v unit sadě sáhne do DB (po `TestDatabase::reset()` bez tabulek → 500).
- **PHP obraz nemá `intl`** (jen `pdo_mysql`, `opcache` + výchozí `mbstring`) → české datum vlastním polem měsíců.
- **`Request` zahazuje ne-řetězce z `$_POST`/`$_GET`** → pole formuláře `tags[]` potřebují `inputList()`
  (plán 005). `TestContainer::replaceArticleDependencies` musí nahrazovat každý nový repozitář.
- **nginx `fastcgi_read_timeout 30s`** (`docker/nginx/default.conf`) — dlouhá volání (LLM) potřebují zvýšit
  (plán 006 navrhl 120 s). **`Session` ukládá jen `string|int`** → strukturovaná data přes JSON.
  Kontejner `app` dostává jen proměnné vyjmenované v `compose.yaml` `environment:` (`.env` je `/dev/null`).
- **Kolace `utf8mb4_czech_ci` + PAD SPACE**: `WHERE slug = ?` ignoruje velikost písmen a koncové mezery →
  slug z URL validovat regexem před dotazem.

**Why:** zjištěno při plánech 003 a 004 (M3/M4, 2026-10-03); bez toho tým navrhne AC, které nejde ověřit.

**How to apply:** při plánech s HTTP/session/E2E navrhuj AC v mezích hooku a Playwrightu; ověř
aktuální hook a compose, mohly se změnit. Viz [[project-vyukovy-rezim]], [[project-db-nazvy-a-migrace]].
