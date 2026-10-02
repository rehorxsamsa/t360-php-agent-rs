#!/usr/bin/env bash
# PreToolUse(Bash): deterministická pojistka nad rámec permissions v settings.json.
source "$(dirname "$0")/_spolecne.sh"
vyzaduj_jq
CMD=$(jq -r '.tool_input.command // ""')

shopt -s nocasematch
[[ "$CMD" =~ (^|[;&|[:space:]])git[[:space:]]+push ]] && zamitni "git push je zakázán – push dělá člověk. Commit stačí."
[[ "$CMD" =~ --force|--force-with-lease|push[[:space:]]+-f ]] && zamitni "Force operace jsou zakázány."
[[ "$CMD" =~ git[[:space:]]+(filter-branch|filter-repo|rebase|commit[[:space:]].*--amend) ]] && zamitni "Přepis historie je zakázán. Udělej nový commit."
[[ "$CMD" =~ git[[:space:]]+config[[:space:]]+--global ]] && zamitni "Globální git konfiguraci agenti nemění."
[[ "$CMD" =~ (^|[;&|[:space:]])sudo[[:space:]] ]] && zamitni "sudo je zakázáno. Vše běží v Dockeru."
[[ "$CMD" =~ rm[[:space:]]+-[a-z]*r[a-z]*f?[[:space:]]+(/|~|\$HOME|\.\.|\*)([[:space:]]|$) ]] && zamitni "Rekurzivní mazání mimo projekt je zakázáno."
[[ "$CMD" =~ (curl|wget)[^|]*\|[[:space:]]*(ba|z)?sh ]] && zamitni "Spouštění skriptů stažených z internetu (curl | sh) je zakázáno."
[[ "$CMD" =~ (^|[;&|[:space:]])(ssh|scp|rsync)[[:space:]] ]] && zamitni "Připojení k serverům (ssh/scp/rsync) agenti nedělají – nasazení řeší člověk."
[[ "$CMD" =~ docker[[:space:]]+(system|volume)[[:space:]]+prune|compose[[:space:]]+down[[:space:]].*-v ]] && zeptej_se "Smazání Docker volumes zničí data DB. Opravdu?"
[[ "$CMD" =~ (cat|less|more|head|tail|grep|sed|awk)[[:space:]].*\.env([[:space:]]|$|\.prod|\.local) ]] && zamitni "Čtení .env je zakázáno (tajemství). Použij .env.example."
[[ "$CMD" =~ (^|[;&|[:space:]])(printenv|env)([[:space:]]|$) ]] && zamitni "Výpis proměnných prostředí může prozradit tajemství."
[[ "$CMD" =~ chmod[[:space:]]+(-R[[:space:]]+)?777 ]] && zamitni "chmod 777 je zakázán."
exit 0
