---
name: project-db-nazvy-a-migrace
description: DB názvy anglicky (ADR-0004), skill db-migrace je zastaralý (česky); app kontejner v M1 neviděl migrační heslo
metadata:
  type: project
---

- Tabulky/sloupce/ENUM anglicky dle ADR-0004 (plán 002, M2): `articles`, `categories`, `tags`,
  `article_tags`, `users`, `audit_log`, `migrations(name, executed_at)`, `excerpt` = perex.
  Skill `db-migrace` je od M2 přepsaný anglicky (soulad s ADR-0004, ověřeno 2026-10-03).
  CLI příkazy `bin/konzole migrace:*` zůstávají česky (kontrakt).
- Revize M1 (S4) záměrně nedala `DB_MIGRACE_PASSWORD` do služby `app`; plán 002 navrhuje vrátit
  (otázka 1). Ověř v `compose.yaml`, jak to dopadlo.
- Dev mount `app` je `.:/app:ro` + rw podadresáře; nové top-level adresáře (`config/`,
  `templates/`) jsou v kontejneru jen ke čtení — pro čtení stačí, prod obraz (M9) je musí kopírovat.
- Číslování plánů: 002 = M2, 003 = M3 (přihlášení), 004 = M4 (veřejná část, zúžená; rubriky/štítky/
  hledání = M4b), 005 = M5 (administrace článků, zúžená; CRUD rubrik/štítků = M5b), 006 = M6 (AI jádro,
  tabulka `ai_calls`, migrace `202610030007`), **007 = M8** (audit log, opravy, tutoriál; M7 přeskočen v pořadí);
  **008 = M7 zúžený** (příklady 06–07, bez migrace), **009 = M7b** (příklad 08 RAG, migrace `202610080001` `article_embeddings`,
  ADR-0009), **010 = M7c zúžený** (jen příklad 09, ADR-0010, bez migrace), příklad 10 MCP = M7d (011?), CI = další volné číslo
  (ověř v STAV.md). Nová akce auditu nepotřebuje migraci (`audit_log.action VARCHAR(50)`). Migrační heslo v `app` je od M2.
- Seed (M4 návrh): `database/seeds/demo_content.php` vrací objekt `Infrastructure\Seed\Seed`, příkaz
  `db:seed` (otázka 3 plánu 004), běží jako `redakce_app`, jen doplňuje podle slugu, nic nemaže.
- Časy: PHP `Europe/Prague`, MariaDB v UTC → v SQL nepoužívat `NOW()` pro `published_at`, čas přes `Clock`.
  Totéž `created_at`/`updated_at` (DEFAULT / ON UPDATE CURRENT_TIMESTAMP běží v UTC) → plán 005 je zapisuje
  explicitně z `Clock`. **ADR-0007 (plán 007, navrženo):** sloupec plněný DB = UTC (`audit_log.created_at`,
  `users.*_at`, `migrations`), plněný aplikací z `Clock` = Praha; `ConnectionFactory` připíchne `time_zone '+00:00'`,
  převod při čtení jen v repozitáři; seed od M8 píše časy článků explicitně. Ověř, zda ADR přijat.
- `EMULATE_PREPARES=false` → pojmenovaný parametr nelze v jednom dotazu použít dvakrát.

**Why:** rozpory mezi skilly a ADR se opakují; tým jinak dostává protichůdné pokyny.

**How to apply:** při plánech s DB nejdřív zkontroluj ADR-0004 a aktuální `compose.yaml`;
skill `db-migrace` ber jako zastaralý, dokud neodkazuje na ADR-0004. Viz [[project-vyukovy-rezim]].
