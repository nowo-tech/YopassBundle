# Configuration

All options live under the `nowo_yopass` root key in `config/packages/nowo_yopass.yaml`.

## Table of contents

- [Required](#required)
- [Database](#database)
  - [PostgreSQL / MySQL / MariaDB / SQLite / SQL Server / Oracle](#postgresql--mysql--mariadb--sqlite--sql-server--oracle)
  - [MongoDB](#mongodb)
  - [Custom backends (Redis, Couchbase, etc.)](#custom-backends-redis-couchbase-etc)
- [File shares (optional)](#file-shares-optional)
- [Share options](#share-options)
- [Sharing (encryption & links)](#sharing-encryption--links)
  - [Public link formats](#public-link-formats)
- [Access log](#access-log)
- [Routes](#routes)
- [Security](#security)
  - [Firewall](#firewall)
  - [Access checker](#access-checker)
  - [Share list and per-share access events](#share-list-and-per-share-access-events)
  - [Public rate limiting](#public-rate-limiting)
  - [Custom access checker interface](#custom-access-checker-interface)
- [Web UI (UiKit page contract)](#web-ui-uikit-page-contract)
  - [Host layout integration](#host-layout-integration)
- [Other options](#other-options)
- [Assets](#assets)
- [DevKit integration (future)](#devkit-integration-future)

## Required

| Option | Description |
|--------|-------------|
| `user_class` | FQCN of your User entity (`UserInterface` + `getId()`). Used for the `creator` relation on shares. |

## Database

Configure which connection and persistence backend store encrypted shares.

| Option | Default | Description |
|--------|---------|-------------|
| `table_prefix` | `yopass_` | Prefix for table/collection name. Final name: `{prefix}secure_shares`. |
| `database.driver` | `doctrine_orm` | `doctrine_orm`, `doctrine_mongodb`, or `custom`. |
| `database.platform` | `postgresql` | Documented platform: `postgresql`, `mysql`, `mariadb`, `sqlite`, `sqlserver`, `oracle`, `mongodb`, `other`. |
| `database.connection` | `default` | Doctrine DBAL connection name from `config/packages/doctrine.yaml`. |
| `database.entity_manager` | `default` | Doctrine ORM entity manager for relational databases. |
| `database.document_manager` | `default` | Doctrine MongoDB document manager when using MongoDB. |
| `database.collection` | `null` | MongoDB collection override (defaults to `{table_prefix}secure_shares`). |
| `database.repository` | `null` | Custom service id implementing `ShareRepositoryInterface` when `driver: custom`. |

### PostgreSQL / MySQL / MariaDB / SQLite / SQL Server / Oracle

Use Doctrine ORM with your existing DBAL connection:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        # PostgreSQL example:
        # DATABASE_URL="postgresql://app:app@db:5432/app?serverVersion=16&charset=utf8"

nowo_yopass:
    database:
        driver: doctrine_orm
        platform: postgresql
        connection: default
        entity_manager: default
    table_prefix: yopass_
```

Use a dedicated connection for shares:

```yaml
doctrine:
    dbal:
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            yopass:
                url: '%env(resolve:YOPASS_DATABASE_URL)%'
    orm:
        entity_managers:
            yopass:
                connection: yopass
                mappings: { ... }

nowo_yopass:
    database:
        connection: yopass
        entity_manager: yopass
```

### MongoDB

Requires `doctrine/mongodb-odm-bundle`:

```bash
composer require doctrine/mongodb-odm-bundle
```

```yaml
nowo_yopass:
    database:
        driver: doctrine_mongodb
        platform: mongodb
        connection: default
        document_manager: default
        collection: yopass_secure_shares
```

### Custom backends (Redis, Couchbase, etc.)

Implement `ShareRepositoryInterface` and register it:

```yaml
nowo_yopass:
    database:
        driver: custom
        platform: other
        repository: App\Yopass\RedisShareRepository
```

Run `doctrine:schema:update` or a migration after install (ORM). For MongoDB, ensure indexes/collection exist via your ODM setup.

## File shares (optional)

File uploads are **disabled by default**. Enable them only when you register a service implementing `ShareFileHandlerInterface`:

```yaml
# config/services.yaml
services:
    Nowo\YopassBundle\Service\DefaultShareFileHandler: ~

# config/packages/nowo_yopass.yaml
nowo_yopass:
    file_handler: Nowo\YopassBundle\Service\DefaultShareFileHandler
```

Without `file_handler`, the manage UI shows text shares only and the create API rejects `payloadKind: file`. Any `payloadKind` other than `text` or `file` fails form validation.

The Symfony 8 demo enables local file storage by default ([Local storage example](examples/LocalStorage.md)). For AWS S3, see [S3 example](examples/S3.md).

## Share options

Control which expiration and max-read values appear in the manage UI and are accepted by the create API:

```yaml
nowo_yopass:
    shares:
        default_expiration: 1h
        default_max_reads: 1
        max_reads_options: [1, 3, 10]
        expiration_options:
            - { id: 1h, interval: '1 hour' }
            - { id: 24h, interval: '24 hours' }
            - { id: 7d, interval: '7 days' }
```

| Option | Default | Description |
|--------|---------|-------------|
| `shares.default_expiration` | `1h` | Pre-selected expiration id (must exist in `expiration_options`). |
| `shares.default_max_reads` | `1` | Pre-selected max reads (must exist in `max_reads_options`). |
| `shares.max_reads_options` | `[1, 3, 10]` | Allowed max-read values (1–1000). |
| `shares.expiration_options` | see above | Each entry: `id` (API/UI value) and `interval` (PHP relative modifier, e.g. `48 hours`). |
| `shares.list_page_size` | `20` | Shares per page in manage list (`0` = no pagination). |
| `shares.retention.enabled` | `true` | Purge shares older than `max_age` (UI + console command). |
| `shares.retention.max_age` | `1 month` | PHP relative modifier (e.g. `30 days`). |

Add translation keys `yopass.expires.{id}` under `NowoYopassBundle` for custom expiration ids.

Invalid `expiresIn` / `maxReads` in the create API fall back to the configured defaults.

## Sharing (encryption & links)

```yaml
nowo_yopass:
    sharing:
        default_encryption: auto       # auto | password
        allow_custom_password: true
        default_embed_in_url: false    # true: list the one-click link first on the "share created" page
        allow_embed_in_url: true       # false: no one-click link at all (short link + key only)
        show_remember_notice: true
```

| Option | Default | Description |
|--------|---------|-------------|
| `sharing.default_encryption` | `auto` | Default mode in create form. |
| `sharing.allow_custom_password` | `true` | Show password encryption option. |
| `sharing.default_embed_in_url` | `false` | When true (and embedding is allowed), the "share created" page lists the one-click link first. With false it comes after the short link and the key. |
| `sharing.allow_embed_in_url` | `true` | When true, the created page offers a one-click link with the key in the URL fragment (`#decrypt_key=`, never sent to the server) and the manage preview embeds the stored key in its public link. When false, only the short link and the key are shown (send them on separate channels). Up to 1.8.0 both options had no effect. |
| `sharing.show_remember_notice` | `true` | Post-create security reminder. |

### Public link formats

| Type | Example | Notes |
|------|---------|-------|
| Short | `/share/{uuid}` | Recipient enters key manually on reveal page. |
| One-click | `/share/{uuid}#decrypt_key=BASE64URL` | Fragment, never sent to the server. Auto-reveal. Legacy raw `#key` still read. Legacy query links (`?decrypt_key=`, `?key=`, `?password=`) are redirected `303` to the fragment form before routing. |

## Access log

```yaml
nowo_yopass:
    access_log:
        enabled: true   # requires Doctrine ORM
```

When enabled, successful public consumes are logged for the share creator (preview modal history).

## Routes

Each route has `path` and `name`. Optional `web_ui.path_prefix` (deprecated alias: `route_prefix`) is prepended to every path, manage and public (e.g. `/{_locale}`).

| Key | Default path | Default name |
|-----|--------------|--------------|
| `routes.manage` | `/tools/yopass` | `nowo_yopass_index` |
| `routes.create` | `/tools/yopass/create` | `nowo_yopass_create` |
| `routes.created` | `/tools/yopass/{id}/created` | `nowo_yopass_created` |
| `routes.preview` | `/tools/yopass/{id}/preview` | `nowo_yopass_preview` |
| `routes.extend` | `/tools/yopass/{id}/extend` | `nowo_yopass_extend` |
| `routes.revoke` | `/tools/yopass/{id}/revoke` | `nowo_yopass_revoke` |
| `routes.delete` | `/tools/yopass/{id}/delete` | `nowo_yopass_delete` |
| `routes.delete_all` | `/tools/yopass/delete-all` | `nowo_yopass_delete_all` |
| `routes.public_show` | `/share/{id}` | `nowo_yopass_public_share` |
| `routes.public_consume` | `/share/{id}/consume` | `nowo_yopass_public_consume` |

Import routes:

```yaml
# config/routes/nowo_yopass.yaml
nowo_yopass:
    resource: .
    type: nowo_yopass
```

## Security

### Firewall

Manage routes require an authenticated user on your main firewall. Public `/share/*` routes must allow anonymous access:

```yaml
# config/packages/security.yaml (example)
security:
    firewalls:
        main:
            # ...
    access_control:
        - { path: ^/share, roles: PUBLIC_ACCESS }
        - { path: ^/tools/yopass, roles: ROLE_ADMIN }
```

Replace `ROLE_ADMIN` with the roles you configure under `security.access_roles` (defaults to `ROLE_ADMIN` since 1.3.0).

### Access checker

Replace the default role-based checker with your own service implementing `YopassAccessCheckerInterface` (e.g. team-based ACL in DevKit):

```yaml
nowo_yopass:
    security:
        access_checker: App\Security\DevKitYopassAccessChecker
```

Default role configuration (REQ-UI-002 / REQ-UI-003 vocabulary):

| Option | Default | Purpose |
|--------|---------|---------|
| `security.access_roles` | `[ROLE_ADMIN]` | Open the manage UI |
| `security.roles.admin` | `[ROLE_ADMIN]` | Full access bypass (every feature) |
| `security.roles.create` | `[ROLE_ADMIN]` | Create shares |
| `security.roles.list` | `[ROLE_ADMIN]` | List and preview own shares |
| `security.roles.revoke` | `[ROLE_ADMIN]` | Revoke, extend and delete own shares |
| `security.access_checker` | `null` | Service implementing `YopassAccessCheckerInterface` (replaces the role checker) |
| `security.allow_unauthenticated` | `false` | DEV/DEMO only: skip SecurityBundle requirement (never enable in production) |

An empty list grants that permission to nobody (only `security.roles.admin` bypasses it). With the built-in role checker
and the Web UI on, the container refuses to compile when **every** list (`access_roles` and all `roles.*`) is empty,
because nobody could ever open the manage UI.

**Deprecated since 1.10** (mapped, removed in 2.0): `security.admin_roles` → `security.roles.admin`,
`security.create_roles` → `security.roles.create`, `security.list_roles` → `security.roles.list`,
`security.revoke_roles` → `security.roles.revoke`. When both are set, the canonical key wins.

When `allow_unauthenticated` is `false` (default), the bundle requires **Symfony SecurityBundle** at compile time. Set it to `true` only in local demos without authentication.

Host firewall example (adjust roles to match your config):

```yaml
# config/packages/security.yaml (example)
security:
    access_control:
        - { path: ^/share, roles: PUBLIC_ACCESS }
        - { path: ^/tools/yopass, roles: ROLE_ADMIN }
```

The Symfony 8 demo keeps `ROLE_USER` in `demo/symfony8/config/packages/nowo_yopass.yaml` so auto-login continues to work with the demo user.

### Share list and per-share access events

The bundle stores the share **creator** but does not implement teams, groups, or per-share ACLs. Listen to Symfony events to customize:

| Event | Constant | Purpose |
|-------|----------|---------|
| `ShareListQueryEvent` | `YopassEvents::SHARE_LIST_QUERY` | Change list query or replace results |
| `ShareListResultEvent` | `YopassEvents::SHARE_LIST_RESULT` | Filter/reorder listed shares |
| `ShareAccessCheckEvent` | `YopassEvents::SHARE_ACCESS_CHECK` | Grant/deny preview, extend, revoke, delete on a share |

Default per-share grant: viewer is the creator. Copy example listeners from [`examples/access-control/`](../examples/access-control/) — see [Access control events](examples/AccessControl.md).

Services involved: `ShareLister` (manage list), `ShareAccessGuard` (created/preview/extend/revoke/delete).

### Public rate limiting

Anonymous `/share/*` routes can be rate limited per client IP:

```yaml
nowo_yopass:
    public_rate_limit:
        enabled: true          # default true
        limit: 60              # requests per window
        interval_seconds: 60   # window length
```

Requires Symfony **`cache.app`** (FrameworkBundle cache), injected as an optional reference. When cache is unavailable (a compile-time warning is emitted) or `enabled: false`, limiting is skipped. See [Security](SECURITY.md).

### Custom access checker interface

```php
interface YopassAccessCheckerInterface
{
    public function canAccess(?UserInterface $user = null): bool;
    public function canCreate(?UserInterface $user = null): bool;
    public function canList(?UserInterface $user = null): bool;
    public function canRevoke(?UserInterface $user = null): bool;
}
```

## Web UI (UiKit page contract)

The pages are on the **UiKit page contract** (REQ-UI-003, [UiKitBundle](https://github.com/nowo-tech/UiKitBundle)
`^2.15.4`): the manage page extends the UiKit **list scaffold**, the "share created" page the **show scaffold**, and the
public reveal page is a unique page on the UiKit **guest layout**. The bundle ships no layout, no CSS framework and no
CDN: the host theme (UiKit `nowo_ui_kit.theme`, the host layout and the host form theme) styles every page. See
[Templates](TEMPLATES.md) for pages, view models and data blocks.

```yaml
nowo_ui_kit:
    css_framework: bootstrap5            # bootstrap5 | bootstrap4 | tailwind | foundation | tabler | custom | none
    icon_set: bootstrap-icons            # bootstrap-icons | tabler-icons | ux_icon | svg_inline | none
    row_actions_display: icon            # icon | text | icon_text
    layouts:
        admin: 'layouts/admin.html.twig' # your admin layout or bridge (renders the canonical nowo_ui_* blocks)
        guest: 'layouts/guest.html.twig' # your public layout or bridge (the reveal page)

nowo_yopass:
    web_ui:
        enabled: true
        path_prefix: ''                  # prefix of every bundle route (e.g. /{_locale})
        layout_template: ~               # null = nowo_ui_kit.layouts.admin
        public_layout_template: ~        # null = nowo_ui_kit.layouts.guest
        templates:
            manage: ~                    # null = @NowoYopassBundle/manage/index.html.twig
            created: ~                   # null = @NowoYopassBundle/manage/created.html.twig
            public: ~                    # null = @NowoYopassBundle/public/reveal.html.twig
```

| Option | Default | Description |
|--------|---------|-------------|
| `web_ui.enabled` | `true` | Manage Web UI feature flag. When false, only the public routes (`/share/{id}`, `/share/{id}/consume`) are registered and the CSRF / SecurityBundle compile checks of the manage UI are skipped. When true, access is enforced by `security.*` and the host firewall. |
| `web_ui.path_prefix` | `''` | Prefix prepended to every bundle route path (manage and public). Replaces `route_prefix`. |
| `web_ui.layout_template` | `null` | Layout the manage pages extend (`page.layout`). Null = `nowo_ui_kit.layouts.admin`. **Set the project admin layout in UiKit once** (`nowo_ui_kit.layouts.admin`); use this key only for a Yopass-specific layout. A 1.x layout without the UiKit canonical blocks (e.g. a `base.html.twig` with `body`) is wrapped in the deprecated `_legacy_layout.html.twig` bridge. The 1.x default `@NowoYopassBundle/layout.html.twig` maps to null. |
| `web_ui.public_layout_template` | `null` | Layout of the public reveal page. Null = `nowo_ui_kit.layouts.guest`. |
| `web_ui.templates.<page>` | `null` | Per-page template override (`manage`, `created`, `public`): a host template that `{% extends '@!NowoYopassBundle/...' %}` and overrides one data block. |
| `web_ui.css_framework` | `null` | **Deprecated since 1.10**: set `nowo_ui_kit.css_framework`. When set (and `nowo_ui_kit.css_framework` is not), it is copied to UiKit (`bootstrap` → `bootstrap5`). |
| `web_ui.icon_set` | `null` | **Deprecated since 1.10**: set `nowo_ui_kit.icon_set`. When set (and `nowo_ui_kit.icon_set` is not), it is copied to UiKit. |

**Deprecated root keys (1.10, removed in 2.0):** `route_prefix` → `web_ui.path_prefix`; `templates.layout` →
`web_ui.layout_template`; `templates.manage` / `templates.created` / `templates.public` → `web_ui.templates.<page>`.
The canonical key wins when both are set.

### Host layout integration

Point `nowo_ui_kit.layouts.admin` / `guest` at your layouts **once** for every Nowo bundle. A host layout either
extends `@NowoUiKit/layout/admin.html.twig` / `guest.html.twig`, or `{% use '@NowoUiKit/layout/_bridge.html.twig' %}`
inside the project shell (reference: `demo/symfony8/templates/layouts/admin.html.twig`):

```twig
{# templates/layouts/admin.html.twig #}
{% extends 'base.html.twig' %}
{% use '@NowoUiKit/layout/_bridge.html.twig' %}

{% block title %}{{ block('nowo_ui_bridge_title') }}{% endblock %}
{% block stylesheets %}{{ parent() }}<link rel="stylesheet" href="{{ asset('css/nowo-ui.css', 'nowo_ui_kit') }}">{{ nowo_ui_tokens() }}{{ block('nowo_ui_bridge_assets') }}{% endblock %}
{% block body %}{{ block('nowo_ui_bridge_body') }}{% endblock %}
{% block javascripts %}{{ parent() }}{{ include(nowo_ui_partial('scripts')) }}{{ block('nowo_ui_bridge_end') }}{% endblock %}
```

The pages fill `nowo_ui_document_title`, `nowo_ui_page_header`, `nowo_ui_content`, `nowo_ui_modals` and stack the
bundle script in `nowo_ui_scripts` with `{{ parent() }}` (asset package `nowo_yopass`, `js/yopass.js`), so host and
UiKit scripts keep loading. The UiKit runtime scripts (`dialog`, `confirm`, …) must be loaded by the layout (the UiKit
layouts and the `scripts` partial do it): the View / Extend row actions open the preview `<dialog>` and Revoke /
Delete / Delete all open the UiKit confirm `<dialog>`.

**CSS stack examples** (the bundle markup carries only `nowo-ui-*` semantic classes, bundle hooks `nowo-yopass-*` and
UiKit theme slots, so switching the stack is one UiKit key):

```yaml
# Bootstrap 5 (or Tabler) host
nowo_ui_kit: { css_framework: bootstrap5, icon_set: bootstrap-icons }
twig: { form_themes: ['bootstrap_5_layout.html.twig'] }   # host form theme for the fields

# Tailwind host
nowo_ui_kit: { css_framework: tailwind, icon_set: svg_inline }

# Foundation host
nowo_ui_kit: { css_framework: foundation, icon_set: none }

# Own design system: nowo-ui-* classes + --nowo-ui-* tokens only
nowo_ui_kit:
    css_framework: custom
    icon_set: svg_inline
    row_actions_display: text
    theme:
        tokens: { primary: 'var(--brand)', radius: '0.25rem' }
        bundles:
            NowoYopassBundle: { classes: { table: 'data-table data-table--dense' } }
```

Form fields carry the `nowo-ui-input` hook (FormKit profile `yopass`, which the host may redefine); the host form
theme (`twig.form_themes` or the FormKit `css_framework`) adds the framework classes.

## Other options

| Option | Default | Description |
|--------|---------|-------------|
| `max_ciphertext_bytes` | `700000` | Max POST body ciphertext size |
| `max_secret_chars` | `524288` (512 KiB) | Max characters in the text secret field (multiline allowed) |
| `dashboard_route` | `null` | Route name for “back” link in manage UI |
| `firewall` | `main` | Documented firewall name for host apps |
| `public_firewall_paths` | `[^/share]` | Documented public path patterns |

## Assets

The bundle ships Stimulus controllers built to `src/Resources/public/js/yopass.js` (asset package `nowo_yopass`).

Rebuild after changes:

```bash
pnpm install && pnpm run build
```

## DevKit integration (future)

When wiring into `nowo-devkit`, point `security.access_checker` to a service wrapping `TeamAccessChecker` and set `table_prefix: vault_` to preserve `vault_secure_shares`.
