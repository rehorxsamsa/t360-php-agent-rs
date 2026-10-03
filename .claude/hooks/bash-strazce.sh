#!/usr/bin/env bash
# PreToolUse(Bash): deterministická pojistka nad rámec permissions v settings.json.
# Příkaz se rozdělí na segmenty (; && || | & ( ) ` nový řádek) a pravidla "na pozici příkazu"
# se vyhodnocují po segmentech, takže se nemíchají vlastnosti různých příkazů v jednom řádku.
source "$(dirname "$0")/_spolecne.sh"
vyzaduj_jq
CMD=$(jq -r '.tool_input.command // ""')

shopt -s nocasematch

ASK_REASON=""
dotaz() { [[ -z "$ASK_REASON" ]] && ASK_REASON="$1"; return 0; }
# has_q <text>: obsahuje text velké "Q" (značka odstraněného obsahu uvozovek)? Rozlišuje velikost písmen
# (nocasematch jinak bere i malé "q", např. v "jq").
has_q() { local r=1; shopt -u nocasematch; [[ "$1" == *Q* ]] && r=0; shopt -s nocasematch; return $r; }

# --- Pravidla nad celým řetězcem (nezávislá na kontextu).
[[ "$CMD" =~ (^|[;\&|[:space:]])git[[:space:]]+push ]] && zamitni "git push je zakázán – push dělá člověk. Commit stačí."
[[ "$CMD" =~ --force|--force-with-lease|push[[:space:]]+-f ]] && zamitni "Force operace jsou zakázány."
[[ "$CMD" =~ git[[:space:]]+(filter-branch|filter-repo|rebase) ]] && zamitni "Přepis historie je zakázán. Udělej nový commit."
[[ "$CMD" =~ git[[:space:]]+config[[:space:]]+--global ]] && zamitni "Globální git konfiguraci agenti nemění."
[[ "$CMD" =~ (^|[;\&|[:space:]])sudo[[:space:]] ]] && zamitni "sudo je zakázáno. Vše běží v Dockeru."
[[ "$CMD" =~ rm[[:space:]]+-[a-z]*r[a-z]*f?[[:space:]]+(/|~|\$HOME|\.\.|\*)([[:space:]]|$) ]] && zamitni "Rekurzivní mazání mimo projekt je zakázáno."
[[ "$CMD" =~ (curl|wget)[^|]*\|[[:space:]]*(ba|z)?sh ]] && zamitni "Spouštění skriptů stažených z internetu (curl | sh) je zakázáno."
[[ "$CMD" =~ (^|[;\&|[:space:]])(ssh|scp|rsync)[[:space:]] ]] && zamitni "Připojení k serverům (ssh/scp/rsync) agenti nedělají – nasazení řeší člověk."
[[ "$CMD" =~ docker[[:space:]]+volume[[:space:]]+(prune|rm|remove)|docker[[:space:]]+system[[:space:]]+prune|compose[[:space:]]+down[[:space:]].*(-v|--volumes) ]] && dotaz "Smazání Docker volumes zničí data DB. Opravdu?"
[[ "$CMD" =~ chmod[[:space:]]+(-R[[:space:]]+)?777 ]] && zamitni "chmod 777 je zakázán."
# (--no-verify / --amend hlídá segmentová kontrola níže – globální regex dával falešné poplachy v textu zpráv.)

# --- Těla heredoců (např. víceřádková commit zpráva) nejsou příkazy: vyřízni je před analýzou segmentů.
# Jen u `git commit …` a `cat <<…` (jinak by se obešla kontrola např. u `bash <<EOF`).
strip_heredocs() {
  awk '
    inhd { t = $0; sub(/^\t+/, "", t); if (t == tag) inhd = 0; next }
    {
      print
      c = $0; gsub(/<<</, "@@@", c)
      if (match(c, /<<-?[ \t]*\\?["\047]?[A-Za-z_][A-Za-z0-9_]*/) &&
          (c ~ /git[ \t]+commit/ || c ~ /(^|[^A-Za-z_])cat[ \t]+<</)) {
        s = substr(c, RSTART, RLENGTH)
        sub(/^<<-?[ \t]*\\?["\047]?/, "", s)
        tag = s; inhd = 1
      }
    }'
}
CMD_NH=$(printf '%s\n' "$CMD" | strip_heredocs)

# --- Odstranění obsahu uvozovek: krátké jednoslovné hodnoty zůstanou, ostatní se nahradí "Q",
# aby text v commit zprávě nebo v sh -c '…' nebyl brán jako příkaz. "$(…)" a `…` uvnitř "…" zůstávají.
strip_quotes() {
  local s=$1 out="" i c q="" buf="" n=${#1}
  for ((i = 0; i < n; i++)); do
    c=${s:i:1}
    if [[ -z "$q" ]]; then
      if [[ "$c" == "'" || "$c" == '"' ]]; then q=$c; buf=""
      elif [[ "$c" == '\' ]]; then out+="${s:i:2}"; i=$((i + 1))
      else out+=$c; fi
    else
      if [[ "$c" == "$q" ]]; then
        if [[ "$q" == '"' && ( "$buf" == *'$('* || "$buf" == *'`'* ) ]]; then out+=" $buf "
        elif [[ ${#buf} -le 32 && "$buf" =~ ^[A-Za-z0-9_./:@=,+-]+$ ]]; then out+=$buf
        else out+="Q"; fi
        q=""
      elif [[ "$c" == '\' && "$q" == '"' ]]; then buf+="${s:i:2}"; i=$((i + 1))
      else buf+=$c; fi
    fi
  done
  [[ -n "$q" ]] && out+="Q"
  printf '%s' "$out"
}

[[ "$CMD_NH" =~ (MAKEFLAGS|MAKEFILES|MFLAGS|GNUMAKEFLAGS|MAKEOVERRIDES) ]] && zamitni "Zmínka o MAKEFLAGS/MAKEFILES/MFLAGS/GNUMAKEFLAGS/MAKEOVERRIDES (jakkoli zapsaná) je zakázána – mění chování make (načtení cizího souboru)."

STRIPPED=$(strip_quotes "$CMD_NH")
SEGMENTS=$(printf '%s' "$STRIPPED" | sed -E 's/[;&|()`]+/\n/g')

value_opt() {  # volby docker compose run/exec, které berou hodnotu v dalším argumentu
  case "$1" in
    -e|--env|-u|--user|-w|--workdir|--index|-v|--volume|-p|--publish|--name|--entrypoint|-l|--label|--cap-add|--cap-drop|--mount|--network|--net|--pid|--device|--userns|--env-from-file|--pull) return 0 ;;
  esac
  return 1
}
global_value_opt() {  # globální volby docker compose, které berou hodnotu
  case "$1" in
    -f|--file|-p|--project-name|--profile|--env-file|--project-directory|--ansi|--parallel|-c|--context|--progress) return 0 ;;
  esac
  return 1
}

# scan_opts <run|exec> <index>: projde volby podpříkazu, nastaví J na první ne-volbu (služba).
scan_opts() {
  local kind=$1 t name val
  J=$2
  while [[ $J -lt ${#T[@]} && "${T[J]}" == -* ]]; do
    t=${T[J]}; val=""
    if [[ "$t" == --*=* ]]; then name=${t%%=*}; val=${t#*=}; J=$((J + 1))
    elif [[ "$t" == --* ]]; then
      name=$t
      if value_opt "$name"; then val=${T[J + 1]:-}; J=$((J + 2)); else J=$((J + 1)); fi
    elif [[ ${#t} -gt 2 ]]; then
      name=${t:0:2}; val=${t:2}; J=$((J + 1))
      if [[ "${t: -1}" == "u" ]]; then name="-u"; val=${T[J]:-}; J=$((J + 1)); fi
    else
      name=$t
      if value_opt "$name"; then val=${T[J + 1]:-}; J=$((J + 2)); else J=$((J + 1)); fi
    fi
    case "$name" in
      --privileged) zamitni "docker compose $kind --privileged je zakázáno (únik z kontejneru na hostitele)." ;;
      -u|--user) [[ "$val" =~ ^(0|root)(:.*)?$ ]] && zamitni "docker compose $kind jako root (-u 0 / --user root) je zakázáno." ;;
    esac
    if [[ "$kind" == "run" ]]; then
      case "$name" in
        -v|--volume|--mount|--cap-add|--pid|--device|--userns) zamitni "docker compose run s -v/--volume/--mount/--cap-add/--pid/--device/--userns je zakázáno (únik z kontejneru na hostitele)." ;;
        --network|--net) [[ "$val" == "host" ]] && zamitni "docker compose run s --network host je zakázáno (únik z kontejneru na hostitele)." ;;
      esac
    fi
  done
}

COMPOSER_ALLOW='^(install|check|test|qa|cs|cs:fix|stan|lint|audit|show|outdated|validate|dump-autoload)$'
# composer_gate <první ne-volba po "composer">: jen allowlist podpříkazů, ostatní = brána člověka.
composer_gate() {
  local sub=$1
  [[ -z "$sub" ]] && return 0
  [[ "$sub" =~ $COMPOSER_ALLOW ]] || dotaz "Composer podpříkaz '$sub' mimo allowlist (přidání/změna závislostí je brána člověka)."
  return 0
}

# Přísnější brána pro `make composer ARGS="…"`: celá hodnota (podpříkaz + jen dlouhé volby).
MAKE_ARGS_ALLOW='^(install|check|test|qa|audit|validate|dump-autoload|show|outdated)( -[-a-z]+)*$'
# make_args_gate: projde VŠECHNY výskyty ARGS= v příkazu (i ve více make voláních za && / ;).
make_args_gate() {
  local gate=$1 rest=$CMD_NH raw
  local re='ARGS=((\\.|"[^"]*"|'"'"'[^'"'"']*'"'"'|[^[:space:];&|"'"'"'\\])*)'
  while [[ "$rest" =~ $re ]]; do
    raw=${BASH_REMATCH[1]}
    rest=${rest#*"${BASH_REMATCH[0]}"}
    [[ "$raw" == *'$('* || "$raw" == *'${'* || "$raw" == *'`'* ]] && zamitni "ARGS obsahuje \$( \${ nebo zpětný apostrof – expanze v make/shellu je zakázána."
    raw=${raw//[\"\']/}
    raw=$(printf '%s' "$raw" | sed -E 's/\\(.)/\1/g')
    [[ $gate -eq 1 && ! "$raw" =~ $MAKE_ARGS_ALLOW ]] && dotaz "make composer ARGS='$raw' mimo allowlist (přidání/změna závislostí je brána člověka)."
  done
}

# check_target <cesta>: cíl zápisu přes Bash do chráněného souboru -> dotaz.
check_target() {
  local t=$1 rel
  [[ -z "$t" || "$t" == -* ]] && return 0
  if [[ "$t" == *'$'* ]]; then dotaz "Zápis do cíle s proměnnou ($t) – nelze ověřit, zda nejde o chráněný soubor."; return 0; fi
  if [[ "$t" == *[\*\?\[]* ]]; then
    # Zástupné znaky: ověř adresář, ve kterém se expanduje (kořen projektu a chráněné adresáře = dotaz).
    local dir=.
    [[ "$t" == */* ]] && dir=${t%/*}
    if [[ "$dir" == "." ]] || chraneny_soubor "$(normalizuj_rel "$dir/zz")"; then
      dotaz "Zápis do cíle se zástupnými znaky ($t) v chráněném místě – nelze ověřit, zda nejde o chráněný soubor."
    fi
    return 0
  fi
  rel=$(normalizuj_rel "$t")
  if chraneny_soubor "$rel" || [[ "$rel" =~ ^\.env(\..*)?$ && "$rel" != ".env.example" ]]; then
    dotaz "Zápis přes Bash do chráněného souboru ($rel) – změny konfigurace spouštěné na hostiteli schvaluje člověk."
  fi
}

# file_arg_gate <cesta> <kontext>: čtený soubor (jq soubor, git commit -F/-t) musí být v projektu a nesmí být .env*.
# Mimo projekt / .env* = zamítnutí; cesta s uvozovkami, \, `, $ = dotaz (nelze ověřit).
file_arg_gate() {
  local t=$1 ctx=$2 rel
  [[ -z "$t" || "$t" == "-" ]] && return 0
  if has_q "$t" || [[ "$t" == *'\'* || "$t" == *'$'* || "$t" == *'`'* ]]; then
    dotaz "$ctx: cesta s uvozovkami/zpětným lomítkem/expanzí ($t) – nelze ověřit, zda nejde o soubor mimo projekt nebo .env."
    return 0
  fi
  [[ "$t" == "~"* ]] && zamitni "$ctx: cesta do domovského adresáře ($t) je zakázána."
  rel=$(normalizuj_rel "$t")
  [[ "$rel" == /* || "$rel" == ..* ]] && zamitni "$ctx: soubor mimo projekt ($t) je zakázán."
  if [[ "$rel" =~ (^|/)\.env(\.[A-Za-z0-9_.-]+)?$ && ! "$rel" =~ \.env\.example$ ]] || [[ "$t" =~ (^|/)\.[a-z]*[*?\[] ]]; then
    zamitni "$ctx: čtení .env ($t) je zakázáno (tajemství)."
  fi
  return 0
}

# write_check: přesměrování a zapisující příkazy v aktuálním segmentu (T, C0, P).
write_check() {
  local i t tgt insed=0
  for ((i = 0; i < ${#T[@]}; i++)); do
    t=${T[i]}
    if [[ "$t" =~ ^[0-9]*\>\>?(.*)$ ]]; then
      tgt=${BASH_REMATCH[1]}; [[ -z "$tgt" ]] && tgt=${T[i + 1]:-}
      check_target "$tgt"
    fi
  done
  case "$C0" in
    cp|mv|tee|install|ln|touch|rm|truncate|dd) ;;
    sed|perl|ruby)
      for t in "${T[@]:P+1}"; do [[ "$t" =~ ^-[A-Za-z]*i || "$t" == --in-place* ]] && insed=1; done
      [[ $insed -eq 1 ]] || return 0 ;;
    *) return 0 ;;
  esac
  for t in "${T[@]:P+1}"; do
    [[ "$t" == of=* ]] && t=${t#of=}
    [[ "$t" =~ ^[0-9]*\> ]] && continue
    check_target "$t"
  done
}

COMPOSE_RUNEXEC=0
while IFS= read -r seg; do
  read -ra T <<<"$seg"
  [[ ${#T[@]} -eq 0 ]] && continue

  # --- pozice příkazu: přeskoč přiřazení proměnných a obalující slova
  P=0
  while [[ $P -lt ${#T[@]} ]] && [[ "${T[P]}" =~ ^[A-Za-z_][A-Za-z0-9_]*= || "${T[P]}" =~ ^(time|nohup|command|exec|xargs|nice|\{|!|if|then|do|else|while|until)$ ]]; do P=$((P + 1)); done
  C0=${T[P]:-}
  # Normalizace názvu příkazu: /usr/bin/php, ./make, p\hp -> php, make.
  C0=${C0//\\/}; C0=${C0##*/}
  # Název příkazu po odstranění uvozovek ("Q") nebo s expanzí ($) nelze ověřit – brána člověka (i make, env, printenv).
  if has_q "$C0" || [[ "$C0" == *'$'* ]]; then
    dotaz "Název příkazu obsahuje uvozovky nebo expanzi ($C0) – nelze ověřit, co se spustí."
  fi
  # Přiřazení COMPOSE_* / DOCKER_HOST… přepíná soubory/démona docker compose (obchází -f compose.yaml).
  for t in "${T[@]}"; do
    [[ "$t" =~ ^(COMPOSE_[A-Za-z0-9_]*|DOCKER_HOST|DOCKER_CONTEXT|DOCKER_CONFIG)= ]] && zamitni "Nastavení ${t%%=*} mění chování docker compose (jiný soubor/démon) – zakázáno. COMPOSE_FILE patří jen do .env."
  done

  # Pravidlo 0: PHP/Composer/Node na hostiteli nikdy.
  [[ "$C0" =~ ^(php|composer|node|npm|npx)$ ]] && zamitni "php/composer/node/npm/npx na hostiteli nejsou (pravidlo 0). Použij: docker compose exec -T app php … / make composer ARGS=\"…\" / make test."
  # Výpis prostředí jen na pozici příkazu (ne slovo v commit zprávě).
  [[ "$C0" =~ ^(printenv|env)$ ]] && zamitni "Výpis proměnných prostředí může prozradit tajemství."

  # Čtení .env: čtecí příkaz kdekoli v segmentu + argument .env / .env.* (kromě .env.example), i přes
  # zástupné znaky (.env*, .e*) a přesměrování vstupu (< .env, $(< .env)).
  READER=0; ENVFILE=0; PREV=""
  for t in "${T[@]}"; do
    [[ "$t" =~ ^(cat|less|more|head|tail|grep|egrep|fgrep|rg|ag|ack|sed|awk|gawk|jq|xxd|od|hexdump|base64|strings|cut|sort|nl|tac|diff|cmp|source|\.|paste|tr|fold|column|wc|zcat|vim|nano|view|bat|find|xargs|python3?|perl|ruby)$ ]] && READER=1
    TN=${t#<}
    if [[ "$TN" =~ (^|/)\.env(\.[A-Za-z0-9_.-]+)?$ && ! "$TN" =~ \.env\.example$ ]] || [[ "$TN" =~ (^|/)\.[a-z]*[*?\[] ]]; then
      if [[ $READER -eq 1 || "$t" == \<* || "$PREV" == "<" ]]; then ENVFILE=1; fi
    fi
    PREV=$t
  done
  [[ $ENVFILE -eq 1 ]] && zamitni "Čtení .env je zakázáno (tajemství). Použij .env.example."
  # jq: vestavěné čtení prostředí (env, $ENV) + čtení/načítání souborů přes volby a poziční argumenty.
  if [[ "$C0" == "jq" ]]; then
    JQ_ENV_RE='(^|[^A-Za-z_])(\$ENV|env)([^A-Za-z_]|$)'
    JQ_HAS_Q=0
    for ((k = P + 1; k < ${#T[@]}; k++)); do
      t=${T[k]}
      has_q "$t" && JQ_HAS_Q=1
      [[ "$t" =~ $JQ_ENV_RE ]] && zamitni "jq env/\$ENV vypisuje prostředí hostitele (tajemství)."
    done
    # Filtr v uvozovkách je nečitelný ("Q") – pak se hledá env v celém příkazu.
    if [[ $JQ_HAS_Q -eq 1 && "$CMD_NH" =~ $JQ_ENV_RE ]]; then
      zamitni "jq env/\$ENV vypisuje prostředí hostitele (tajemství)."
    fi
    JQ_POS=0; JQ_ARGSMODE=0; k=$((P + 1))
    while [[ $k -lt ${#T[@]} ]]; do
      t=${T[k]}
      if [[ "$t" =~ ^[0-9]*[\<\>]+$ ]]; then k=$((k + 2)); continue; fi
      if [[ "$t" =~ ^[0-9]*[\<\>] ]]; then k=$((k + 1)); continue; fi
      if [[ "$t" =~ ^--(rawfile|slurpfile|argfile|from-file|library-path) || "$t" =~ ^-[A-Za-z]*[fL] ]]; then
        zamitni "jq ${t%%=*} (--rawfile/--slurpfile/-f/--from-file/-L) čte soubory mimo kontrolu – zakázáno."
      fi
      if [[ "$t" == "--arg" || "$t" == "--argjson" ]]; then k=$((k + 3)); continue; fi
      if [[ "$t" == "--indent" ]]; then k=$((k + 2)); continue; fi
      if [[ "$t" == "--args" || "$t" == "--jsonargs" ]]; then JQ_ARGSMODE=1; k=$((k + 1)); continue; fi
      if [[ "$t" == -* ]]; then k=$((k + 1)); continue; fi
      JQ_POS=$((JQ_POS + 1))
      # První poziční argument je filtr, další jsou soubory.
      [[ $JQ_POS -gt 1 && $JQ_ARGSMODE -eq 0 ]] && file_arg_gate "$t" "jq"
      k=$((k + 1))
    done
  fi

  # curl: allowlist – právě jedna URL http://localhost:8080/… a jen bezpečné volby.
  if [[ "$C0" == "curl" ]]; then
    [[ "$CMD_NH" == *@* ]] && zamitni "curl s '@' (soubor jako hodnota volby) je zakázán."
    CURL_URLS=0; k=$((P + 1))
    while [[ $k -lt ${#T[@]} ]]; do
      t=${T[k]}
      if [[ "$t" =~ ^[0-9]*[\<\>]+$ ]]; then k=$((k + 2)); continue; fi
      if [[ "$t" =~ ^[0-9]*[\<\>] ]]; then k=$((k + 1)); continue; fi
      if [[ "$t" =~ ^-[sSiI]+$ ]]; then k=$((k + 1)); continue; fi
      if [[ "$t" == "-X" ]]; then
        [[ "${T[k + 1]:-}" =~ ^(GET|HEAD|POST)$ ]] || zamitni "curl -X smí mít jen GET, HEAD nebo POST."
        k=$((k + 2)); continue
      fi
      if [[ "$t" =~ ^-X(GET|HEAD|POST)$ ]]; then k=$((k + 1)); continue; fi
      # Výukový režim: povoleno i -D <soubor|->, -m <s>, -d <data>, -o /dev/null, --path-as-is.
      if [[ "$t" == "-D" || "$t" == "-m" || "$t" == "-d" ]]; then k=$((k + 2)); continue; fi
      if [[ "$t" == "-o" ]]; then
        [[ "${T[k + 1]:-}" == "/dev/null" ]] || zamitni "curl -o smí jen /dev/null."
        k=$((k + 2)); continue
      fi
      if [[ "$t" == "--path-as-is" ]]; then k=$((k + 1)); continue; fi
      if [[ "$t" == "-w" || "$t" == "-H" ]]; then k=$((k + 2)); continue; fi
      if [[ "$t" =~ ^-[wH]. ]]; then k=$((k + 1)); continue; fi
      if [[ "$t" == -* ]]; then
        zamitni "curl s volbou '$t' je zakázán (povoleno: -s -S -sS -i -I -w <fmt> -H <hlavička> -X GET|HEAD)."
      fi
      [[ "$t" =~ ^http://localhost:8080/ ]] || zamitni "curl smí jen URL http://localhost:8080/… (dostal '$t')."
      CURL_URLS=$((CURL_URLS + 1))
      k=$((k + 1))
    done
    [[ $CURL_URLS -eq 1 ]] || zamitni "curl musí mít právě jednu URL http://localhost:8080/… (nalezeno $CURL_URLS)."
  fi
  # Rekurzivní hledání bez vynechání .env* by .env našlo.
  if [[ "$C0" =~ ^(grep|egrep|fgrep|rg|ag|ack)$ ]]; then
    REC=0; EXCL=0
    [[ "$C0" =~ ^(rg|ag|ack)$ ]] && REC=1
    for t in "${T[@]:P+1}"; do
      [[ "$t" =~ ^-[A-Za-z]*[rR] || "$t" == --recursive || "$t" == --dereference-recursive ]] && REC=1
      [[ "$t" =~ ^--exclude=\.env(\*)?$ ]] && EXCL=1
    done
    [[ $REC -eq 1 && $EXCL -eq 0 ]] && dotaz "Rekurzivní hledání bez vynechání .env* (přidej --exclude=.env*) by mohlo vypsat tajemství."
  fi

  # Zápis přes Bash do chráněných souborů (sed -i, cp, mv, tee, >, >> …).
  write_check

  # git commit: -n / --no-verify (i zkratky --no-v…), --amend (i --am…), obejití hooků přes core.hooksPath.
  if [[ "$C0" == "git" ]]; then
    IS_COMMIT=0
    for t in "${T[@]}"; do [[ "$t" == "commit" ]] && IS_COMMIT=1; done
    for t in "${T[@]}"; do
      [[ "$t" =~ ^core\.hookspath ]] && zamitni "Změna core.hooksPath obchází hooky – zakázáno."
      # Volby, které zapisují soubory / spouštějí externí programy / čtou mimo repo.
      [[ "$t" =~ ^--(outp|no-in|ext-diff|textconv) ]] && zamitni "git s volbou '$t' (--output/--no-index/--ext-diff/--textconv) zapisuje soubory nebo spouští externí programy – zakázáno."
    done
    # Globální volby před podpříkazem (-c, -C, --git-dir, --work-tree, --exec-path, --config-env) = brána člověka.
    GI=$((P + 1))
    while [[ $GI -lt ${#T[@]} && "${T[GI]}" == -* ]]; do
      t=${T[GI]}
      if [[ "$t" =~ ^(-c|-C|--config-env|--git-dir|--work-tree|--namespace|--super-prefix)$ ]]; then
        dotaz "Globální volba git ($t) mění konfiguraci/adresář/repozitář – brána člověka."
        GI=$((GI + 2)); continue
      fi
      if [[ "$t" =~ ^(-c.|-C.|--config-env=|--git-dir=|--work-tree=|--exec-path|--namespace=|--super-prefix=) ]]; then
        dotaz "Globální volba git ($t) mění konfiguraci/adresář/repozitář/cestu programů – brána člověka."
      fi
      GI=$((GI + 1))
    done
    GSUB=${T[GI]:-}
    [[ "$GSUB" == "format-patch" ]] && dotaz "git format-patch zapisuje soubory (.patch) – brána člověka."
    if [[ "$GSUB" == "config" ]]; then
      GCFG_READ=0
      for t in "${T[@]}"; do [[ "$t" =~ ^(--get|--list$|-l$) ]] && GCFG_READ=1; done
      [[ $GCFG_READ -eq 1 ]] || dotaz "git config bez --get*/--list/-l mění konfiguraci – brána člověka."
    fi
    if [[ $IS_COMMIT -eq 1 ]]; then
      for ((k = P + 1; k < ${#T[@]}; k++)); do
        t=${T[k]}
        [[ "$t" =~ ^-[A-Za-z]*n[A-Za-z]*$ || "$t" =~ ^--no-v ]] && zamitni "git commit -n / --no-verify je zakázán – hooky se neobcházejí."
        [[ "$t" =~ ^--am(e(n(d)?)?)?$ ]] && zamitni "git commit --amend je zakázán – přepis historie. Udělej nový commit."
        # Zpráva ze souboru (-F/--file) nebo šablona (-t/--template): jen soubor v projektu, ne .env*.
        if [[ "$t" =~ ^--(fil|file|te|tem|temp|templ|templa|templat|template)=(.*)$ ]]; then
          file_arg_gate "${BASH_REMATCH[2]}" "git commit"
        elif [[ "$t" =~ ^--(fil|file|te|tem|temp|templ|templa|templat|template)$ || "$t" =~ ^-[A-Za-z]*[Ft]$ ]]; then
          file_arg_gate "${T[k + 1]:-}" "git commit"
        elif [[ "$t" =~ ^-[Ft]. && ! "$t" =~ ^-[A-Za-z]*[mC] ]]; then
          file_arg_gate "${t:2}" "git commit"
        fi
      done
    fi
  fi

  # make: žádné volby (-f, --eval, -C, -n …), žádná přiřazení kromě ARGS=, žádné expanze; brána závislostí.
  if [[ "$C0" == "make" ]]; then
    for ((k = 0; k < P; k++)); do
      [[ "${T[k]}" =~ ^[A-Za-z_][A-Za-z0-9_]*= && ! "${T[k]}" =~ ^ARGS= ]] && zamitni "make: přiřazení proměnné před příkazem (${T[k]%%=*}=) je zakázáno – povoleno je jen ARGS=."
    done
    MK=0
    for ((k = P + 1; k < ${#T[@]}; k++)); do
      t=${T[k]}
      [[ "$t" == -* ]] && zamitni "make s volbou ($t) je zakázáno (-f/--eval/-C/-n … spouští cizí kód). Povoleno: make <cíl> [ARGS=…]."
      [[ "$t" == *=* && ! "$t" =~ ^ARGS= ]] && zamitni "make s přiřazením proměnné (${t%%=*}=) je zakázáno – povoleno je jen ARGS=."
      [[ "$t" == "Q" ]] && zamitni "make s uvozovkami mimo hodnotu ARGS= je zakázáno."
      [[ "$t" == *'$'* ]] && zamitni "make s proměnnými/expanzí v argumentech je zakázáno."
      [[ "$t" == "composer" ]] && MK=1
    done
    make_args_gate "$MK"
  fi

  # --- docker compose …: najdi "docker compose", přeskoč globální volby, urči podpříkaz.
  DI=-1
  for ((k = 0; k + 1 < ${#T[@]}; k++)); do
    if [[ "${T[k]}" == "docker" && "${T[k + 1]}" == "compose" ]]; then DI=$k; break; fi
  done
  [[ $DI -lt 0 ]] && continue
  j=$((DI + 2))
  while [[ $j -lt ${#T[@]} && "${T[j]}" == -* ]]; do
    if [[ "${T[j]}" != *=* ]] && global_value_opt "${T[j]}"; then j=$((j + 2)); else j=$((j + 1)); fi
  done
  SUBCMD=${T[j]:-}

  case "$SUBCMD" in
    config)
      Q=0
      for ((k = j + 1; k < ${#T[@]}; k++)); do
        [[ "${T[k]}" =~ ^(-q|--quiet|--no-interpolate)$ ]] && Q=1
      done
      [[ $Q -eq 0 ]] && zamitni "docker compose config bez --quiet/--no-interpolate by vypsal hesla z .env. Použij docker compose config --quiet."
      ;;
    run)
      COMPOSE_RUNEXEC=1
      scan_opts run $((j + 1))
      ;;
    exec)
      COMPOSE_RUNEXEC=1
      scan_opts exec $((j + 1))
      SERVICE=${T[J]:-}
      [[ "$SERVICE" == "db" ]] && dotaz "docker compose exec … db: přístup do databázového kontejneru je brána člověka."
      for ((k = J + 1; k < ${#T[@]}; k++)); do
        if [[ "${T[k]}" == "composer" ]]; then
          SUB=""
          for ((m = k + 1; m < ${#T[@]}; m++)); do
            [[ "${T[m]}" != -* ]] && { SUB=${T[m]}; break; }
          done
          composer_gate "$SUB"
          break
        elif [[ "${T[k]}" =~ (^|/)composer(\.phar)?$ ]]; then
          dotaz "Composer spuštěný přes php/cestu (${T[k]}) – přidání/změna závislostí je brána člověka."
        fi
      done
      ;;
  esac
done <<<"$SEGMENTS"

if [[ $COMPOSE_RUNEXEC -eq 1 ]]; then
  if [[ "$CMD" =~ environ|getenv|_PASSWORD|phpinfo|\$_(SERVER|ENV)|\.env([^.]|\.[^e]|$) || "$CMD" =~ php[[:space:]]+([^\;\&\|]*[[:space:]])?-[A-Za-z]*i([[:space:]]|$) ]]; then
    dotaz "Příkaz v kontejneru čte prostředí/hesla/.env (environ, getenv, _PASSWORD, php -i, \$_SERVER, .env) – může prozradit tajemství."
  fi
fi

[[ -n "$ASK_REASON" ]] && zeptej_se "$ASK_REASON"
exit 0
