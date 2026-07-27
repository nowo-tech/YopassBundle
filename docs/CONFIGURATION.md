# Configuration

All options live under the `nowo_yopass` root key in `config/packages/nowo_yopass.yaml`.

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

Without `file_handler`, the manage UI shows text shares only and the create API rejects `payloadKind: file`.

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
        default_embed_in_url: true     # one-click link includes ?decrypt_key=
        allow_embed_in_url: true
        show_remember_notice: true
```

| Option | Default | Description |
|--------|---------|-------------|
| `sharing.default_encryption` | `auto` | Default mode in create form. |
| `sharing.allow_custom_password` | `true` | Show password encryption option. |
| `sharing.default_embed_in_url` | `true` | Documented default for one-click links. |
| `sharing.allow_embed_in_url` | `true` | When false, only short links are offered. |
| `sharing.show_remember_notice` | `true` | Post-create security reminder. |

### Public link formats

| Type | Example | Notes |
|------|---------|-------|
| Short | `/share/{uuid}` | Recipient enters key manually on reveal page. |
| One-click | `/share/{uuid}?decrypt_key=BASE64URL` | Auto-reveal; legacy `#`, `?key=`, `?password=` still read. |

## Access log

```yaml
nowo_yopass:
    access_log:
        enabled: true   # requires Doctrine ORM
```

When enabled, successful public consumes are logged for the share creator (preview modal history).

## Routes

Each route has `path` and `name`. Optional `route_prefix` is prepended to every path (e.g. `/admin`).

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

Default role configuration:

| Option | Default | Purpose |
|--------|---------|---------|
| `security.admin_roles` | `[ROLE_ADMIN]` | Full access bypass |
| `security.access_roles` | `[ROLE_ADMIN]` | Open manage UI |
| `security.create_roles` | `[ROLE_ADMIN]` | Create shares |
| `security.list_roles` | `[ROLE_ADMIN]` | List own shares |
| `security.revoke_roles` | `[ROLE_ADMIN]` | Revoke own shares |
| `security.allow_unauthenticated` | `false` | DEV/DEMO only: skip SecurityBundle requirement (never enable in production) |

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

Requires Symfony **`cache.app`** (FrameworkBundle cache). When cache is unavailable or `enabled: false`, limiting is skipped. See [Security](SECURITY.md).

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

## Web UI (manage pages)

Configure look-and-feel and host layout integration (REQ-UI-001):

```yaml
nowo_yopass:
    web_ui:
        enabled: true
        layout_template: 'base.html.twig'   # your project layout
        css_framework: bootstrap5           # bootstrap | bootstrap4 | bootstrap5 | tailwind | foundation | custom | tabler | none
        icon_set: bootstrap-icons           # bootstrap-icons | tabler-icons | ux_icon | svg_inline | none
```

| Option | Default | Description |
|--------|---------|-------------|
| `web_ui.enabled` | `true` | Manage Web UI feature flag. Access is still enforced by `security.access_roles` and the host firewall. |
| `web_ui.layout_template` | `@NowoYopassBundle/layout.html.twig` | Layout extended by manage pages (Twig global `nowo_yopass_layout_template`). |
| `web_ui.css_framework` | `tabler` | CSS stack for macros and optional CDN in the bundle default layout. |
| `web_ui.icon_set` | `tabler-icons` | Icon set for row actions when using bundle markup. |

**Canonical key:** `web_ui.layout_template`. The legacy `templates.layout` key remains as a BC alias: if you only customize `templates.layout`, it is used when `web_ui.layout_template` is still the bundle default.

### Host layout integration

Manage pages extend `nowo_yopass_layout_template` and call `{{ parent() }}` in `stylesheets` / `javascripts` so host assets stack with bundle assets.

**Bootstrap 5 host app:**

```yaml
nowo_yopass:
    web_ui:
        layout_template: 'base.html.twig'
        css_framework: bootstrap5
        icon_set: bootstrap-icons
```

**Tailwind host app:**

```yaml
nowo_yopass:
    web_ui:
        layout_template: 'layouts/admin.html.twig'
        css_framework: tailwind
        icon_set: svg_inline
```

**Foundation host app:**

```yaml
nowo_yopass:
    web_ui:
        layout_template: 'admin.html.twig'
        css_framework: foundation
        icon_set: none
```

**Custom design system (`nowo-ui-*` semantic classes only):**

```yaml
nowo_yopass:
    web_ui:
        layout_template: 'base.html.twig'
        css_framework: custom
        icon_set: svg_inline
```

When your project layout uses a different content block name, add a thin bridge template (see `@NowoYopassBundle/layout_integrate_host.html.twig`) and point `layout_template` at it.

The public reveal page (`public/reveal.html.twig`) stays standalone and does not use the manage layout.

## Templates

Override via `templates/bundles/NowoYopassBundle/` or config:

```yaml
nowo_yopass:
    templates:
        layout: '@NowoYopassBundle/layout.html.twig'   # BC alias for web_ui.layout_template
        manage: '@NowoYopassBundle/manage/index.html.twig'
        public: '@NowoYopassBundle/public/reveal.html.twig'
```

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
