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
        - { path: ^/tools/yopass, roles: ROLE_USER }
```

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
| `security.access_roles` | `[ROLE_USER]` | Open manage UI |
| `security.create_roles` | `[ROLE_USER]` | Create shares |
| `security.list_roles` | `[ROLE_USER]` | List own shares |
| `security.revoke_roles` | `[ROLE_USER]` | Revoke own shares |

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

## Templates

Override via `templates/bundles/NowoYopassBundle/` or config:

```yaml
nowo_yopass:
    templates:
        layout: '@NowoYopassBundle/layout.html.twig'
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
