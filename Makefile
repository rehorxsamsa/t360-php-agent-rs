# Z příkazové řádky je povolena jen proměnná ARGS (revize kola 3, S5): jakákoli jiná (SAFE_ARGS=, ARGS_REST=,
# .SHELLFLAGS=, COMPOSE=…) by mohla spustit kód na hostiteli. Pozn.: `X!=cmd`/`ARGS:=…` z příkazové řádky
# Makefile neubrání (spustí se před jeho načtením); skutečnou hranicí je přesný allow `make <cíl>` a hook.
override CLI_VARS := $(foreach v,$(.VARIABLES),$(if $(filter command line,$(origin $(v))),$(v)))
ifneq ($(filter-out ARGS,$(CLI_VARS)),)
$(error Z příkazové řádky je povolena jen proměnná ARGS: $(filter-out ARGS,$(CLI_VARS)))
endif

.DEFAULT_GOAL := help

# Pojistky proti spuštění kódu na hostiteli přes proměnné z příkazové řádky / prostředí (revize M1, V1):
# `override` ignoruje COMPOSE=… i SHELL=… z příkazové řádky, ARGS se nikdy neexpanduje ani neexportuje.
override SHELL := /bin/bash
# Vše běží v Dockeru (pravidlo 0); na hostiteli jen docker, make, bash. `-f` vypíná auto-načtení override souborů.
override COMPOSE := docker compose -f compose.yaml

unexport ARGS
# $(value …) vrací text proměnné bez expanze: `$(shell …)` v ARGS se nespustí.
SAFE_ARGS := $(value ARGS)

# Povolené znaky ARGS: písmena, číslice, mezera a _ . / : @ = ^ ~ * - [ ]
ALLOWED_CHARS := a b c d e f g h i j k l m n o p q r s t u v w x y z \
                 A B C D E F G H I J K L M N O P Q R S T U V W X Y Z \
                 0 1 2 3 4 5 6 7 8 9 _ . / : @ = ^ ~ * - [ ]
strip_chars = $(if $(2),$(call strip_chars,$(subst $(firstword $(2)),,$(1)),$(wordlist 2,$(words $(2)),$(2))),$(1))
empty :=
space := $(empty) $(empty)
ARGS_REST := $(subst $(space),,$(call strip_chars,$(SAFE_ARGS),$(ALLOWED_CHARS)))

.PHONY: help up down ai-local index sh composer check test qa fix migrate seed logs ps mcp test-hooks

help: ## Seznam cílů
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-12s %s\n", $$1, $$2}'

.env:
	cp .env.example .env

up: .env ## Sestaví a spustí prostředí, nainstaluje závislosti
	mkdir -p public var vendor database bin src tests/Unit tests/Integration tests/_artefakty
	@grep -q '^COMPOSE_FILE=' .env || echo COMPOSE_FILE=compose.yaml >> .env
	$(COMPOSE) up -d --build --wait db app
	$(COMPOSE) exec -T app composer install --no-interaction
	$(COMPOSE) up -d --build --wait

down: ## Zastaví kontejnery (data v DB zůstávají)
	$(COMPOSE) --profile ai-local down

ai-local: ## Spustí Ollamu (profil ai-local) a stáhne model embeddinggemma (~3,8 GB obraz + 622 MB model)
	$(COMPOSE) --profile ai-local up -d --wait ollama
	$(COMPOSE) --profile ai-local exec -T ollama ollama pull embeddinggemma

index: ## Zaindexuje publikované články pro sémantické vyhledávání (ai:indexuj)
	$(COMPOSE) exec -T app php bin/konzole ai:indexuj

sh: ## Shell v kontejneru app
	$(COMPOSE) exec app bash

composer: ## Composer v kontejneru: make composer ARGS="install"
	$(if $(ARGS_REST),$(error ARGS obsahuje nepovolené znaky))
	@set -f; $(COMPOSE) exec -T app composer $(SAFE_ARGS)

check: ## Rychlá kontrola (lint, php-cs-fixer, phpstan)
	$(COMPOSE) exec -T app composer check

test: ## PHPUnit (Unit + Integration)
	$(COMPOSE) exec -T app composer test

qa: ## Kompletní brána (check + test + composer audit)
	$(COMPOSE) exec -T app composer qa

fix: ## Automatická oprava stylu (php-cs-fixer)
	$(COMPOSE) exec -T app composer cs:fix

migrate: ## Spustí čekající migrace databáze
	$(COMPOSE) exec -T app php bin/konzole migrace:spust

seed: ## Nahraje ukázková data (jen APP_ENV=dev|test)
	$(COMPOSE) exec -T app php bin/konzole db:seed

logs: ## Logy všech služeb
	$(COMPOSE) logs --tail=100 -f

ps: ## Stav služeb
	$(COMPOSE) ps

mcp: ## Předstáhne/sestaví obrazy MCP serverů (profil mcp)
	mkdir -p tests/_artefakty
	$(COMPOSE) --profile mcp build
	$(COMPOSE) --profile mcp pull --ignore-buildable

test-hooks: ## Scénáře hooků (běží na hostiteli: bash, jq, docker)
	bash tests/Hooks/scenare.sh
