SHELL := /bin/sh
COMPOSE := docker compose

.PHONY: setup config build up down migrate fixtures test analyse lint frontend-test e2e check logs

setup:
	@sh docker/setup-env.sh

config:
	@$(COMPOSE) --profile test config --quiet

build: config
	$(COMPOSE) --profile test build app nginx tests

up: config
	$(COMPOSE) up --build -d

down:
	$(COMPOSE) down

migrate: config
	$(COMPOSE) run --rm migrate

fixtures: config
	$(COMPOSE) run --rm app php bin/fixtures.php

test: config
	$(COMPOSE) --profile test run --build --rm tests composer test

analyse: config
	$(COMPOSE) --profile test run --build --rm tests composer analyse

lint: config
	$(COMPOSE) --profile test run --build --rm tests composer lint

frontend-test:
	docker build --target frontend-test -t product-import-frontend-tests:local .

e2e:
	cd tests && npm ci --no-audit --no-fund && npx playwright install chromium && node e2e.mjs

check: config test analyse lint frontend-test e2e

logs:
	$(COMPOSE) logs --tail=100 -f app worker dispatcher nginx
