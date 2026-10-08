---
name: db-migrace
description: Konvence schématu MariaDB, formát migrací a migrátor (anglické názvy podle ADR-0004).
---

# Migrace a schéma

Názvy tabulek, sloupců, indexů a ENUM hodnot jsou **anglicky** (ADR-0004). Česky zůstává
jen obsah dat, CLI příkazy `bin/konzole` a názvy DB/uživatelů z nasazovacího kontraktu.

## Migrátor (plán 002)
- Soubory `database/migrations/YYYYMMDDHHMM_popis.php` (`^\d{12}_[a-z0-9_]+\.php$`), každý
  `return new class implements Migration { up(\PDO), down(\PDO) }` (`App\Infrastructure\Migration\Migration`).
- Evidence: tabulka `migrations (name VARCHAR(190) PK, executed_at DATETIME(6))`.
- Příkazy: `make migrate` (= `bin/konzole migrace:spust`), `migrace:vrat [--kroky=N]`, `migrace:stav`.
  Seed (`db:seed`) zatím neexistuje, přijde s M4.
- DDL v MariaDB implicitně commituje, proto bez transakcí: **jedna tabulka = jedna migrace**,
  záznam do `migrations` až po úspěšném `up`.
- Pořadí respektuje cizí klíče, `down` je přesný opak `up` (`DROP TABLE` v opačném pořadí).
- Commitnutou migraci nikdy neměň, napiš novou.

## Konvence
- Tabulky množné číslo, snake_case: `users`, `categories`, `tags`, `articles`, `article_tags`
  (spojovací tabulka), výjimka `audit_log`.
- PK `id BIGINT UNSIGNED AUTO_INCREMENT` (veřejně v URL `slug`, ne id).
- Časy `DATETIME(6)`: `created_at NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`,
  `updated_at … ON UPDATE CURRENT_TIMESTAMP(6)`, `published_at NULL`.
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci` (české řazení).
- Cizí klíče `<entita>_id`, autor změn `created_by`/`updated_by` (FK `users`, `ON DELETE SET NULL`).
- Názvy omezení: `uq_<tabulka>_<sloupce>`, `idx_<tabulka>_<sloupce>`, `fk_<tabulka>_<sloupec>`.
- ENUM hodnoty = `case` hodnoty PHP enumů: `articles.status ENUM('draft','published','archived')`,
  `users.role ENUM('admin')`.
- `articles.category_id NOT NULL` + `ON DELETE RESTRICT` (rubriku s články nelze smazat);
  `article_tags` obě FK `ON DELETE CASCADE`.
- Mazání článků: tvrdé `DELETE` + záznam v `audit_log` s kopií titulku v `summary`
  (`entity_id` bez FK, entita už neexistuje).
- Každý index odůvodni dotazem, který ho používá; ověř `EXPLAIN`.

## Vektory (RAG, AI příklad 08, M7b — plán 009, ADR-0009)
Skutečné schéma je v `database/migrations/202610080001_create_article_embeddings_table.php`:
`article_id` (PK, FK `ON DELETE CASCADE`), `model`, `source_hash`, `embedding VECTOR(768) NOT NULL`, `indexed_at`
a `VECTOR INDEX idx_article_embeddings_embedding (embedding) M=8 DISTANCE=cosine`. Dimenze 768 = model `embeddinggemma`.

**Past: filtr po indexu.** Dotaz, který používá vektorový index, bere kandidáty přes `ORDER BY vzdálenost LIMIT n`
a ostatní podmínky se uplatní až poté, takže `WHERE a.status = 'published'` ve stejném dotazu může vrátit méně
řádků, než kolik publikovaných článků existuje (ověřeno na MariaDB 11.8.9: z 20 kandidátů zůstalo 18).
Proto dvoustupňově — vnitřní poddotaz vybere kandidáty přes index, vnější teprve filtruje publikovanost:
```sql
SELECT a.id, a.slug, a.title, c.distance
FROM (
  SELECT article_id, VEC_DISTANCE_COSINE(embedding, VEC_FromText(:vector)) AS distance
  FROM article_embeddings WHERE model = :model ORDER BY distance LIMIT 20
) c JOIN articles a ON a.id = c.article_id
WHERE a.status = 'published' AND a.published_at <= :now
ORDER BY c.distance, a.id LIMIT :limit;
```
Podrobnosti a `EXPLAIN` jsou v `docs/ai-priklady/08.md`. Nepublikované články se nikdy neindexují a jejich vektory se
při indexaci mažou (obrana ve třech vrstvách). Syntaxi vždy ověř v dokumentaci MariaDB pro nainstalovanou verzi.

## Uživatelé DB
- `redakce_app` — SELECT/INSERT/UPDATE/DELETE na `redakce` (a `redakce_test`).
- `redakce_migrace` — DDL (jen migrace; v dev ho má kontejner `app` pro `make migrate` a integrační testy).
- `redakce_cteni` — jen SELECT (MCP server pro agenty, port 3307 jen na 127.0.0.1 v dev).
Vytvoření: `docker/mariadb/init/01-uzivatele.sh` (hesla z env). Integrační testy mažou tabulky
**jen v `redakce_test`**, nikdy v `redakce`.
