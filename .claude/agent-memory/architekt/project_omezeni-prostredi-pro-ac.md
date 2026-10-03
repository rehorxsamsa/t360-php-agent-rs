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

**Why:** zjištěno při plánu 003 (M3, 2026-10-03); bez toho tým navrhne AC, které nejde ověřit.

**How to apply:** při plánech s HTTP/session/E2E navrhuj AC v mezích hooku a Playwrightu; ověř
aktuální hook a compose, mohly se změnit. Viz [[project-vyukovy-rezim]], [[project-db-nazvy-a-migrace]].
