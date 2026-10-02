#!/usr/bin/env bash
# PreToolUse(Bash) jen pro agenta 'databazista': přímé SQL smí jen číst. Schéma = migrace.
source "$(dirname "$0")/_spolecne.sh"
vyzaduj_jq
CMD=$(jq -r '.tool_input.command // ""')
shopt -s nocasematch
if [[ "$CMD" =~ (mariadb|mysql)([[:space:]]|$) ]] && [[ ! "$CMD" =~ bin/konzole ]]; then
  if [[ "$CMD" =~ (INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|REPLACE|GRANT|RENAME)[[:space:]] ]]; then
    zamitni "Přímý zápis do DB je zakázán. Změny schématu piš jako migraci, data jako seed."
  fi
fi
exit 0
