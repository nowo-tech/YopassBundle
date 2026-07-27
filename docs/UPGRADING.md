# Upgrading

This document describes how to upgrade between versions of Yopass Bundle.

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

## Unreleased / 1.x

Breaking or notable changes in future 1.x releases will be documented here.
