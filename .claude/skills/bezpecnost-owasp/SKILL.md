---
name: bezpecnost-owasp
description: Bezpečnostní checklist projektu (OWASP Top 10 2025, OWASP Top 10 pro LLM aplikace, ASVS L2 výběr) s konkrétními PHP řešeními. Načti při psaní auth, formulářů, šablon, AI funkcí a při revizi.
---

# Bezpečnostní checklist

## Autentizace a session
- [ ] `password_hash($h, PASSWORD_ARGON2ID)`, `password_verify`, `password_needs_rehash` při loginu.
- [ ] `session_regenerate_id(true)` po přihlášení i změně role; odhlášení = destroy + nová session.
- [ ] Cookie: `HttpOnly`, `Secure` (v prod), `SameSite=Strict`, název `__Host-redakce`.
- [ ] Brute force: tabulka `prihlaseni_pokusy`, max 5 pokusů / 15 min na IP + účet, stejná hláška
      „Neplatné přihlašovací údaje“ (neprozrazovat existenci účtu).
- [ ] Timeout nečinnosti admin session (30 min) a absolutní limit (8 h).
- [ ] Volitelně TOTP 2FA pro admina (backlog).

## Autorizace
- [ ] Middleware `VyzadujeRoli('admin')` na celé skupině `/admin/*` **a** kontrola v use-case.
- [ ] Žádné IDOR: ID z URL vždy ověřit proti oprávnění. Nepublikované články nejsou veřejně čitelné.
- [ ] Odmítnutí = 403 (přihlášený) / redirect na login (nepřihlášený).

## Vstup a výstup
- [ ] PDO: `ATTR_EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`, jen parametrizované dotazy.
      Dynamické `ORDER BY` jen z allowlistu.
- [ ] Escapování: `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')`.
- [ ] Markdown článků přes CommonMark s `html_input: 'strip'`, `allow_unsafe_links: false`.
- [ ] JSON do `<script>`: `json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)`.
- [ ] Redirecty jen na interní cesty (allowlist), žádné `?next=https://…`.
- [ ] Limity velikosti vstupu (titulek 200, perex 500, text 100 000 znaků).

## HTTP hlavičky (middleware `BezpecnostniHlavicky`)
`Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{N}'; style-src 'self';
img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'`
+ `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
`Permissions-Policy: camera=(), microphone=(), geolocation=()`, `Cross-Origin-Opener-Policy: same-origin`,
HSTS jen v produkci za TLS.

## CSRF
- [ ] Token per session (32 B z `random_bytes`), `hash_equals`, u každého POST/PUT/DELETE.

## Tajemství a logy
- [ ] Tajemství jen z prostředí (`getenv`) — nikdy v repu, v logu ani v chybové stránce.
- [ ] `APP_DEBUG=0` v produkci; chyby do logu (stderr kontejneru), uživateli obecná stránka.
- [ ] `audit_log`: kdo, co, kdy, IP (zkrácená) pro každou admin změnu a login.

## LLM (OWASP Top 10 for LLM Applications)
- [ ] **LLM01 Prompt injection**: obsah článku vždy v `<clanek>` značkách + instrukce, že nejde o pokyny.
- [ ] **LLM02 Únik citlivých dat**: do promptu nikdy hesla, e-maily, tokeny, interní ID uživatelů.
- [ ] **LLM05 Nevalidovaný výstup**: výstup modelu = nedůvěryhodný vstup → JSON schéma, escapování,
      nikdy `eval`, nikdy přímo do SQL/HTML.
- [ ] **LLM06 Nadměrná autonomie**: nástroje pro model jen čtecí; zápis (např. uložení konceptu)
      vždy až po potvrzení adminem (human-in-the-loop).
- [ ] **LLM07 Únik systémového promptu**: v promptu nejsou tajemství.
- [ ] **LLM10 Neomezená spotřeba**: rate limit AI endpointů (10/min/admin), denní limit tokenů,
      `max_tokens` u každého volání, timeout.

## Infrastruktura
- [ ] Kontejnery ne-root, prod `read_only`, `no-new-privileges`, DB port neexponovaný ven.
- [ ] `composer audit` v CI, pinované verze obrazů, Dependabot/Renovate (backlog).
