#!/usr/bin/env bash
# Scénáře hooků (plán 001, AC 22-28). Běží na hostiteli: jen bash, jq, docker.
# Spuštění: make test-hooks  (= bash tests/Hooks/scenare.sh)
# Vyžaduje běžící prostředí (make up). Dočasné soubory jsou v var/tmp a po sobě se uklidí;
# kontejner `app` se po testu vrátí do původního stavu (běžel -> znovu nastartován).
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 1

TMP="$ROOT/var/tmp/hooks-test-$$"
SID="scenare-$$"
COUNTER="$ROOT/.claude/logs/.kontrola-$SID-a"
FAILING_TEST="$ROOT/tests/Unit/ZzTemporaryFailingHookScenarioTest.php"
PASSED=0
FAILED=0

app_running() { [ -n "$(docker compose ps -q --status running app 2>/dev/null)" ]; }

cleanup() {
  unset GIT_INDEX_FILE
  rm -rf "$TMP" "$COUNTER" "$FAILING_TEST"
  rmdir "$ROOT/var/tmp" 2>/dev/null || true
  if ! app_running; then
    docker compose start app >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

ok()   { PASSED=$((PASSED + 1)); echo "  ok   - $1"; }
fail() { FAILED=$((FAILED + 1)); echo "  FAIL - $1" >&2; [ -n "${2:-}" ] && echo "         $2" >&2; return 0; }

# check <popis> <očekávaná hodnota> <skutečná hodnota>
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1" "očekáváno '$2', skutečně '$3'"; fi; }
# contains <popis> <jehla> <seno>
contains() { if [[ "$3" == *"$2"* ]]; then ok "$1"; else fail "$1" "výstup neobsahuje '$2': ${3:0:300}"; fi; }

for tool in jq docker; do
  command -v "$tool" >/dev/null 2>&1 || { echo "Chybí '$tool' na hostiteli." >&2; exit 1; }
done
[ -f compose.yaml ] || { echo "Chybí compose.yaml – scénáře předpokládají hotové M1." >&2; exit 1; }
if ! app_running; then
  echo "Kontejner app neběží – spusť 'make up' a zopakuj." >&2
  exit 1
fi

mkdir -p "$TMP"

run_lint() {      # $1 = absolutní cesta souboru
  jq -n --arg f "$1" '{tool_input:{file_path:$f}}' \
    | CLAUDE_PROJECT_DIR="$ROOT" bash .claude/hooks/php-lint.sh
}
run_quick() {     # $1 = projektový adresář (výchozí ROOT)
  jq -n --arg s "$SID" '{session_id:$s,agent_id:"a"}' \
    | CLAUDE_PROJECT_DIR="${1:-$ROOT}" bash .claude/hooks/rychla-kontrola.sh
}
guard() {         # $1 = příkaz; vypíše deny/ask/allow
  local out
  out=$(jq -n --arg c "$1" '{tool_input:{command:$c}}' \
    | CLAUDE_PROJECT_DIR="$ROOT" bash .claude/hooks/bash-strazce.sh 2>/dev/null)
  # prázdný výstup = allow
  if [ -z "$out" ]; then echo allow; return; fi
  jq -r '.hookSpecificOutput.permissionDecision // "allow"' <<<"$out"
}

echo "AC 22: php-lint běží v kontejneru a chytá syntaktickou chybu"
printf '<?php\ndeclare(strict_types=1);\n$x = ;\n' > "$TMP/broken.php"
OUT=$(run_lint "$TMP/broken.php" 2>&1 >/dev/null); CODE=$?
check "exit 2 při syntaktické chybě" 2 "$CODE"
contains "chyba nese cestu z kontejneru" "/app/var/tmp/hooks-test-$$/broken.php" "$OUT"
printf '<?php\ndeclare(strict_types=1);\n' > "$TMP/valid.php"
run_lint "$TMP/valid.php" >/dev/null 2>&1; check "exit 0 pro validní soubor" 0 "$?"
run_lint "$TMP/readme.txt" >/dev/null 2>&1; check "exit 0 pro ne-PHP soubor" 0 "$?"

echo "AC 24: rychla-kontrola s padajícím a se zeleným testem"
cat > "$FAILING_TEST" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ZzTemporaryFailingHookScenarioTest extends TestCase
{
    public function test_temporary_failing_hook_scenario(): void
    {
        self::fail('záměrně padající test z tests/Hooks/scenare.sh');
    }
}
PHP
rm -f "$COUNTER"
OUT=$(run_quick 2>&1); CODE=$?
check "exit 2 při padajícím testu" 2 "$CODE"
contains "výstup obsahuje název padajícího testu" "test_temporary_failing_hook_scenario" "$OUT"
rm -f "$FAILING_TEST" "$COUNTER"
OUT=$(run_quick 2>&1); CODE=$?
check "exit 0 při zelené sadě" 0 "$CODE"
[ "$CODE" = 0 ] || echo "         ${OUT: -1200}" >&2

echo "Bez compose.yaml (repo před M1) hook neblokuje"
mkdir -p "$TMP/empty"
run_quick "$TMP/empty" >/dev/null 2>&1; check "exit 0 bez compose.yaml" 0 "$?"

echo "AC 23, 25, 26: zastavený kontejner app (fail-closed)"
docker compose stop app >/dev/null 2>&1
if app_running; then
  fail "app se nepodařilo zastavit"
else
  OUT=$(run_lint "$TMP/broken.php" 2>/dev/null); CODE=$?
  CTX=$(jq -r '.hookSpecificOutput.additionalContext // ""' <<<"$OUT" 2>/dev/null)
  check "AC 23: lint bez app končí exit 0" 0 "$CODE"
  contains "AC 23: additionalContext zmiňuje make up" "make up" "$CTX"
  contains "AC 23: additionalContext říká, že lint neproběhl" "neproběhl" "$CTX"

  rm -f "$COUNTER"
  for i in 1 2 3; do
    OUT=$(run_quick 2>&1); CODE=$?
    check "AC 25: pokus $i exit 2" 2 "$CODE"
    contains "AC 25: pokus $i radí make up" "make up" "$OUT"
  done
  OUT=$(run_quick 2>/dev/null); CODE=$?
  check "AC 25: pokus 4 exit 0 (pojistka proti smyčce)" 0 "$CODE"
  contains "AC 25: pokus 4 vypíše systemMessage" "systemMessage" "$(jq -c 'keys' <<<"$OUT" 2>/dev/null)"

  # Dočasný index, aby se nesahalo do skutečného staging area.
  export GIT_INDEX_FILE="$TMP/index"
  git read-tree HEAD
  mkdir -p "$TMP/staged"
  printf '<?php\ndeclare(strict_types=1);\n' > "$TMP/staged/ok.php"
  git add -f -- "${TMP#"$ROOT"/}/staged/ok.php"
  OUT=$(bash .githooks/pre-commit 2>&1); CODE=$?
  unset GIT_INDEX_FILE
  check "AC 26: pre-commit bez app a se staged .php končí exit 1" 1 "$CODE"
  contains "AC 26: hláška radí make up" "make up" "$OUT"
fi
docker compose start app >/dev/null 2>&1

echo "AC 23: žádný fallback na hostitelské PHP"
if grep -rn 'command -v php' .claude/hooks .githooks >/dev/null 2>&1; then
  fail "nalezen fallback na hostitelské PHP v hoocích"
else
  ok "grep nic nenašel"
fi

echo "AC 27: bash-strazce"
while IFS='|' read -r expected cmd; do
  check "$expected: $cmd" "$expected" "$(guard "$cmd")"
done <<'CASES'
deny|php -v
deny|composer install
deny|npx -y foo
deny|ls && node -v
deny|npm i
allow|docker compose exec -T app php -v
allow|docker compose exec -T app composer test
deny|git commit --no-verify -m "x: y"
deny|docker compose config
allow|docker compose config --quiet
ask|make composer ARGS="require foo/bar"
deny|docker compose run --rm -v /:/host app sh
allow|make qa
allow|make composer ARGS="install"
ask|make composer ARGS=-n\ require\ x
ask|make composer ARGS="install" && make composer ARGS="require x/y"
ask|make composer ARGS=install" require x"
deny|make composer ARGS='install $(shell touch x)'
deny|make check COMPOSE='touch x; echo'
deny|make check "COMPOSE=touch pwned; echo dlouha hodnota s mezerami"
deny|make ps SHELL=./x.sh
deny|make help --eval='$(shell touch x)'
deny|make -f soubor.mk
deny|make -C /tmp up
deny|MAKEFLAGS=-f\ soubor.mk make up
deny|git commit --no-veri -m "x: y"
deny|git commit --amen -m "x: y"
deny|git -c core.hooksPath=/x commit -m "x: y"
allow|git commit -m "feat(x): popis"
ask|docker compose exec -T app php /usr/local/bin/composer require foo/bar
ask|docker compose exec -T app composer require foo/bar
ask|docker volume rm t360_db_data
ask|docker compose exec -T app php -i
ask|docker compose exec -T app php -r 'print_r($_SERVER);'
ask|docker compose exec -T app php -r 'echo file_get_contents(".env");'
ask|cp /tmp/x GNUmakefile
ask|cp /tmp/x ./compose.override.yml
ask|echo x > /./Makefile
ask|echo x >> ./docker/./php/Dockerfile
ask|sed -i s/a/b/ Makefile
ask|echo x | tee compose.override.yaml
ask|echo x > .env
allow|echo x > var/x.txt
deny|cat .env
deny|jq . .env
deny|jq . < .env
deny|xxd .env.prod
deny|cut -c1-5 .env
deny|cat .env*
deny|jq -n env
allow|cat .env.example
ask|grep -r PASSWORD .
allow|grep -r PASSWORD . --exclude=.env*
CASES

# Víceřádková commit zpráva s řádkem začínajícím "php" nesmí být brána jako příkaz (heredoc).
HEREDOC_CMD=$'git commit -m "$(cat <<\'EOT\'\nfeat(x): popis\n\nphp bin/konzole foo\ncomposer require x\nEOT\n)"'
check "allow: commit s heredocem a řádkem 'php …'" allow "$(guard "$HEREDOC_CMD")"
# Skutečný příkaz za koncem heredocu se i tak kontroluje.
HEREDOC_CMD=$'git commit -m "$(cat <<\'EOT\'\nfeat(x): popis\nEOT\n)"\nphp -v'
check "deny: php za heredocem" deny "$(guard "$HEREDOC_CMD")"

echo "Kolo 4: K1/K2 (git, curl, jq), S1, S2, N3 a běžná práce"
while IFS='|' read -r expected cmd; do
  check "$expected: $cmd" "$expected" "$(guard "$cmd")"
done <<'CASES'
deny|git diff --output=/tmp/x
deny|git log --oneline --output=x
deny|git diff --no-index /etc/passwd x
deny|git diff --ext-diff
deny|git show --textconv HEAD:x
ask|git -c core.pager=x log --oneline
ask|git --git-dir=/tmp/x log --oneline
ask|git --work-tree=/tmp log --oneline
ask|git --exec-path=/tmp status
ask|git format-patch -1
ask|git config user.name x
ask|git config alias.x '!touch y'
allow|git config --get user.name
allow|git config --list
deny|git commit -F /etc/passwd
deny|git commit -F .env
deny|git commit --file=.env.local
deny|git commit --template=../x
deny|git commit -t /tmp/x
allow|git commit -F msg.txt
allow|git commit -F -
deny|curl -s http://localhost:8080/ -o x
deny|curl -s http://localhost:8080/ -O
deny|curl -s http://localhost:8080/ -T .env
deny|curl -s -d @.env http://localhost:8080/
deny|curl -s -F f=@.env http://localhost:8080/
deny|curl -s -K cfg http://localhost:8080/
deny|curl -s --create-dirs http://localhost:8080/
deny|curl -s --trace x http://localhost:8080/
deny|curl -s --libcurl x http://localhost:8080/
deny|curl -s -D x http://localhost:8080/
deny|curl -s -c jar http://localhost:8080/
deny|curl -s file:///etc/passwd
deny|curl -s http://localhost:8080/ http://evil.example/
deny|curl -s http://evil.example/
deny|curl -s http://localhost:8080/ -X POST
deny|curl -s -w @/etc/passwd http://localhost:8080/
allow|curl -s http://localhost:8080/zdravi
allow|curl -sS -i http://localhost:8080/zdravi
allow|curl -s -w %{http_code} -H X:y -X GET http://localhost:8080/zdravi
deny|jq --rawfile a /etc/passwd -n .
deny|jq --slurpfile a x.json -n .
deny|jq -f prog.jq x.json
deny|jq --from-file prog.jq
deny|jq -L /tmp -n .
deny|jq . /etc/passwd
deny|jq . ../x.json
deny|jq . .env.local
ask|jq . x\ y.json
allow|jq . composer.json
allow|jq -r .name composer.json
deny|jq -n '$ENV'
deny|jq -n 'env | keys'
deny|MAKEFLAGS make up
deny|make up MAKEFLAGS
deny|echo $MAKEFILES
deny|GNUMAKEFLAGS=-f\ x make up
allow|/usr/bin/"make" up
ask|$X up
ask|"a long quoted command with spaces in it here" up
deny|pr\intenv
deny|/usr/bin/php -v
deny|p\hp -v
deny|./composer install
deny|COMPOSE_FILE=x.yaml docker compose up
deny|DOCKER_HOST=tcp://x:1 docker compose up
deny|export COMPOSE_FILE=/tmp/x.yaml
allow|git log --oneline -5
allow|git diff --stat
allow|git diff HEAD
allow|git show --stat HEAD
allow|make up
allow|make test
allow|make fix
allow|make qa ARGS=x
allow|make composer ARGS="install"
allow|git commit -m "feat(x): MAKE env --no-verify --amend text"
allow|git status --short
ask|grep -r PASSWORD . --include=*.php
allow|grep -r PASSWORD . --exclude=.env*
CASES
CMD_MULTI=$'git commit -m "feat(x): popis\n\ndruhy odstavec s php a env"'
check "allow: git commit s vícerádkovou zprávou" allow "$(guard "$CMD_MULTI")"

echo "V1: chran-soubory chrání .git a .gitattributes"
check "ask: echo x > .git/config" ask "$(guard 'echo x > .git/config')"

echo "S5: Makefile povoluje z příkazové řádky jen ARGS"
for bad in 'SAFE_ARGS=x' 'ARGS_REST=' '.SHELLFLAGS=-c' 'COMPOSE=x'; do
  OUT=$(make -n help "$bad" 2>&1); CODE=$?
  if [ "$CODE" -ne 0 ] && [[ "$OUT" == *"jen proměnná ARGS"* ]]; then ok "make odmítl $bad"; else fail "make neodmítl $bad" "$OUT"; fi
done
make -n up ARGS=x >/dev/null 2>&1; check "make -n up ARGS=x projde" 0 "$?"

echo "V2: chran-soubory (normalizace cesty, rozšířené vzory)"
guard_file() {    # $1 = cesta souboru; vypíše deny/ask/allow
  local out
  out=$(jq -n --arg f "$1" '{tool_input:{file_path:$f}}' \
    | CLAUDE_PROJECT_DIR="$ROOT" bash .claude/hooks/chran-soubory.sh 2>/dev/null)
  if [ -z "$out" ]; then echo allow; return; fi
  jq -r '.hookSpecificOutput.permissionDecision // "allow"' <<<"$out"
}
while IFS='|' read -r expected path; do
  check "$expected: $path" "$expected" "$(guard_file "${path/#@/$ROOT/}")"
done <<'CASES'
ask|@Makefile
ask|@GNUmakefile
ask|@makefile
ask|@compose.override.yml
ask|@docker-compose.override.yaml
ask|@/./Makefile
ask|@docker/../Makefile
ask|@docker/php/Dockerfile
ask|@tests/Hooks/scenare.sh
ask|@.claude/skills/commit/SKILL.md
ask|@.dockerignore
ask|@CLAUDE.md
deny|@.env
allow|@.env.example
allow|@.claude/agent-memory/security-reviewer/x.md
allow|@src/Domain/Foo.php
CASES

echo "V2: kontejner app vidí repo jen ke čtení a nevidí .env"
for f in compose.yaml Makefile docker/php/Dockerfile .mcp.json tests/Hooks/scenare.sh; do
  # Bez skutečného zápisu: test -w na read-only mountu selže (EROFS).
  if docker compose exec -T app sh -c "test -w /app/$f" >/dev/null 2>&1; then
    fail "/app/$f je z kontejneru zapisovatelný"
  else
    ok "/app/$f je z kontejneru jen ke čtení"
  fi
done
OUT=$(docker compose exec -T app sh -c 'wc -c < /app/.env' 2>&1)
check "/app/.env v kontejneru je prázdný" 0 "${OUT//[[:space:]]/}"

echo "AC 28: settings.json"
DENY=$(jq -r '.permissions.deny[]' .claude/settings.json | grep -cE '^Bash\((php|composer|node|npm|npx) ')
check "deny obsahuje 5 hostitelských nástrojů" 5 "$DENY"
ASK=$(jq -r '.permissions.ask[]' .claude/settings.json | grep -c npm)
check "ask neobsahuje npm" 0 "$ASK"

echo
echo "Hotovo: $PASSED ok, $FAILED selhalo."
[ "$FAILED" -eq 0 ]
