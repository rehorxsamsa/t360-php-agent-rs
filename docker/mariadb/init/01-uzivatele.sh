#!/bin/bash
# Běží jen při prvním vytvoření volume (prázdný datadir).
# Vytvoří DB redakce_test a uživatele redakce_app (DML), redakce_migrace (DDL), redakce_cteni (SELECT).
# Hesla se předávají jen přes proměnné prostředí a nikdy se nevypisují.
set +x
set -eu

: "${DB_PASSWORD:?chybí DB_PASSWORD}"
: "${DB_MIGRACE_PASSWORD:?chybí DB_MIGRACE_PASSWORD}"
: "${DB_READONLY_PASSWORD:?chybí DB_READONLY_PASSWORD}"

# Escapování zpětných lomítek a jednoduchých uvozovek pro SQL řetězec.
esc() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g"; }

APP_PW="$(esc "$DB_PASSWORD")"
MIG_PW="$(esc "$DB_MIGRACE_PASSWORD")"
RO_PW="$(esc "$DB_READONLY_PASSWORD")"

# Heslo roota přes MYSQL_PWD (ne přes argument příkazové řádky).
MYSQL_PWD="${MARIADB_ROOT_PASSWORD:?chybí MARIADB_ROOT_PASSWORD}" mariadb -uroot --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`redakce\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
ALTER DATABASE \`redakce\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
CREATE DATABASE IF NOT EXISTS \`redakce_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

CREATE USER IF NOT EXISTS 'redakce_app'@'%' IDENTIFIED BY '${APP_PW}';
CREATE USER IF NOT EXISTS 'redakce_migrace'@'%' IDENTIFIED BY '${MIG_PW}';
CREATE USER IF NOT EXISTS 'redakce_cteni'@'%' IDENTIFIED BY '${RO_PW}';

GRANT SELECT, INSERT, UPDATE, DELETE ON \`redakce\`.* TO 'redakce_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`redakce_test\`.* TO 'redakce_app'@'%';

-- Migrace: explicitní seznam práv (bez GRANT OPTION, FILE, SUPER apod.).
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER, LOCK TABLES ON \`redakce\`.* TO 'redakce_migrace'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER, LOCK TABLES ON \`redakce_test\`.* TO 'redakce_migrace'@'%';

GRANT SELECT ON \`redakce\`.* TO 'redakce_cteni'@'%';
-- Limity čtecího uživatele (MCP): souběžná spojení, dotazy za hodinu, doba jednoho dotazu v sekundách.
ALTER USER 'redakce_cteni'@'%' WITH MAX_USER_CONNECTIONS 3 MAX_QUERIES_PER_HOUR 2000 MAX_STATEMENT_TIME 5;
FLUSH PRIVILEGES;
SQL
