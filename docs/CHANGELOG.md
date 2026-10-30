# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.10.1] - 2026-10-30](#1101---2026-10-30)
- [[1.10.0] - 2026-10-30](#1100---2026-10-30)
- [[1.9.0] - 2026-10-29](#190---2026-10-29)
- [[1.8.0] - 2026-10-29](#180---2026-10-29)
- [[1.7.0] - 2026-10-28](#170---2026-10-28)
- [[1.6.0] - 2026-10-26](#160---2026-10-26)
- [[1.5.5] - 2026-10-25](#155---2026-10-25)
- [[1.5.4] - 2026-10-24](#154---2026-10-24)
- [[1.5.3] - 2026-10-20](#153---2026-10-20)
- [[1.5.2] - 2026-10-18](#152---2026-10-18)
- [[1.5.1] - 2026-10-13](#151---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.9] - 2026-10-13](#149---2026-10-13)
- [[1.4.8] - 2026-10-09](#148---2026-10-09)
- [[1.4.7] - 2026-09-27](#147---2026-09-27)
- [[1.4.6] - 2026-09-25](#146---2026-09-25)
- [[1.4.5] - 2026-08-24](#145---2026-08-24)
- [[1.4.4] - 2026-08-20](#144---2026-08-20)
- [[1.4.3] - 2026-08-19](#143---2026-08-19)
- [[1.4.2] - 2026-08-18](#142---2026-08-18)
- [[1.4.1] - 2026-08-07](#141---2026-08-07)
- [[1.4.0] - 2026-08-04](#140---2026-08-04)
- [[1.3.2] - 2026-07-29](#132---2026-07-29)
- [[1.3.1] - 2026-07-27](#131---2026-07-27)
- [[1.3.0] - 2026-07-27](#130---2026-07-27)
- [[1.2.8] - 2026-07-22](#128---2026-07-22)
- [[1.2.7] - 2026-07-22](#127---2026-07-22)
- [[1.2.6] - 2026-07-16](#126---2026-07-16)
- [[1.2.5] - 2026-07-15](#125---2026-07-15)
- [[1.2.4] - 2026-07-15](#124---2026-07-15)
- [[1.2.3] - 2026-07-13](#123---2026-07-13)
- [[1.2.2] - 2026-07-08](#122---2026-07-08)
- [[1.2.1] - 2026-07-07](#121---2026-07-07)
- [[1.2.0] - 2026-07-05](#120---2026-07-05)
- [[1.1.0] - 2026-07-04](#110---2026-07-04)
- [[1.0.1] - 2026-07-04](#101---2026-07-04)
- [[1.0.0] - 2026-07-04](#100---2026-07-04)

## [Unreleased]

## [1.10.1] - 2026-10-30

Security: Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-1101).

### Security

- **Yopass routes registered without the route loader (REQ-SF-004 rule 7):** on Symfony >= 7.3 `#[AsController]` and the `#[Route]` attributes autoconfigured `ShareManageController` and `PublicShareController` with the `routing.controller` tag, so a host whose `config/routes.yaml` has the default `controllers: { resource: routing.controllers }` got the default `/tools/yopass/...` routes and the anonymous `/share/{id}` reveal / consume endpoints even without importing the `nowo_yopass` loader, bypassing `nowo_yopass.routes`, `route_prefix` and `web_ui.enabled: false`. The controllers are registered explicitly (`autoconfigure: false`, `controller.service_arguments` + `container.service_subscriber`, no `#[AsController]`); the routes exist only where the host imports `type: nowo_yopass`. Regression test with a kernel that only loads `routing.controllers`.

[1.10.1]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.10.1

## [1.10.0] - 2026-10-30

Yopass: UiKit page contract (guest reveal page, confirm dialogs, no CDN), no-store/no-referrer on secret pages, preview wiped on close See [UPGRADING](UPGRADING.md#to-1100).

Yopass on the UiKit page contract (REQ-UI-003): list / show scaffolds fed by view models, public reveal page on the UiKit guest layout, canonical `web_ui.*` / `security.roles.*` keys with deprecated aliases. See [UPGRADING](UPGRADING.md#unreleased).

### Template contract

- **Manage page** `manage/index.html.twig` extends `@NowoUiKit/page/list.html.twig` (`View\ListPage` from `Ui\YopassPageFactory::managePage()`); data block `nowo_ui_list_row`; `nowo_ui_content` adds the create panel (`manage/_create_panel.html.twig`) and the preview dialog (`manage/_preview_dialog.html.twig`, UiKit `dialog` partial, native `<dialog>`). Header, back link, Delete all, pagination, empty state, row actions (View, Extend, Revoke, Delete) and confirm dialogs come from UiKit.
- **Share created page** `manage/created.html.twig` extends `@NowoUiKit/page/show.html.twig` (`View\ShowPage`); data block `nowo_ui_show_sections`.
- **Public reveal page** `public/reveal.html.twig` is a unique page on the UiKit guest layout (`nowo_ui_layout.guest` or `web_ui.public_layout_template`); it no longer prints its own `<html>` document nor loads Tabler from a CDN.
- **Removed:** `layout.html.twig` (default shell loading Tabler / Bootstrap / icon fonts from CDNs; the 1.x value of `web_ui.layout_template` maps to the UiKit layout) and `_action_icons.html.twig` (row actions are UiKit `Action`s with canonical kinds `view`, `restore`, `cancel`, `delete`). The 1.x Bootstrap `div.modal` preview and the `nowo-yopass-confirm` controller (`window.confirm()`) are gone (UiKit dialog / confirm contracts).
- No framework utility classes in bundle markup, form types or scripts: theme slots (`nowo_ui_class()`), `nowo-ui-*` and `nowo-yopass-*` hooks only; toggled elements use the `hidden` attribute instead of `d-none`. Form fields carry `nowo-ui-input` (FormKit profile `yopass` without framework classes); the host form theme styles them.
- New `docs/TEMPLATES.md` (pages → scaffolds, view models, data blocks, unique pages) and the shared contract test `tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict missing-translation rule).

### Added

- `web_ui.path_prefix`, `web_ui.public_layout_template`, `web_ui.templates.{manage,created,public}` and `security.roles.{admin,create,list,revoke}` (REQ-UI-003 vocabulary).
- `Ui\YopassPages` (layouts, per-page templates, deprecated 1.x layout bridge `_legacy_layout.html.twig`) and `Ui\YopassPageFactory` (UiKit view models).
- Translation key `yopass.list.actions` (7 locales).
- Container compile error when the built-in role checker is used and every role list is empty (nobody could open the manage UI).

### Changed

- Requires `nowo-tech/ui-kit-bundle` `^2.15.4` (was `^1.4 || ^2.2`).
- `web_ui.layout_template` defaults to `null` (UiKit `nowo_ui_kit.layouts.admin`); `web_ui.css_framework` / `icon_set` default to `null` and, when set, are only copied to `nowo_ui_kit` (the CSS stack is inherited from UiKit).
- The FormKit profile `yopass` seeded by the bundle no longer adds Bootstrap classes (`form-control`, `form-select`, `mb-2`, …); it sets `nowo-ui-input` and `convention_requires_translation: true`.
- `ShareManageController` / `PublicShareController` take `Ui\YopassPageFactory` instead of the `$templates` array.

### Security

- **Reveal page on a host layout (REQ-SEC-004, delta re-audit 2026-10-30, Low).** The public reveal page now extends the host-overridable UiKit guest layout (`web_ui.public_layout_template`, else `nowo_ui_kit.layouts.guest`), so a third-party script loaded there (analytics, tag manager, session replay, chat widget) could read the `#decrypt_key=` fragment, the key / password input and the decrypted plaintext. USAGE, TEMPLATES, CONFIGURATION and SECURITY now state that the guest / public layout must not load third-party scripts (dedicated minimal layout + strict CSP).
- The public reveal page (`GET /share/{id}`) and the "share created" page send `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer` (shared `PublicShareController::hardenSecretResponse()`).
- The manage preview dialog wipes the decrypted text, the key / password inputs, the public link and the decrypted file download link (blob URL revoked) on **every** close — close button, Escape and backdrop (native `close` event) — instead of only resetting its internal state, so no plaintext stays in the hidden DOM. `src/Resources/public/js/yopass.js` rebuilt.

### Deprecated

- `route_prefix` (use `web_ui.path_prefix`), `templates.layout` / `templates.{manage,created,public}` (use `web_ui.layout_template` / `web_ui.templates.<page>`), `security.{admin,create,list,revoke}_roles` (use `security.roles.<action>`), `web_ui.css_framework` / `web_ui.icon_set` (use `nowo_ui_kit`), the Twig globals `nowo_yopass_layout_template` / `nowo_yopass_css_framework` / `nowo_yopass_icon_set`, `layout_integrate_host.html.twig` and 1.x layouts without the UiKit canonical blocks (bridged). All removed in 2.0.

[1.10.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.10.0

## [1.9.0] - 2026-10-29

Yopass: decryption key kept in the URL fragment (never sent to the server), recipe APP_SECRET removed, password mode works (libsodium sumo) See [UPGRADING](UPGRADING.md#to-190).

Yopass: one-click links carry the key in the URL fragment and honour the embed options, no `APP_SECRET` in the Flex recipe, working password mode. See [UPGRADING](UPGRADING.md#unreleased).

### Security

- **REQ-SEC-004 M1 — the `sharing.allow_embed_in_url` / `sharing.default_embed_in_url` options had no effect up to 1.8.0.** The "share created" page always offered a one-click `/share/{id}?decrypt_key=…` link (and the manage preview embedded the stored key in its public link), so the key or password was sent to the server in the query string, where web-server / reverse-proxy access logs, the Symfony router log and APM tools could record it. Now:
  - One-click links put the key in the **URL fragment** (`/share/{id}#decrypt_key=…`), which browsers never send to the server.
  - The one-click link is rendered only when `sharing.allow_embed_in_url` is `true` (default `true`); otherwise the page shows the short link and the key separately. `sharing.default_embed_in_url: true` lists it first; with the default `false` it comes after the short link and the key. The manage preview embeds the stored key in its public link only when embedding is allowed.
  - New `ShareKeyQueryRedirectListener` (`kernel.request`, priority 64, before the router): a `GET|HEAD` of the public share page carrying `decrypt_key` / `key` / `password` in the query string is answered `303 See Other` to the same path with the key moved into the fragment (`Cache-Control: no-store`, `Referrer-Policy: no-referrer`). Old links keep working, the router never logs the key, and it no longer stays in the address bar or the history. The first request of such an old link still reaches the web server / proxy, which may have logged it.
- **REQ-SEC-004 M2 — the Flex recipes (1.0.0 and 1.1.0) appended `APP_SECRET=ChangeMeInProduction` to the host `.env`.** Flex appends the recipe block unconditionally, and Dotenv keeps the last definition, so this public value could override the host secret (`kernel.secret` signs fragment URIs, remember-me cookies, login links, `UriSigner` URLs). The `env` block is removed from both recipe manifests; the bundle needs no secret. Hosts that installed the recipe must remove the line and rotate `APP_SECRET` (see UPGRADING).

### Fixed

- **REQ-SEC-004 L1 — password mode did not work in the browser.** The shipped `yopass.js` was built with the standard `libsodium-wrappers`, which has no `crypto_pwhash` (Argon2id); creating a password-protected share failed (closed: nothing was posted) and password envelopes could not be decrypted. The bundle now depends on `libsodium-wrappers-sumo` `^0.8.4` (same API and version line) and `src/Resources/public/js/yopass.js` is rebuilt. Raw library errors on create are shown as `CREATE_FAILED` instead of the library message.
- **REQ-SEC-004 L2** — with `web_ui.enabled: false` the route loader registers only the public routes (`/share/{id}`, `/share/{id}/consume`). Before, the manage routes stayed routed while the CSRF / SecurityBundle compile checks were skipped.
- Manage preview: after extending a share, the re-rendered public link kept no key because `renderMetadata()` was called without the share id (`tsc` error).

### Tests

- Vitest: password-mode encrypt / decrypt round trips (text and file) against the real libsodium build, no mock; fragment link build / read / strip; created page and preview with embedding allowed / not allowed; reveal from a `#decrypt_key=` fragment.
- PHPUnit: `ShareKeyQueryRedirectListenerTest`, route loader with the Web UI disabled, `ShareCreatedLinksType` `allow_embed_in_url` option.

### CI

- TypeScript job runs `pnpm run typecheck` (`tsc --noEmit`) before Vitest; new `make typecheck` (part of `make release-check`).

[1.9.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.9.0

## [1.8.0] - 2026-10-29

Yopass: injected clock in share creation and public rate limiter, TS coverage over every source, Twig override docs See [UPGRADING](UPGRADING.md#to-180).

### Changed

- **REQ-DI-001** — `ShareCreator` computes the expiration date of new shares from an injectable `Psr\Clock\ClockInterface` (new optional third constructor argument; autowired to the framework `clock`), and `PublicEndpointRateLimiter` uses the clock instead of `time()` for its fixed window (new optional fifth argument, wired to `Psr\Clock\ClockInterface` when the service exists). `ShareCreateData::resolveExpiresAt()` accepts an optional `$now` reference time. Without a clock service both fall back to the system clock, so behaviour is unchanged. See [UPGRADING](UPGRADING.md#unreleased).

### Documentation

- **REQ-TWIG-001** — USAGE “Twig overrides”: loader order, upgrade-safe customisation table, freeze rule, override procedure, full `<subpath>` table and recovery after a freeze.
- **REQ-SEC-002** — SECURITY release checklist (12.4.1) now covers every item (SECURITY.md, `.env` ignore, secrets, config/recipe, input/output, dependencies, logs, cryptography, permissions/exposure, limits/DoS, AI audit).
- **REQ-DI-001** — USAGE “Clock” section lists the clock-aware services and the documented entity exception.
- **REQ-CS-001** — PHPDoc description on every public class and public method in `src/`.
- **REQ-TEST-007** — README “Tests and Coverage” shows the measured PHP and TypeScript percentages.
- DEMO-FRANKENPHP: “Database (MySQL)” section and corrected demo command list.

### Tests

- **REQ-TEST-003** — Vitest coverage now measures every TypeScript source under `src/Resources/assets/src/` (previously only `yopass-crypto.ts`) and fails below 90% lines / statements / functions / branches. New happy-dom tests for the Stimulus controllers and helpers (`happy-dom` dev dependency).
- Clock-driven tests for `ShareCreator`, `ShareCreateData` and `PublicEndpointRateLimiter`.

### Demo

- **REQ-DEMO-006 / REQ-DEMO-011** — removed the unused Flex PostgreSQL `database` service, its volume and the tracked `compose.override.yaml` (published port 5432); MySQL is the only demo database.
- **REQ-MAKE-003** — demo Makefile adds `restart`, `setup`, `test-coverage` and `verify` (HTTP 200 on `/tools/yopass`).

[1.8.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.8.0

## [1.7.0] - 2026-10-28

Security: the admin UI refuses to compile without form CSRF protection (CsrfProtectionPass, fail closed). See [UPGRADING](UPGRADING.md#to-170).

### Security

- CSRF fails closed: new `CsrfProtectionPass` makes the container refuse to compile when the manage Web UI (`web_ui.enabled: true`) is on and form CSRF protection is unavailable (`security.csrf.token_manager` or `form.type_extension.csrf` missing, or `framework.form.csrf_protection: false`). Before, share revoke / delete / delete all (`CsrfActionType` declares its own CSRF options, checked by `isCsrfActionValid()`) and the create form accepted tokenless cross-site POSTs in that configuration. `symfony/security-csrf` is now a hard requirement. See [UPGRADING](UPGRADING.md#unreleased) and [SECURITY](SECURITY.md#threats-and-mitigations).

[1.7.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.7.0

## [1.6.0] - 2026-10-26

Forms in the bundle views are Symfony Form Types rendered with form_start / child loop / form_end, no raw form controls (REQ-TWIG-005). See [UPGRADING](UPGRADING.md#to-160).

### Changed

- Bundle views render every control through Symfony Form Types (REQ-TWIG-005): the revoke / delete / delete-all actions use `CsrfActionType` (same flat `_token` and token ids; the controller validates them through the Form instead of `isCsrfTokenValid()`), the browser-only secret / file / encryption mode / custom password controls are unmapped children of `ShareCreateType` (fixed ids and Stimulus targets, empty `name`, so the posted payload is unchanged), and the preview / extend modal, the created page and the public reveal page use `SharePreviewType`, `ShareCreatedLinksType` and `ShareRevealType`. `ShareCreateType` gains the `allow_custom_password` and `default_encryption` options. Template overrides must render the new form variables (see UPGRADING).

### Added

- `make check-no-raw-html-form` (REQ-TWIG-005) fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle and demo Twig; it runs in `release-check`.

[1.6.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.6.0

## [1.5.5] - 2026-10-25

Reproducible JS bundle: the build stamp is a hash of the sources instead of the build time. See [UPGRADING](UPGRADING.md#to-155).

### Changed

- **Assets:** reproducible bundle build. `__YOPASS_BUILD_TIME__` now holds a build id (a hash of the TypeScript/CSS sources and `pnpm-lock.yaml`) instead of the build time, so rebuilding unchanged sources (`make assets`, `make release-check`) no longer rewrites the committed JS; the console line reads `script loaded, build: <id>`.
- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `check-twig-extra`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `validate-translations`, `coverage-check`, demo release-check, `test-ts`). `check-no-raw-html-form` (REQ-TWIG-005) is wired once the remaining raw form markup is migrated.

[1.5.5]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.5

## [1.5.4] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-154).

### Changed

- Views: `manage/index.html.twig` keeps the Stimulus-targeted hidden `ciphertext` / `payloadKind` widgets (rule 3)
  and renders the remaining create-form children (expiry, max reads, CSRF token) through the child loop
  (`{% for child in form %}` + `not child.rendered` + `form_row(child)`), closing with `form_end(create_form)`, per
  REQ-TWIG-003. The `mb-3` row and `form-label` label classes of `expiresIn` / `maxReads` moved into
  `ShareCreateType` (`row_attr` / `label_attr`).

[1.5.4]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.4

## [1.5.3] - 2026-10-20

Dependency refresh (bundle and demos), php-cs-fixer ParallelConfig(2). See [UPGRADING](UPGRADING.md#to-153).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19); shipped assets rebuilt.
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README standard badges (PHP 8.2+, Symfony 7.4 | 8.0 | 8.1+, stars image, Coverage) and documentation list order, CONTRIBUTING Cursor Agent settings, canonical bug-report template, demo `.env.test` comment, SPEC-DRIVEN-DEVELOPMENT functional scope / non-goals / see also, doc TOCs, Spec Kit inventory refresh (77/77).

[1.5.3]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.3

## [1.5.2] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-152).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock and the demo lock use 2.2.0. Templates are unchanged (compatibility release).

[1.5.2]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.2

## [1.5.1] - 2026-10-13

### Changed

- **Dev:** Vitest 4 (`vitest` / `@vitest/coverage-v8` `^4.1.11`).
- Docs: `docs/SECURITY.md` threat model lists the `payloadKind` validation added in 1.5.0.

[1.5.1]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-10-13

### Fixed

- Share links on the "created" page and in the manage preview (`public_path` / `publicPath`) are now generated by the router from `routes.public_show.name`, so `route_prefix`, extra route placeholders (e.g. `{_locale}`) and an app base path are honoured instead of copying the raw configured path.

### Security

- `ShareCreateType` validates the hidden `payloadKind` field with `Choice(['text', 'file'])`; any other value is rejected (`yopass.error.create`) instead of being stored.

### Changed

- CI: dropped the Symfony 7.0 matrix cell (and the 7.0 coverage install), which could not resolve against the `^7.4 || ^8.0` constraints; coverage now runs on Symfony 7.4.

[1.5.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.0

## [1.4.9] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `vite` 8.3.2 → 8.3.4 (frontend tooling; #66).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #67).
- `nowo-tech/form-kit-bundle` 2.6.1 → 2.7.1 (development lock; #68).
- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #69).

[1.4.9]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.9

## [1.4.8] - 2026-10-09

### Security

- **Public share rate limiting actually enabled:** `YopassExtension` checked `has('cache.app')` / `has('logger')` inside `load()`, where the isolated extension container never sees framework services, so `PublicEndpointRateLimiter` always received a `null` cache pool (rate limiting on `/share/*` silently skipped) and a user warning fired on every container compile. The limiter now gets `cache.app` and `logger` as optional references resolved on the merged container; the "cache.app missing" warning moved to the new `PublicRateLimitCachePass`.

### Changed

- PHPStan: use the published `ruleset-worker.neon` only (drop the local no-kernel-reset ignores).
- Dependencies (Dependabot + lock refresh): `doctrine/orm` 3.7.4, FormKitBundle 2.6.1, UiKitBundle 1.9.2; dev Vite 8.3.2, PHPStan 2.3.1 (+ phpunit/symfony extensions 2.1), PHPUnit 10.5.66, PHP-CS-Fixer 3.95.27, Rector 2.7.0, `igor-php/igor-php` 0.10.1, `nowo-tech/phpstan-frankenphp` 1.2.3, `mongodb/mongodb` 2.5.0.
- Demo (Symfony 8): `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, FormKitBundle 2.6.1, UiKitBundle 1.9.2, Twig 3.30.0, HotReloadBundle 1.5.5, TwigInspectorBundle 1.1.7; regenerated `config/reference.php`.

[1.4.8]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.8

## [1.4.7] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.4.7]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.7

## [1.4.6] - 2026-09-25

### Fixed

- **FrankenPHP worker (`FRANKENPHP_RESET_KERNEL` unset/false):** ORM repositories resolve the EntityManager from `ManagerRegistry` per call and reset it when a previous flush closed it, so one failed write no longer breaks later requests in the same worker (W-01).
- **FrankenPHP worker:** MongoDB share repository likewise resolves/reopens the DocumentManager via `doctrine_mongodb` registry when closed.
- **FrankenPHP worker:** `ShareRepositoryInterface::find()` always reads from the database (`Query::HINT_REFRESH` for ORM, `DocumentManager::refresh()` for MongoDB), so a share revoked, extended, consumed or deleted by another worker is never served from a stale identity map (W-01).
- **Doctrine ORM:** `DoctrineOrmShareRepository::consumeReadIfAvailable()` no longer calls `EntityManager::clear()` (which detached host application entities); the consumed share is re-read with a refresh instead (W-03).

### Added

- **`docs/FRANKENPHP-WORKER-AUDIT.md`** — full worker audit for kernel reuse without reset (scenario B / `FRANKENPHP_RESET_KERNEL` unset/false).
- **PHPStan** — enable `ruleset-worker-no-kernel-reset.neon` (missing `ResetInterface` detection for shared mutable services).

## [1.4.5] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.4.5]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.5

## [1.4.4] - 2026-08-20

### Security

- **Flex recipe:** ship `security_nowo_yopass.yaml` (`/share` public, `/tools/yopass` `ROLE_ADMIN`); `when@prod` keeps `default_embed_in_url: false`. REQ-SEC-004 **Pass (good)**. Prefer **`^1.4.4`**.

## [1.4.3] - 2026-08-19

### Changed

- Code style only (PHP CS Fixer). **No application upgrade steps.**

[1.4.3]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.3

## [1.4.2] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.4.2]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.2

## [1.4.1] - 2026-08-07

### Fixed

- **CI / FormKit:** `ControllerContainerBuilder` injects `FormOptionsMerger` via `FormKitTestSupport::withMerger()` for `ShareCreateType` (fixes uninitialized `$formOptionsMerger` in controller unit tests).
- **PHPStan:** simplify `prependUiKitDefaults` (remove always-true `is_array` / empty-defaults guards).

### Tests

- Cover FormKit / UiKit prepend seed and host-override paths in `YopassExtensionTest`.

[1.4.1]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.1

## [1.4.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `yopass` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Manage/public Twig imports `@NowoUiKitBundle/macros/ui.html.twig`; removed `_ui_macros.html.twig`. Domain icons live in `_action_icons.html.twig`. Requires `nowo-tech/ui-kit-bundle` `^1.4`. Extension seeds `nowo_ui_kit` from `web_ui` when the host has not configured UiKit.

[1.4.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.0

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

[Unreleased]: https://github.com/nowo-tech/YopassBundle/compare/v1.10.1...HEAD
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
