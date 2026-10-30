# Upgrading

## Table of contents

- [Unreleased](#unreleased)
  - [Template contract (UiKit page contract, REQ-UI-003)](#template-contract-uikit-page-contract-req-ui-003)
  - [Configuration keys](#configuration-keys)
- [To 1.9.0](#to-190)
  - [Remove the recipe `APP_SECRET` and rotate it (security)](#remove-the-recipe-app_secret-and-rotate-it-security)
  - [One-click links: key in the URL fragment, embed options honoured (security)](#one-click-links-key-in-the-url-fragment-embed-options-honoured-security)
  - [Password mode, Web UI flag](#password-mode-web-ui-flag)
- [To 1.8.0](#to-180)
  - [Clock-aware share creation and public rate limiting](#clock-aware-share-creation-and-public-rate-limiting)
- [To 1.7.0](#to-170)
  - [Manage Web UI requires form CSRF protection at compile time](#manage-web-ui-requires-form-csrf-protection-at-compile-time)
- [To 1.6.0](#to-160)
  - [Forms rendered through Form Types (REQ-TWIG-005)](#forms-rendered-through-form-types-req-twig-005)
- [To 1.5.5](#to-155)
- [To 1.5.4](#to-154)
- [To 1.5.3](#to-153)
- [To 1.5.2](#to-152)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.9](#to-149)
- [To 1.4.8](#to-148)
- [To 1.4.7](#to-147)
- [From 1.4.5 to 1.4.6](#from-145-to-146)
- [From 1.4.4 to 1.4.5](#from-144-to-145)
- [From 1.4.4 to 1.4.5](#from-144-to-145-1)
- [1.4.4 (2026-08-20)](#144-2026-08-20)
- [1.4.3 (2026-08-19)](#143-2026-08-19)
- [1.4.2 (Symfony 8 demos / Hot Reload 1.4)](#142-symfony-8-demos--hot-reload-14)
- [1.4.1 (2026-08-07)](#141-2026-08-07)
- [1.4.0 (2026-08-04)](#140-2026-08-04)
  - [UiKit composition (REQ-UI-001-kit)](#uikit-composition-req-ui-001-kit)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)
- [1.3.2 (2026-07-29)](#132-2026-07-29)
- [1.3.1 (2026-07-27)](#131-2026-07-27)
- [1.3.0 (2026-07-27)](#130-2026-07-27)
  - [Web UI contract (REQ-UI-001) and security defaults (REQ-UI-002)](#web-ui-contract-req-ui-001-and-security-defaults-req-ui-002)
- [1.2.8 (2026-07-22)](#128-2026-07-22)
- [1.2.7 (2026-07-22)](#127-2026-07-22)
- [1.2.6 (2026-07-16)](#126-2026-07-16)
- [1.2.5 (2026-07-15)](#125-2026-07-15)
- [1.2.4 (2026-07-15)](#124-2026-07-15)
- [1.2.3 (2026-07-13)](#123-2026-07-13)
- [1.2.2 (2026-07-08)](#122-2026-07-08)
- [1.2.1 (2026-07-07)](#121-2026-07-07)
  - [Fixes included](#fixes-included)
  - [Upgrade steps](#upgrade-steps)
- [1.2.0 (2026-07-05)](#120-2026-07-05)
  - [Public rate limiting](#public-rate-limiting)
  - [Custom `ShareRepositoryInterface`](#custom-sharerepositoryinterface)
  - [Translations](#translations)
  - [Upgrade steps](#upgrade-steps-1)
- [1.1.0 (2026-07-04)](#110-2026-07-04)
  - [Share list and per-share access events](#share-list-and-per-share-access-events)
  - [Upgrade steps](#upgrade-steps-2)
- [1.0.1 (2026-07-04)](#101-2026-07-04)
  - [Symfony 8 + Doctrine](#symfony-8--doctrine)
  - [Assets](#assets)
- [1.0.0 (2026-07-04)](#100-2026-07-04)
  - [Requirements](#requirements)
  - [Install](#install)
  - [Public links](#public-links)

## Unreleased

```bash
composer update nowo-tech/yopass-bundle nowo-tech/ui-kit-bundle --with-dependencies
php bin/console assets:install public
php bin/console cache:clear
```

### Template contract (UiKit page contract, REQ-UI-003)

The pages render inside the **UiKit layouts** and the **UiKit theme**, configured once for every Nowo bundle:

```yaml
nowo_ui_kit:
    css_framework: bootstrap5            # was nowo_yopass.web_ui.css_framework (default tabler)
    icon_set: bootstrap-icons            # was nowo_yopass.web_ui.icon_set (default tabler-icons)
    layouts:
        admin: 'layouts/admin.html.twig' # renders the canonical nowo_ui_* blocks (extend @NowoUiKit/layout/admin.html.twig or use @NowoUiKit/layout/_bridge.html.twig)
        guest: 'layouts/guest.html.twig' # the public reveal page
twig:
    form_themes: ['bootstrap_5_layout.html.twig']   # the field classes now come from your form theme
```

| 1.9 | 1.10 |
| --- | --- |
| Pages extend `nowo_yopass_layout_template` (default `@NowoYopassBundle/layout.html.twig`, Tabler from a CDN) and fill `title` / `body` / `stylesheets` / `javascripts` | `manage/index.html.twig` extends the UiKit **list** scaffold, `manage/created.html.twig` the **show** scaffold; canonical blocks `nowo_ui_document_title`, `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts` |
| `public/reveal.html.twig`: standalone `<html>` with Tabler from a CDN | unique page on `nowo_ui_kit.layouts.guest` (or `web_ui.public_layout_template`) |
| `layout.html.twig` | **removed** — the value `@NowoYopassBundle/layout.html.twig` maps to null (UiKit layout); load your CSS framework in your layout |
| `_action_icons.html.twig` macro | **removed** — row actions are UiKit `Action`s (`row_actions_display` / `icon_set` from `nowo_ui_kit`) |
| Bootstrap `div.modal` preview, `window.confirm()` on revoke / delete | native `<dialog>` (UiKit `dialog` partial) and the UiKit confirm `<dialog>`; the layout must load the UiKit runtime scripts (`{{ include(nowo_ui_partial('scripts')) }}`, done by the UiKit layouts) |
| Framework classes in markup / form types (`btn`, `card`, `alert`, `form-control`, `d-none`, …) | theme slots + `nowo-ui-*` / `nowo-yopass-*` hooks; `hidden` attribute; FormKit profile `yopass` sets only `nowo-ui-input` |

- **Host layout still a 1.x layout?** If `web_ui.layout_template` points at a layout that renders `body` (e.g. your
  `base.html.twig` or `@NowoYopassBundle/layout_integrate_host.html.twig`), the pages keep rendering through the
  deprecated `@NowoYopassBundle/_legacy_layout.html.twig` bridge (a deprecation is logged). Move the layout to
  `nowo_ui_kit.layouts.admin` and set `web_ui.layout_template` back to null before 2.0.
- **Template overrides** of `manage/index.html.twig`, `manage/created.html.twig` or `public/reveal.html.twig` written
  for 1.x must be redone: prefer theming UiKit (`nowo_ui_kit.theme`, scaffolds under
  `templates/bundles/NowoUiKitBundle/page/`), or `web_ui.templates.<page>` with a thin
  `{% extends '@!NowoYopassBundle/...' %}` overriding one data block (`nowo_ui_list_row`, `nowo_ui_show_sections`,
  `nowo_ui_content`). Pages, view models and data blocks: [Templates](TEMPLATES.md).
- **Controller wiring** (only if you decorate or instantiate the controllers yourself): `ShareManageController` and
  `PublicShareController` take `Nowo\YopassBundle\Ui\YopassPageFactory` instead of the `$templates` array.
- **Security is unchanged**: one-click links keep the key in the URL fragment, legacy `?decrypt_key=` links are still
  redirected (`303`), the manage routes are registered only with `web_ui.enabled`, and the browser crypto still uses
  libsodium sumo.

### Configuration keys

Old keys keep working in 1.x (deprecation notices) and are removed in 2.0; the canonical key wins when both are set.

| Deprecated | Use |
| --- | --- |
| `route_prefix` | `web_ui.path_prefix` |
| `templates.layout` | `web_ui.layout_template` (null = `nowo_ui_kit.layouts.admin`) |
| `templates.manage` / `templates.created` / `templates.public` | `web_ui.templates.manage` / `.created` / `.public` |
| `security.admin_roles` / `create_roles` / `list_roles` / `revoke_roles` | `security.roles.admin` / `.create` / `.list` / `.revoke` |
| `web_ui.css_framework` / `web_ui.icon_set` | `nowo_ui_kit.css_framework` / `nowo_ui_kit.icon_set` (copied there while set) |
| Twig globals `nowo_yopass_layout_template`, `nowo_yopass_css_framework`, `nowo_yopass_icon_set` | `nowo_ui_layout`, `nowo_ui_kit_css_framework`, `nowo_ui_kit_icon_set` |

New: `web_ui.public_layout_template` (null = `nowo_ui_kit.layouts.guest`). With the built-in role checker and the Web
UI on, the container now refuses to compile when **every** role list is empty (nobody could open the manage UI).

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/yopass-bundle
```

From **1.8.0**:

```bash
composer update nowo-tech/yopass-bundle
php bin/console assets:install
```

`src/Resources/public/js/yopass.js` changed (fragment links, libsodium sumo build): install the assets again and purge any CDN / HTTP cache that serves `/bundles/yopass/js/yopass.js`.

### Remove the recipe `APP_SECRET` and rotate it (security)

The Flex recipes up to this release appended this block to `.env` (or `.env.dist`):

```dotenv
###> nowo-tech/yopass-bundle ###
APP_SECRET=ChangeMeInProduction
###< nowo-tech/yopass-bundle ###
```

Dotenv keeps the last definition, so that public value could replace your own `APP_SECRET`.

1. Delete the `###> nowo-tech/yopass-bundle ###` block (or at least its `APP_SECRET` line) from `.env` / `.env.dist`. Also check `.env.local.php` (`composer dump-env`) and any image built from those files.
2. If the deployed `kernel.secret` was ever `ChangeMeInProduction`, **rotate** `APP_SECRET`: set a new random value (`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`) in the real environment or the secrets vault (`php bin/console secrets:set APP_SECRET`), then redeploy. Remember-me cookies, login links and signed URLs issued with the old secret stop validating; users sign in again.
3. Yopass itself never reads `APP_SECRET`.

### One-click links: key in the URL fragment, embed options honoured (security)

Up to 1.8.0 `sharing.allow_embed_in_url` and `sharing.default_embed_in_url` were ignored: the "share created" page always offered a `/share/{id}?decrypt_key=…` link, so keys reached the server (access logs, router log, APM).

- New one-click links are `/share/{id}#decrypt_key=…`. The fragment is never sent to the server. The reveal page reads it and removes it from the address bar.
- **Old links still work.** A `GET /share/{id}?decrypt_key=…` (or legacy `?key=` / `?password=`) is redirected (`303`) to `/share/{id}#decrypt_key=…` before routing (`ShareKeyQueryRedirectListener`), so the application never logs or renders it. The web server / reverse proxy in front of the app may still have logged the first request of an old link: treat those logs as sensitive, or rotate (recreate) shares sent with old one-click links.
- `sharing.allow_embed_in_url: false` now really hides the one-click link: the created page shows the short link and the key (send them on separate channels), and the manage preview shows the public link without the key. Default stays `true`.
- `sharing.default_embed_in_url: true` lists the one-click link first on the created page; with the default `false` it comes after the short link and the key.
- If you override `@NowoYopassBundle/manage/created.html.twig`: the `links_form.oneClickLink` child exists only when `allow_embed_in_url` is `true`. Guard it with `{% if links_form.oneClickLink is defined %}` and pass `data-nowo-yopass-created-allow-embed-in-url-value` like the bundle template (without it the controller never fills the one-click field). If you override `manage/index.html.twig`, add `data-nowo-yopass-manage-preview-allow-embed-in-url-value="{{ sharing_options.allow_embed_in_url ? 'true' : 'false' }}"` to the preview controller element to keep keys in preview links.
- If something else in your stack builds links with `?decrypt_key=` (a custom integration, e-mails), switch it to `#decrypt_key=` (URL-encoded value).

### Password mode, Web UI flag

- Password-protected shares now work in the browser (`libsodium-wrappers-sumo`). If you build `yopass.js` yourself from `src/Resources/assets`, replace `libsodium-wrappers` with `libsodium-wrappers-sumo@^0.8.4`. The shipped bundle grows from about 490 KiB to about 590 KiB.
- `web_ui.enabled: false` now leaves only the public routes registered (`/share/{id}`, `/share/{id}/consume`). Before, the manage routes stayed reachable. If you relied on them with the Web UI disabled, enable the Web UI again (its CSRF / SecurityBundle compile checks then apply).

## To 1.8.0

From **1.7.0**:

```bash
composer update nowo-tech/yopass-bundle
```

### Clock-aware share creation and public rate limiting

No action is required for most hosts.

- `Nowo\YopassBundle\Service\ShareCreator::__construct()` gains an optional third argument `?Psr\Clock\ClockInterface $clock = null`, and `Nowo\YopassBundle\Security\PublicEndpointRateLimiter::__construct()` an optional fifth one. Both are wired automatically (framework `clock` service when `symfony/clock` is enabled; otherwise the system clock). If you instantiate or decorate these services yourself, existing calls keep working.
- `ShareCreateData::resolveExpiresAt(array $expirationOptions, ?DateTimeImmutable $now = null)` — the new parameter is optional; omitting it keeps the previous “relative to now” behaviour.
- If your application replaces the `clock` service (for example `MockClock` in functional tests), new share expiration dates and the public rate-limit window now follow that clock, like share extension, retrieval and retention already did.
- Demo only: `demo/symfony8` no longer defines the Flex PostgreSQL `database` service. If you ran the demo before, remove the orphan container/volume with `docker compose down --remove-orphans` and `docker volume rm yopass-bundle-demo-symfony-8_database_data` (if present).

## To 1.7.0

From **1.6.0**:

```bash
composer update nowo-tech/yopass-bundle
```

### Manage Web UI requires form CSRF protection at compile time

`symfony/security-csrf` is now required (`composer update nowo-tech/yopass-bundle` installs it). With `web_ui.enabled: true` the container no longer compiles without form CSRF protection (`LogicException`: "requires CSRF protection"). Standard Flex hosts (`framework.csrf_protection` enabled, `framework.form.csrf_protection` not disabled) need no change. Otherwise enable `framework.csrf_protection: true` — also in the `test` environment of functional suites that enable the manage UI — or set `web_ui.enabled: false`.

## To 1.6.0

From **1.5.5**:

```bash
composer update nowo-tech/yopass-bundle
```

### Forms rendered through Form Types (REQ-TWIG-005)

The controllers always pass these new variables; **if you override a template**, replace its raw controls with `form_start` / the child loop / `form_end` on them (see [USAGE — Forms in templates](USAGE.md#forms-in-templates-req-twig-005)):

| Template | New variable(s) | Replaces |
| -------- | --------------- | -------- |
| `manage/index.html.twig` | `create_form.secret`, `create_form.file`, `create_form.encryptionMode`, `create_form.customPassword` | raw `<textarea id="yopass-secret">`, `<input type="file" id="yopass-file">`, `yopass-encryption-mode` radios, `<input id="yopass-custom-password">` |
| `manage/index.html.twig` | `revoke_forms[share.id]`, `delete_forms[share.id]`, `delete_all_form` | hand-rolled `<form>` + `_token` |
| `manage/index.html.twig` | `preview_form` | preview password / key / public link inputs and the extend selects |
| `manage/created.html.twig` | `links_form` | read-only one-click link / short link / key inputs |
| `public/reveal.html.twig` | `reveal_form` | `share-password` / `share-key` inputs |

Ids, `data-nowo-yopass-*` targets and posted field names are unchanged, so the bundled JavaScript works with old and new markup. Because `create_form` now has extra children, an override that renders `create_form` with the child loop but without the browser-only controls would render them twice — render them explicitly (as the shipped template does) before the loop. `ShareCreateType` has two new options, `allow_custom_password` (default `true`) and `default_encryption` (default `auto`), passed by `ShareManageController` from `nowo_yopass.sharing`.

## To 1.5.5

From **1.5.4**:

```bash
composer update nowo-tech/yopass-bundle
```

## To 1.5.4

From **1.5.3**:

```bash
composer update nowo-tech/yopass-bundle
```

## To 1.5.3

From **1.5.2**:

```bash
composer update nowo-tech/yopass-bundle
```

## To 1.5.2

From **1.5.1**:

```bash
composer update nowo-tech/yopass-bundle
```

## To 1.5.1

From **1.5.0** — Vitest 4 (dev); threat model documents payloadKind validation

```bash
composer update nowo-tech/yopass-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.5.0

From **1.4.9** — share links generated via the router, payloadKind validated.

```bash
composer update nowo-tech/yopass-bundle
```

- Share links are now generated by the router, so they include `route_prefix`, route placeholders (e.g. `{_locale}`) and the app base path; links change for apps using those.
- A create form submitted with a `payloadKind` other than `text` or `file` is rejected.

## To 1.4.9

From **1.4.8** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/yopass-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.4.8

From **1.4.7** — public share rate limiting fix and dependency refresh. No breaking changes.

```bash
composer update nowo-tech/yopass-bundle
php bin/console cache:clear
```

- `public_rate_limit` (enabled by default: 60 requests / 60 s per client IP on `/share/*` show and consume) is now **effectively enforced** when `cache.app` exists. Previously the limiter never received the cache pool and skipped limiting. If legitimate traffic from a shared IP exceeds the limit, raise `public_rate_limit.limit` / `interval_seconds` or set `public_rate_limit.enabled: false`.
- The "cache.app is missing" warning now only fires when the application really has no `cache.app` service.

## To 1.4.7

From **1.4.6** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/yopass-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## From 1.4.5 to 1.4.6

No breaking configuration keys. Behaviour notes (FrankenPHP worker hardening for `FRANKENPHP_RESET_KERNEL` unset/false):

- `DoctrineOrmShareRepository` and `DoctrineOrmShareAccessLogRepository` accept new optional constructor arguments (`?ManagerRegistry $registry`, `?string $managerName`); the bundle wires them automatically from `doctrine`.
- `DoctrineMongoShareRepository` accepts the same optional registry args wired from `doctrine_mongodb`.
- `DoctrineOrmShareRepository::consumeReadIfAvailable()` no longer clears the whole EntityManager. If your code relied on that side effect after opening a public share, clear or refresh your own entities explicitly.
- `find()` always hits the database (one query per call). Custom `ShareRepositoryInterface` implementations should also avoid serving shares from a per-process cache.
- See [`FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md) for the full worker audit (forms/model transformers stay request-scoped; FormKit `withBuilder()` restores state in `finally`).

```bash
composer update nowo-tech/yopass-bundle
```

## From 1.4.4 to 1.4.5

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/yopass-bundle
```

## From 1.4.4 to 1.4.5

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/yopass-bundle
```

# Upgrading

This document describes how to upgrade between versions of Yopass Bundle.

## 1.4.4 (2026-08-20)

Review Flex `security_nowo_yopass.yaml` (`ROLE_ADMIN` on manage) and keep `sharing.default_embed_in_url: false` in production.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.4.3 (2026-08-19)

No application upgrade steps.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.4.2 (Symfony 8 demos / Hot Reload 1.4)

- No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

## 1.4.1 (2026-08-07)

From **1.4.0** — CI FormKit test wiring + PHPStan cleanup. No host migration.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.4.0 (2026-08-04)

From **1.3.x** — FormKit ^2, UiKit ^1.4, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer.

```bash
composer update nowo-tech/yopass-bundle
php bin/console cache:clear
php bin/console assets:install
```

### UiKit composition (REQ-UI-001-kit)

Manage/public Twig imports `@NowoUiKitBundle/macros/ui.html.twig` (local `_ui_macros.html.twig` removed; domain icons in `_action_icons.html.twig`). Requires `nowo-tech/ui-kit-bundle` `^1.4`. Extension seeds `nowo_ui_kit` from `web_ui` when the host has not configured UiKit.

### FormKitBundle (admin forms)

Ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `yopass` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

## 1.3.2 (2026-07-29)

Maintainer / QA release. **No configuration or schema changes** for typical consumers.

- PHPStan baseline removed; `ignoreErrors: []` with level-8 fixes in controllers, DI, Doctrine metadata, forms, and Mongo count path.
- `make coverage-check` (99% statements) is part of `release-check`; optional MongoDB ODM repository remains excluded from the PHPUnit source gate.
- Packagist homepage / keywords and SECURITY 12.4.1 SEC-004 row updated.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.3.1 (2026-07-27)

Maintainer / compliance release. **No configuration or schema changes** for typical consumers.

- Services and Doctrine share repositories accept an optional `Psr\Clock\ClockInterface` (autowired in Symfony apps via `symfony/clock`). Manual `new ShareRetriever($repo)` / similar call sites keep working (default wall clock).
- Doctrine entities / documents still stamp `createdAt` / `revokedAt` with `new DateTimeImmutable()` at construction (entity lifecycle; not container services).
- `make release-check` now runs `check-open-prs` (requires authenticated `gh`).
- PHPUnit fails on **direct** Symfony deprecations from this package (`max[direct]=0`).

```bash
composer update nowo-tech/yopass-bundle
```

## 1.3.0 (2026-07-27)

### Web UI contract (REQ-UI-001) and security defaults (REQ-UI-002)

**Action required** if you rely on the previous `ROLE_USER` defaults or hard-coded layout paths.

1. **Role defaults** — `security.access_roles`, `create_roles`, `list_roles`, and `revoke_roles` now default to `[ROLE_ADMIN]`. Either grant your users `ROLE_ADMIN`, or set explicit roles in `config/packages/nowo_yopass.yaml`:

```yaml
nowo_yopass:
    security:
        access_roles: [ROLE_USER]
        create_roles: [ROLE_USER]
        list_roles: [ROLE_USER]
        revoke_roles: [ROLE_USER]
```

2. **Firewall** — align `security.access_control` with your configured roles (e.g. `ROLE_ADMIN` for `/tools/yopass` when using defaults).

3. **Layout integration** — manage pages now extend the Twig global `nowo_yopass_layout_template`. Prefer `web_ui.layout_template` over `templates.layout` (still supported as a BC alias).

```yaml
nowo_yopass:
    web_ui:
        layout_template: 'base.html.twig'
        css_framework: bootstrap5   # or tabler, tailwind, foundation, custom
        icon_set: bootstrap-icons
```

4. **SecurityBundle** — when `security.allow_unauthenticated` is `false` (default), compilation fails without SecurityBundle. Demos may set `allow_unauthenticated: true`; production must not.

5. **Template overrides** — if you override manage templates, use `{% extends nowo_yopass_layout_template %}` and `{{ parent() }}` in `stylesheets` / `javascripts` blocks.

6. **PHPStan FrankenPHP (REQ-CS-005)** — `require-dev` now includes `nowo-tech/phpstan-frankenphp`; `phpstan.neon.dist` includes classic + worker rulesets. Run `composer update nowo-tech/phpstan-frankenphp` in your clone if you develop the bundle.

7. **Demo PHP 8.5** — Symfony 8 demo FrankenPHP image is `dunglas/frankenphp:1-php8.5-bookworm`; recreate the demo container after pull.

8. **Routes (REQ-SF-004)** — controllers declare `#[Route]` attributes; the Flex recipe still imports `type: nowo_yopass` so path/name overrides keep working. Do **not** also attribute-import the controllers or routes will duplicate. The loader reads public `Route` attribute properties (Symfony Routing 8.1+) and remains safe under FrankenPHP worker reloads.

Public reveal pages are unchanged for consumers. Rebuild published assets if you fork the frontend (`pnpm run build`).

```bash
composer update nowo-tech/yopass-bundle
```

## 1.2.8 (2026-07-22)

Maintenance release. **No application, configuration, or schema changes** for bundle consumers.

Applies the PHP-CS-Fixer `import_symbols` pass enabled in 1.2.7 and bumps Doctrine lockfile entries. No action required beyond a normal Composer update if you vendor the package.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.2.7 (2026-07-22)

Maintainer / demo tooling only. **No application, configuration, or schema changes** for bundle consumers.

Expands REQ-GIT-001 docs/scripts, bumps Vite and `setup-node`, and adds demo `FRANKENPHP_MODE` (`classic`|`worker`). If you run the Symfony 8 demo, copy the new `FRANKENPHP_MODE` key from `.env.example` into `.env` and recreate the container after changing it.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.2.6 (2026-07-16)

Maintainer tooling and documentation only. **No application, configuration, or schema changes** for bundle consumers.

Adds [`GITHUB_CI.md`](GITHUB_CI.md) and `make strip-cursor-coauthor-from-history` for REQ-GIT-001 cleanup. Upgrade only if you mirror the repo for development or want the latest docs.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.2.5 (2026-07-15)

Maintainer tooling only. **No application, configuration, or schema changes** for bundle consumers.

Hardens REQ-GIT-001 (git hooks, CI audit, contributor docs) and bumps dev-only lockfile dependencies. If you cloned the repo for development, run `make setup-hooks` once per clone.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.2.4 (2026-07-15)

Documentation and maintainer tooling only. **No application, configuration, or schema changes** for bundle consumers.

Adds Code of Conduct, git commit hygiene checks, and bumps frontend dev dependencies (TypeScript, Vite). Upgrade only if you want updated docs or mirror the repo for development.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.2.3 (2026-07-13)

Maintenance release. **No application, configuration, or schema changes** for bundle consumers.

Updates CI (`actions/setup-node` 6) and dev-only lockfile bumps. Upgrade only if you vendor docs or mirror the repo for development.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.2.2 (2026-07-08)

Documentation and maintainer tooling only. **No application or configuration changes** for bundle consumers.

Adds GitHub Spec Kit baseline (`specs/001-baseline/`), Cursor skills, and [`SPEC-KIT.md`](SPEC-KIT.md). Upgrade only if you want the latest docs in `vendor/nowo-tech/yopass-bundle/docs/`.

```bash
composer update nowo-tech/yopass-bundle
```

## 1.2.1 (2026-07-07)

Patch release. **Required if you upgraded to 1.2.0** and hit container build errors or custom repository fatals.

### Fixes included

- **`PublicEndpointRateLimiter`** autowiring — Symfony apps no longer fail with “Cannot autowire … `$limit`” on `cache:clear` / container compile.
- **Custom repository decorators** — if you wrap `ShareRepositoryInterface` (e.g. offloading file ciphertext to disk/S3), implement `consumeReadIfAvailable()` by delegating to the inner repository, then hydrate references if needed (see demo `LocalOffloadingShareRepository`).

### Upgrade steps

```bash
composer update nowo-tech/yopass-bundle
php bin/console cache:clear
```

No schema or config changes required.

## 1.2.0 (2026-07-05)

Minor release. **No breaking changes** for standard ORM/MongoDB installs. Custom repository implementations must add one new method (see below).

### Public rate limiting

Anonymous `/share/*` routes are rate limited per client IP when enabled (default **on**):

```yaml
nowo_yopass:
    public_rate_limit:
        enabled: true
        limit: 60
        interval_seconds: 60
```

Requires Symfony **`cache.app`**. If your app has no cache pool, set `enabled: false` to restore previous behaviour (no limiting).

### Custom `ShareRepositoryInterface`

If you implement a **custom** repository (`database.driver: custom`), add:

```php
public function consumeReadIfAvailable(string $id): ?SecureShare;
```

Return the updated share after atomically decrementing `reads_left`, or `null` when the share is missing or not consumable. Built-in ORM and MongoDB repositories already implement this.

### Translations

New locales ship with the bundle: **de**, **fr**, **it**, **nl**, **pt** (domain `NowoYopassBundle`). No config change required — Symfony picks them up from the request locale.

### Upgrade steps

```bash
composer update nowo-tech/yopass-bundle
php bin/console cache:clear
```

No schema migration required.

## 1.1.0 (2026-07-04)

Minor release. **No breaking changes** — existing apps keep creator-only list and access behaviour unless you register event listeners.

### Share list and per-share access events

New optional integration points for teams, grants, and role-based rules:

| Event | When |
|-------|------|
| `ShareListQueryEvent` | Before the manage list query |
| `ShareListResultEvent` | After shares are loaded |
| `ShareAccessCheckEvent` | Before preview/extend/revoke/delete/created view |

Copy example listeners from `examples/access-control/` and register them in your `services.yaml`. Full reference: [examples/AccessControl.md](examples/AccessControl.md).

**Layers:**

1. `YopassAccessCheckerInterface` — route-level create/list/revoke (unchanged).
2. Share events — which shares appear and who may act on each share (new).

`delete all` still removes only shares **created by** the current user. Override in your app if team admins need bulk delete.

### Upgrade steps

```bash
composer update nowo-tech/yopass-bundle
php bin/console cache:clear
```

No schema or config changes required.

## 1.0.1 (2026-07-04)

Patch release. No application code changes required.

### Symfony 8 + Doctrine

If you run Symfony 8, ensure **`doctrine/doctrine-bundle` ^3.0** is installed. Version 2.x does not support Symfony 8:

```bash
composer require doctrine/doctrine-bundle:^3.0
```

The bundle already allows `^2.10 || ^3.0` in `composer.json`; Composer resolves the correct major for your Symfony version.

### Assets

After upgrading, reinstall public assets if you vendor-copy them:

```bash
php bin/console assets:install
```

## 1.0.0 (2026-07-04)

First stable release. No upgrade steps when installing for the first time.

### Requirements

- **PHP:** >= 8.2, < 8.6 with `ext-sodium`
- **Symfony:** ^7.4 || ^8.0
- **Doctrine Bundle:** ^2.10 (Symfony 7.x) or ^3.0 (Symfony 8.x)
- **Doctrine ORM:** ^2.15 || ^3.0 (unless using MongoDB ODM or a custom repository)

### Install

```bash
composer require nowo-tech/yopass-bundle
php bin/console assets:install
php bin/console doctrine:schema:update --force
# or create a migration
```

Configure `user_class`, security `access_control` for `/share` (public) and manage routes (authenticated). See [INSTALLATION.md](INSTALLATION.md).

### Public links

One-click links use the query parameter **`decrypt_key`**:

```
/share/{uuid}?decrypt_key=BASE64URL_KEY
```

Short links omit the parameter; recipients paste the key in the reveal page. Legacy `#fragment` and `?key=` URLs are still read by the browser bundle but are no longer generated.
