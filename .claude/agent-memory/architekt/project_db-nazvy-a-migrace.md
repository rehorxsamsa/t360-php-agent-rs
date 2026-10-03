---
name: project-db-nazvy-a-migrace
description: DB názvy anglicky (ADR-0004), skill db-migrace je zastaralý (česky); app kontejner v M1 neviděl migrační heslo
metadata:
  type: project
---

- Tabulky/sloupce/ENUM anglicky dle ADR-0004 (plán 002, M2): `articles`, `categories`, `tags`,
  `article_tags`, `users`, `audit_log`, `migrations(name, executed_at)`, `excerpt` = perex.
  Skill `.claude/skills/db-migrace/SKILL.md` ještě píše česky — dokud ho někdo (se souhlasem)
  nepřepíše, má přednost ADR-0004. CLI příkazy `bin/konzole migrace:*` zůstávají česky (kontrakt).
- Revize M1 (S4) záměrně nedala `DB_MIGRACE_PASSWORD` do služby `app`; plán 002 navrhuje vrátit
  (otázka 1). Ověř v `compose.yaml`, jak to dopadlo.
- Dev mount `app` je `.:/app:ro` + rw podadresáře; nové top-level adresáře (`config/`,
  `templates/`) jsou v kontejneru jen ke čtení — pro čtení stačí, prod obraz (M9) je musí kopírovat.
- Číslování plánů: 002 = M2 (router/DI/migrátor); CI, které plán 001 slíbil jako 002, dostane 003.

**Why:** rozpory mezi skilly a ADR se opakují; tým jinak dostává protichůdné pokyny.

**How to apply:** při plánech s DB nejdřív zkontroluj ADR-0004 a aktuální `compose.yaml`;
skill `db-migrace` ber jako zastaralý, dokud neodkazuje na ADR-0004. Viz [[project-vyukovy-rezim]].
