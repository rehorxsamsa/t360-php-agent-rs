---
name: devops-kontrakt
description: Závazný kontrakt pro Docker, CI a nasazení na VPS Debian 13 — názvy služeb, obrazů, GitHub secrets, cesty na serveru, porty, healthcheck a rollback. Kapitola Nasazení v tutoriálu na něj odkazuje, proto se od něj neodchyluj bez ADR.
---

# Nasazovací kontrakt (neměnit bez ADR a schválení člověkem)

## Služby (compose)
| Služba | Dev (`compose.yaml`) | Prod (`compose.prod.yaml`) |
|---|---|---|
| `app` | PHP 8.4-FPM, target `dev`, repo mount do `/app`, Xdebug vyp. | obraz `ghcr.io/<owner>/redakcni-system-app:${IMAGE_TAG}`, `read_only`, tmpfs `/tmp` |
| `web` | nginx 1.x, `8080:80` | obraz `ghcr.io/<owner>/redakcni-system-web:${IMAGE_TAG}`, port `127.0.0.1:${WEB_PORT:-8085}:80` |
| `db` | `mariadb:11.8`, `127.0.0.1:3307:3306` (jen pro MCP čtení) | `mariadb:11.8`, **bez publikovaného portu**, volume `db_data` |
| `ollama` | profil `ai-local` | — (nepoužívá se) |
| `caddy` | — | profil `caddy` (varianta A: čistý VPS, TLS na 80/443, `DOMENA` z env) |

- Healthcheck `web`: `GET /zdravi` → `200 {"stav":"ok","db":"ok"}`.
- Všechny kontejnery: ne-root, `security_opt: [no-new-privileges:true]`, `cap_drop: [ALL]` (+ jen nutné `cap_add`).
- Prod tajemství: soubor `/opt/redakce/.env` na serveru (`env_file`), nikdy v obrazu ani v GitHubu.
- `compose.prod.yaml` má `name: redakce` (síť pak je `redakce_default`) a obrazy
  `ghcr.io/${IMAGE_OWNER}/redakcni-system-app:${IMAGE_TAG}` / `…-web:${IMAGE_TAG}`.
- `.env.example` má sekci `# PRODUKCE` s proměnnými: `APP_ENV APP_DEBUG APP_URL IMAGE_OWNER
  MARIADB_ROOT_PASSWORD DB_PASSWORD DB_MIGRACE_PASSWORD DB_READONLY_PASSWORD AI_PROVIDER
  ANTHROPIC_API_KEY AI_MODEL AI_MODEL_LEVNY AI_DENNI_LIMIT_TOKENU WEB_PORT TRUSTED_PROXIES
  COMPOSE_PROFILES DOMENA`. Aplikace věří `X-Forwarded-*` jen z adres v `TRUSTED_PROXIES`.

## Obrazy
- Build v CI z `docker/php/Dockerfile` (target `prod`) a `docker/nginx/Dockerfile` (kopíruje `public/`).
- Tagy: `sha-<7 znaků>` a `latest`. Název repa v GHCR **malými písmeny**.

## CI — `.github/workflows/ci.yml` (název workflow: `CI`)
Spouští se na `push` a `pull_request`. Joby:
1. `kvalita`: PHP 8.4 (shivammathur/setup-php), služba `mariadb:11.8`, `composer install`,
   `composer check`, `composer test` (s `AI_PROVIDER=falesny`), `composer audit`.
2. `tajemstvi`: gitleaks nad historií.
3. `obrazy` (jen `main`, `needs: [kvalita, tajemstvi]`): build + push obou obrazů do GHCR
   (`permissions: packages: write`), sken Trivy (fail na CRITICAL).

## Deploy — `.github/workflows/deploy.yml` (název: `Deploy`)
- Spouštěč: `workflow_run` z `CI` (`completed`, větev `main`, jen při `success`) + `workflow_dispatch`
  s volitelným vstupem `tag` (ruční rollback na starší `sha-…`).
- Job `nasazeni` s `environment: production` (**ruční schválení člověkem v GitHubu**).
- Secrets v environmentu `production`:
  `VPS_HOST`, `VPS_PORT`, `VPS_USER`, `VPS_SSH_KEY` (privátní ed25519), `VPS_KNOWN_HOSTS` (výstup `ssh-keyscan`).
- Kroky: zapsat klíč + known_hosts → `scp compose.prod.yaml scripts/vps/nasad.sh` do `/opt/redakce/`
  → `ssh … "cd /opt/redakce && chmod +x nasad.sh && IMAGE_TAG=sha-xxxxxxx ./nasad.sh"`.
- Privátní klíč zapisovat s `umask 077`, po jobu smazat; `StrictHostKeyChecking=yes` s `VPS_KNOWN_HOSTS`.

## Skript na serveru — `scripts/vps/nasad.sh` (píše devops, spouští workflow)
1. `set -euo pipefail`; uloží aktuální tag z `.image_tag` do `.image_tag_predchozi`.
2. `docker compose -f compose.prod.yaml pull` → `up -d --wait`.
3. Migrace: `docker compose -f compose.prod.yaml exec -T app php bin/konzole migrace:spust`.
4. Ověření: `curl -fsS http://127.0.0.1:${WEB_PORT:-8085}/zdravi` (5 pokusů).
5. Úspěch → zapíše nový tag do `.image_tag`; neúspěch → `IMAGE_TAG=<předchozí>` a `up -d`, exit 1.
6. Úklid: `docker image prune -f` (ponechat poslední 3 tagy).
