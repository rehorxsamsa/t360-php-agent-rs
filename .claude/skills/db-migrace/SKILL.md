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

## Vektory (RAG, AI příklad 08, M7)
```sql
CREATE TABLE article_embeddings (
  article_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  model VARCHAR(100) NOT NULL,
  embedding VECTOR(768) NOT NULL,
  VECTOR INDEX (embedding) M=8 DISTANCE=cosine,
  CONSTRAINT fk_article_embeddings_article_id FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- dotaz:
SELECT a.id, a.title, VEC_DISTANCE_COSINE(v.embedding, VEC_FromText(?)) AS distance
FROM article_embeddings v JOIN articles a ON a.id = v.article_id
WHERE a.status = 'published' ORDER BY distance LIMIT 5;
```
Dimenzi (768) potvrď s ai-inzenýrem podle zvoleného modelu embeddingů. Syntaxi ověř v
dokumentaci MariaDB pro nainstalovanou verzi.

## Uživatelé DB
- `redakce_app` — SELECT/INSERT/UPDATE/DELETE na `redakce` (a `redakce_test`).
- `redakce_migrace` — DDL (jen migrace; v dev ho má kontejner `app` pro `make migrate` a integrační testy).
- `redakce_cteni` — jen SELECT (MCP server pro agenty, port 3307 jen na 127.0.0.1 v dev).
Vytvoření: `docker/mariadb/init/01-uzivatele.sh` (hesla z env). Integrační testy mažou tabulky
**jen v `redakce_test`**, nikdy v `redakce`.
