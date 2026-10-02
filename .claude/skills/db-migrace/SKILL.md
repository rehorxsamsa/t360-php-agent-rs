---
name: db-migrace
description: Jak v projektu psát a spouštět migrace MariaDB (vlastní jednoduchý migrátor), konvence schématu, seed data a vektorové sloupce. Načti před každou změnou databáze.
---

# Migrace a schéma

## Migrátor (implementuje programátor v M1, používá databazista)
- Soubory `database/migrations/YYYYMMDDHHMM_popis.php`, každý vrací objekt s `up(PDO)` a `down(PDO)`.
- Tabulka `migrace (nazev VARCHAR(190) PK, spusteno_v DATETIME(6))`.
- Příkazy: `bin/konzole migrace:spust`, `migrace:vrat [--kroky=1]`, `migrace:stav`, `db:seed`.
- Každá migrace v transakci, pokud to DDL dovolí (MariaDB DDL implicitně commituje → migrace malé).

## Konvence
- Tabulky a sloupce česky bez diakritiky, snake_case, množné číslo tabulek (`clanky`).
- PK `id BIGINT UNSIGNED AUTO_INCREMENT` (veřejně v URL používej `slug`, ne id).
- `vytvoreno DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`, `upraveno … ON UPDATE …`.
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci` (české řazení!).
- Stav článku: `ENUM('koncept','publikovano','archiv')` + `publikovano_v DATETIME NULL`.
- Mazání článků: tvrdé `DELETE` (požadavek CRUD) + záznam v `audit_log` s kopií titulku.

## Vektory (RAG, AI příklad 08)
```sql
CREATE TABLE clanky_vektory (
  clanek_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  model VARCHAR(100) NOT NULL,
  vektor VECTOR(768) NOT NULL,
  VECTOR INDEX (vektor) M=8 DISTANCE=cosine,
  FOREIGN KEY (clanek_id) REFERENCES clanky(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- dotaz:
SELECT c.id, c.titulek, VEC_DISTANCE_COSINE(v.vektor, VEC_FromText(?)) AS vzdalenost
FROM clanky_vektory v JOIN clanky c ON c.id = v.clanek_id
WHERE c.stav = 'publikovano' ORDER BY vzdalenost LIMIT 5;
```
Dimenzi (768) potvrď s ai-inzenýrem podle zvoleného modelu embeddingů. Syntaxi ověř v
dokumentaci MariaDB pro nainstalovanou verzi.

## Uživatelé DB
- `redakce_app` — práva SELECT/INSERT/UPDATE/DELETE na `redakce`.
- `redakce_migrace` — DDL (jen pro migrace, v prod používá deploy skript).
- `redakce_cteni` — jen SELECT (MCP server pro agenty, port 3307 jen na 127.0.0.1 v dev).
Vytvoření uživatelů: `docker/mariadb/init/01-uzivatele.sh` (hesla z env).
