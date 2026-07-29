# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.2] - 2026-07-29

### Added

- **REQ-SEC-002** — 12.4.1 release checklist row for REQ-SEC-004 Pass (conditional).
- **REQ-DOCS-018** — GitHub About homepage Packagist + topic `frankenphp`; composer `homepage` / keywords (`php`, `frankenphp`).

### Changed

- **REQ-CS-006** — removed the PHPStan baseline, set `ignoreErrors: []`, and fixed the remaining level-8 findings in controllers, DI, Doctrine metadata remapping, form typing, and the Mongo repository count path.
- **Coverage gate** — `make coverage-check` is now wired into `release-check` and enforces a 99% PHP statements threshold through `scripts/check-coverage.php`; README coverage wording/badge were aligned. The optional MongoDB ODM repository is excluded from the PHPUnit source gate because final ODM query internals are impractical to isolate with the current unit-only test setup.
- **Tests** — expanded unit coverage for Doctrine ORM repositories, access-log repositories, route loading, compiler passes, events, form pre-submit handling, retention purging, metadata listeners, and share retrieve/extend flows.

## [1.3.1] - 2026-07-27

### Added

- **REQ-DI-001** — `Psr\Clock\ClockInterface` (via `symfony/clock`) on share retrieve/extend/purge services and Doctrine share repositories; optional constructor arg defaults to `Symfony\Component\Clock\Clock` for manual wiring in tests.
- **REQ-REL-003 / REQ-MAKE-002** — `make check-open-prs` (`.scripts/check-open-prs.sh`) wired into `release-check`.
- **REQ-SF-005** — `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in `phpunit.xml.dist`.
- **REQ-DOCS-018** — GitHub About: plain-text description, website, and topics (`php`, `symfony`, `symfony-bundle`, …).

### Changed

- **REQ-SEC-004** — security docs reflect public rate limits and observability posture for re-audit (see [SECURITY.md](SECURITY.md)).

## [1.3.0] - 2026-07-27

### Added

- **`web_ui` configuration (REQ-UI-001)** — `layout_template`, `css_framework`, `icon_set`, Twig globals via `YopassTwigExtension` (`nowo_yopass_layout_template`, `nowo_yopass_css_framework`, `nowo_yopass_icon_set`), `_ui_macros.html.twig`, and `layout_integrate_host.html.twig` bridge example.
- **`security.allow_unauthenticated` (REQ-UI-002)** — compile-time SecurityBundle guard for the manage UI (`ManageWebUiSecurityPass`).
- **REQ-CS-005** — `nowo-tech/phpstan-frankenphp` (require-dev) with classic + worker rulesets; FrankenPHP Friendly banner in README (REQ-DOCS-017).
- **REQ-MAKE-007** — `make down-dev` (non-destructive alias of `down`).
- **REQ-MAKE-008** — demo `update-deps` / `update-deps-all` targets aligned with shared monorepo scripts.
- **REQ-TEST-005** — `make test-with-db` / `test-coverage-with-db` aliases.
- **REQ-TEST-011** — `make -C demo demo-smoke` alias for the demo HTTP 200 healthcheck.
- **REQ-OBS-001** — structured rate-limit warnings via `LoggerInterface` (hashed client IP; never secrets/ciphertext).
- **REQ-SF-004** — `#[AsController]` / `#[Route]` on manage and public controllers; `YopassRouteLoader` imports those attributes and applies configurable path/name overrides (compatible with Symfony Routing 7.4 getters and 8.1 public properties; FrankenPHP-worker safe).
- **Stimulus `nowo-yopass-confirm`** — confirm destructive manage actions without inline `onclick` (REQ-UX-001).
- **Demo FrankenPHP boot** — entrypoint waits for Composer `vendor/` before starting worker mode so `make up` can install deps after `compose up`.

### Changed

- **Security defaults (REQ-UI-002)** — `security.access_roles`, `create_roles`, `list_roles`, and `revoke_roles` default to `[ROLE_ADMIN]` instead of `[ROLE_USER]`. Override in config and align `security.access_control` in your app. See [UPGRADING.md](UPGRADING.md).
- **Manage Twig layout** — pages extend `nowo_yopass_layout_template`; `stylesheets` / `javascripts` blocks with `{{ parent() }}` for host asset stacking; `templates.layout` is a BC alias for `web_ui.layout_template`.
- **REQ-UI-001** — manage/public templates route buttons and action icons through `_ui_macros.html.twig` (`btn`, `btn_icon`, `action_icon`).
- **REQ-DEMO-010** — Symfony 8 demo FrankenPHP image bumped to PHP **8.5**.
- **REQ-UX-001** — Stimulus create controller decodes HTML entities without `innerHTML`.
- **REQ-TEST-010** — PHPUnit `#[DataProvider]` replaces docblock `@dataProvider`.

## [1.2.8] - 2026-07-22

### Changed

- **Code style** — apply PHP-CS-Fixer `fully_qualified_strict_types` (`import_symbols`) across bundle sources and tests.
- **Dependencies** — `doctrine/dbal` 4.4.4, `doctrine/doctrine-bundle` 2.18.4 (`composer.lock`); demo lock bumps (`doctrine-bundle` 3.3.0, `nowo-tech/twig-inspector-bundle` 1.0.37).

## [1.2.7] - 2026-07-22

### Added

- **Demo `FRANKENPHP_MODE`** — switch FrankenPHP `classic` vs `worker` via `.env` / Compose (default `worker`); documented in [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md).
- **`docs/GITHUB_CI.md`** — expanded REQ-GIT-001 operator manual (adoption checklist, pitfalls, multi-bundle tools, acceptance criteria).

### Changed

- **REQ-GIT-001 scripts** — `check-no-cursor-coauthor` uses `--no-replace-objects`; strip script requires a clean working tree before rewrite.
- **CI** — `actions/setup-node` 6 → 7.
- **Frontend** — Vite 8.1.4 → 8.1.5.
- **PHP-CS-Fixer** — `fully_qualified_strict_types` with `import_symbols: true`.

### Fixed

- **Demo** — `reference.php` / path lock sync for local path repository.

## [1.2.6] - 2026-07-16

### Added

- **`docs/GITHUB_CI.md`** — GitHub Actions CI requirements for REQ-GIT-001 (verify, rewrite history, job example).
- **`make strip-cursor-coauthor-from-history`** — local history rewrite via `.scripts/strip-cursor-coauthor-from-history.sh` when trailers were already pushed.

### Changed

- **`check-no-cursor-coauthor`** — clearer errors (bundle-local `.git` guard, offending commit list).
- **CONTRIBUTING / README** — link to GITHUB_CI and history-rewrite guidance.
- **Dev dependencies** — `friendsofphp/php-cs-fixer` 3.95.15 (`composer.lock` only).

### Fixed

- **Demo** — `demo/symfony8/config/reference.php` sync (strict types / CS Fixer).

## [1.2.5] - 2026-07-15

### Added

- **REQ-GIT-001 hardening** — `make setup-hooks` (installs `commit-msg` hook; runs on `make up`); CI job `git-hygiene` on every push/PR; post-commit audit documented in CONTRIBUTING and RELEASE.

### Changed

- **Git hooks / audit** — `commit-msg` and `check-no-cursor-coauthor` also strip/detect same-line Cursor co-author trailers.
- **Dev dependencies** — `friendsofphp/php-cs-fixer` 3.95.14, `rector/rector` 2.5.7 (`composer.lock` only).

## [1.2.4] - 2026-07-15

### Added

- **Code of Conduct** — Contributor Covenant (`CODE_OF_CONDUCT.md`); linked from README and CONTRIBUTING.
- **Git hygiene** — `commit-msg` hook and `check-no-cursor-coauthor` script to block Cursor agent `Co-authored-by` trailers; integrated into `make release-check`. Cursor rule `.cursor/rules/01-git-commits.mdc`.

### Changed

- **Frontend dev dependencies** — TypeScript 7.0.2, Vite 8.1.4 (`package.json` / lockfile).
- **Code style** — PHP CS Fixer pass on bundle sources.

## [1.2.3] - 2026-07-13

### Changed

- **CI** — bump `actions/setup-node` from 4 to 6.
- **Dev dependencies** — `doctrine/mongodb-odm` 2.16.3, `friendsofphp/php-cs-fixer` 3.95.13, `rector/rector` 2.5.6 (`composer.lock` only; no runtime bundle changes).

### Fixed

- **`.gitignore`** — ignore machine-specific `.cursor/sandbox.json`.

## [1.2.2] - 2026-07-08

### Added

- **GitHub Spec Kit** — baseline under `specs/001-baseline/` (100% `src/` inventory), `.specify/` scaffolding, Cursor Agent skills (`.cursor/skills/speckit-*`), and operator manual [`docs/SPEC-KIT.md`](SPEC-KIT.md).
- **Spec-driven development** — expanded [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md) with contributor workflow and Spec Kit cross-links.

### Changed

- **README** — link to Spec Kit documentation.

## [1.2.1] - 2026-07-07

### Fixed

- **`PublicEndpointRateLimiter`** — exclude from autowire scan so Symfony apps build the container correctly (explicit wiring via `YopassExtension`).
- **Demo local repository** — `LocalOffloadingShareRepository` implements `consumeReadIfAvailable()` (delegate + hydrate file references).
- **S3 scaffold** — generated `S3OffloadingShareRepository` includes `consumeReadIfAvailable()`.

## [1.2.0] - 2026-07-05

### Added

- **Public rate limiting** — `PublicEndpointRateLimiter` on anonymous `/share/*` show and consume (config: `public_rate_limit`; requires `cache.app`).
- **Atomic consume** — `ShareRepositoryInterface::consumeReadIfAvailable()` prevents concurrent read over-consumption (ORM DQL update + MongoDB find-and-update).
- **Translations** — German, French, Italian, Dutch, and Portuguese (`NowoYopassBundle` domain).
- **Tests** — `PublicEndpointRateLimiterTest`, `ShareRetrieverTest`, `ShareConsumeFlowIntegrationTest`; fixed `PublicShareControllerTest` for rate limiter.
- **CI** — PHPStan, Vitest, and `composer audit` jobs.
- **Flex recipe `1.1.0`** — full route set and security post-install notes.

### Fixed

- **`ShareExtendException`** — expose `errorCode` property used by the extend JSON endpoint.
- **SECURITY.md** — document `?decrypt_key=` query-string risks vs short links and URL fragments; public rate limiting and atomic consume.
- **CONTRIBUTING.md** — align quality-check commands with Makefile/composer scripts.
- **Reveal template** — `crossorigin` and `referrerpolicy` on Tabler CDN stylesheet.

### Changed

- **Code style** — Rector constructor promotion across bundle sources; PHPStan baseline for remaining level-8 findings.

## [1.1.0] - 2026-07-04

### Added

- **Share events** — `ShareListQueryEvent`, `ShareListResultEvent`, and `ShareAccessCheckEvent` to customize manage list queries and per-share access without built-in teams/ACL.
- **`ShareLister`** and **`ShareAccessGuard`** services wired into `ShareManageController` (creator remains the default list subject and access grant).
- **`ShareAccessAction`** enum (`View`, `Preview`, `Extend`, `Revoke`, `Delete`) for per-route access checks.
- **Examples** — `examples/access-control/` (teams, individual grants, role-based access) and [docs/examples/AccessControl.md](examples/AccessControl.md).

### Fixed

- **`composer.lock`** — sync content-hash with `composer.json` (`composer validate --strict` in CI).
- **Local dev** — `COMPOSER_IGNORE_PLATFORM_REQ=ext-mongodb` in `docker-compose.yml` (MongoDB is require-dev only).

## [1.0.1] - 2026-07-04

### Fixed

- **CI** — Symfony 8 matrix jobs use `doctrine/doctrine-bundle ^3.0` (2.x does not support Symfony 8).
- **CI** — ignore optional `ext-mongodb` platform requirement for dev dependencies in GitHub Actions.

### Changed

- **`composer.json`** — declare `symfony/form` and `symfony/validator` for Symfony 8 (`^7.4 || ^8.0`).
- **GitHub Actions** — bump `actions/checkout`, `actions/cache`, and `codecov-action` (Dependabot).

## [1.0.0] - 2026-07-04

First stable release of **Yopass Bundle**.

### Added

- **E2E encrypted sharing** — browser-side libsodium secretbox; server stores ciphertext only.
- **Manage UI** — authenticated list, create (Symfony form POST + redirect), preview, extend, revoke, delete, and delete-all.
- **Public routes** — anonymous reveal page and consume endpoint (JSON ciphertext).
- **Link formats**
  - Short link: `/share/{id}` (recipient pastes decryption key in the UI).
  - One-click link: `/share/{id}?decrypt_key=…` (auto-reveal on load).
  - Legacy support when reading: `#fragment`, `?key=`, and `?password=`.
- **Share options** — configurable expiration ids, max-read limits, and list pagination (`shares.list_page_size`).
- **Retention** — automatic purge of shares older than `shares.retention.max_age` (UI + `nowo:yopass:purge-old-shares` command).
- **Access log** — optional audit trail of public link opens (Doctrine ORM).
- **File shares** — optional `ShareFileHandlerInterface` (text-only when not configured).
- **Encryption modes** — auto-generated key or custom password (`sharing` config).
- **Configuration** — `user_class`, `table_prefix`, routes, templates, firewall hints, and pluggable `YopassAccessCheckerInterface`.
- **Persistence** — Doctrine ORM (PostgreSQL, MySQL, MariaDB, SQLite, SQL Server, Oracle), MongoDB ODM, or custom repository.
- **TypeScript / Stimulus** — Vite + pnpm bundle (`yopass.js`, asset package `nowo_yopass`).
- **Translations** — `NowoYopassBundle` domain (EN/ES).
- **Demo** — Symfony 8.1 + FrankenPHP + MySQL (`demo/symfony8/`).
- **Tooling** — PHPUnit (100% PHP line coverage target), Vitest (crypto), PHP-CS-Fixer, Rector, PHPStan, GitHub Actions CI, Symfony Flex recipe.

### Requirements

- PHP >= 8.2, < 8.6 with `ext-sodium`
- Symfony ^7.4 || ^8.0
- Doctrine ORM ^2.15 || ^3.0 (or MongoDB ODM / custom repository)

[Unreleased]: https://github.com/nowo-tech/YopassBundle/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.8...v1.3.0
[1.2.8]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.7...v1.2.8
[1.2.7]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.6...v1.2.7
[1.2.6]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.5...v1.2.6
[1.2.5]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.4...v1.2.5
[1.2.4]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.3...v1.2.4
[1.2.3]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/nowo-tech/YopassBundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/YopassBundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/nowo-tech/YopassBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.0.0
