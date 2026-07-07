# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.1...HEAD
[1.2.1]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/nowo-tech/YopassBundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/YopassBundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/nowo-tech/YopassBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.0.0
