# Yopass Bundle - Development
.PHONY: help up down build shell install test test-coverage coverage-php-percent cs-check cs-fix qa clean assets assets-build assets-watch assets-test test-ts ensure-up rector rector-dry phpstan release-check release-check-demos composer-sync update validate validate-translations scaffold-s3-examples setup-hooks check-no-cursor-coauthor strip-cursor-coauthor-from-history

COMPOSE_FILE ?= docker-compose.yml
COMPOSE     ?= /usr/bin/docker compose -f $(COMPOSE_FILE)
SERVICE_PHP ?= php

help:
	@echo "Yopass Bundle - Development Commands"
	@echo ""
	@echo "  up              Start Docker container"
	@echo "  down            Stop Docker container"
	@echo "  build           Rebuild Docker image (no cache)"
	@echo "  shell           Open shell in container"
	@echo "  install         Install Composer + pnpm dependencies"
	@echo "  assets          Build TypeScript (pnpm install + pnpm run build)"
	@echo "  assets-build    Alias for assets"
	@echo "  assets-watch    Watch and rebuild TS on change"
	@echo "  test-ts         Run TypeScript (Vitest) unit tests"
	@echo "  assets-test     Alias of test-ts"
	@echo "  test            Run PHPUnit tests"
	@echo "  test-coverage   Run tests with code coverage"
	@echo "  cs-check / cs-fix  Code style"
	@echo "  rector / rector-dry  Rector"
	@echo "  phpstan         Static analysis"
	@echo "  qa              cs-check + test"
	@echo "  release-check   Pre-release checks"
	@echo "  setup-hooks     Install git hooks (REQ-GIT-001; run once per clone)"
	@echo "  composer-sync   Validate and align composer.lock"
	@echo "  clean           Remove vendor and cache"
	@echo "  update / validate  Composer"
	@echo "  scaffold-s3-examples  Generate gitignored AWS S3 demo services"
	@echo ""
	@echo "Demos: make -C demo up-symfony8"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@sleep 3
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install
	@$(MAKE) setup-hooks
	@echo "Container ready."

down:
	$(COMPOSE) down

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		$(COMPOSE) up -d; sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
		$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install; \
	fi

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install

assets: ensure-up
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install
	$(COMPOSE) exec -T $(SERVICE_PHP) pnpm run build
	@echo "Assets built (src/Resources/public/Yopass.js)."

assets-build: assets

assets-watch: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) pnpm run watch

# TypeScript/Vitest tests (bundle has TS)
test-ts: ensure-up
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install --no-frozen-lockfile 2>/dev/null || true
	$(COMPOSE) exec -T $(SERVICE_PHP) pnpm run test:coverage | tee coverage-ts.txt
	sh .scripts/ts-coverage-percent.sh coverage-ts.txt
assets-test: test-ts

test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	sh .scripts/php-coverage-percent.sh coverage-php.txt

test-coverage-100: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage-100 | tee coverage-php.txt
	sh .scripts/php-coverage-percent.sh coverage-php.txt

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c 'for f in src/Resources/translations/*.yaml; do php -r "require \"vendor/autoload.php\"; Symfony\\Component\\Yaml\\Yaml::parseFile(\$$argv[1]);" "$$f" || exit 1; done'

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-install

release-check: check-no-cursor-coauthor ensure-up composer-sync cs-fix cs-check rector-dry phpstan validate-translations test-coverage-100 release-check-demos test-ts

release-check-demos:
	@$(MAKE) -C demo release-check

clean:
	rm -rf vendor node_modules .phpunit.cache coverage .php-cs-fixer.cache

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

scaffold-s3-examples:
	sh .scripts/scaffold-s3-examples.sh


# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

# REQ-MAKE-006 / REQ-GIT-001 — install versioned hooks into .git/hooks (not automatic from .githooks/)
setup-hooks:
	@mkdir -p .git/hooks
	@if [ -f .githooks/commit-msg ]; then \
		cp -f .githooks/commit-msg .git/hooks/commit-msg; \
		chmod +x .git/hooks/commit-msg; \
		echo "commit-msg hook installed (.git/hooks/commit-msg)"; \
	else \
		echo "ERROR: .githooks/commit-msg missing" >&2; \
		exit 1; \
	fi

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
