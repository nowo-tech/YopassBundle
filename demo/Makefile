# REQ-DEMO-004: demo composer.json uses a Composer path repository to /var/yopass-bundle (live mount).
.PHONY: help up-symfony8 down-symfony8 install-symfony8 database-symfony8 database-empty-symfony8 shell-symfony8 test-symfony8 release-check demo-smoke update-bundle-symfony8 update-deps-all update-deps-demos

help:
	@echo "Yopass Bundle — Demos"
	@echo ""
	@echo "Symfony 8.1 (port 8022):"
	@echo "  up-symfony8 down-symfony8 install-symfony8 database-symfony8 database-empty-symfony8 shell-symfony8 test-symfony8"
	@echo ""
	@echo "  release-check / demo-smoke  Boot demo + HTTP 200 on /tools/yopass (REQ-TEST-011)"
	@echo "  update-bundle-symfony8   Sync bundle autoload in demo"
	@echo "  update-deps-all          Composer update in demos (REQ-MAKE-008)"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

install-symfony8:
	@$(MAKE) -C symfony8 install

database-symfony8:
	@$(MAKE) -C symfony8 database

database-empty-symfony8:
	@$(MAKE) -C symfony8 database-empty

shell-symfony8:
	@$(MAKE) -C symfony8 shell

test-symfony8:
	@$(MAKE) -C symfony8 test

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

# REQ-TEST-011: boot stack + one HTTP 200.
demo-smoke: release-check

release-check:
	@$(MAKE) up-symfony8
	@$(MAKE) update-bundle-symfony8
	@curl -sf http://localhost:8022/tools/yopass >/dev/null && echo "Symfony 8 demo healthcheck OK" || (echo "Symfony 8 demo healthcheck failed" && exit 1)

# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
