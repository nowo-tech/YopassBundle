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
- [Security headers](#security-headers)
- [Content-Security-Policy](#content-security-policy)
- [Routes](#routes)
- [Security](#security)
  - [Firewall](#firewall)
  - [Access checker](#access-checker)
  - [Share list and per-share access events](#share-list-and-per-share-access-events)
  - [Share lifecycle events](#share-lifecycle-events)
  - [Public rate limiting](#public-rate-limiting)
  - [Custom access checker interface](#custom-access-checker-interface)
- [Web UI (UiKit page contract)](#web-ui-uikit-page-contract)
  - [Create mode](#create-mode)
  - [Host layout integration](#host-layout-integration)
- [Other options](#other-options)
- [Assets](#assets)
- [DevKit integration (future)](#devkit-integration-future)

## Required

| Option | Description |
|--------|-------------|
| `user_class` | FQCN of your User entity (`UserInterface` + `getId()`). Used for the `creator` relation on shares (`ON DELETE CASCADE`: deleting a user deletes their shares; their access-log rows stay, see [Access log](#access-log)). The security user may be another class exposing the same `getId()` when listed in `accepted_user_classes` (since 1.16). |
| `accepted_user_classes` | Since 1.16; default `[]`. Security-user classes other than `user_class` that map to a share creator by `getId()` (wrappers / read models carrying the `user_class` id). With the default only `user_class` instances create, list, bulk-delete and own shares; any other user is refused (403), so a second identity domain with overlapping ids (Admin#5 / Customer#5) fails closed. Ids ≤ 0 and empty ids are refused. |

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
| `shares.retention.max_age` | `1 month` | PHP relative modifier (e.g. `30 days`). Every `<N> <unit>` term goes back in time: `1 month 2 days` is one month and two days. The cutoff must be in the past and after the year 1000: `-14 days`, `1 month ago`, `0 days` or `100000 years` fail the container build. Not an environment variable: the build validates the value. |

Add translation keys `yopass.expires.{id}` under `NowoYopassBundle` for custom expiration ids.

**Retention notice.** The manage list prints `shares.retention.max_age` as a translated duration when it has the form
`N minutes|hours|days|weeks|months|years` (singular or plural, optional `+`): `14 days` reads `14 días` in Spanish. Any
other modifier the purge accepts (`1 month 2 days`, `1 fortnight`) prints `yopass.list.retention_notice_unspecified`,
which names no duration, and so does a PHP without `ext-intl` or `symfony/polyfill-intl-messageformatter`. The notice is
display only. The units are ICU messages
(`yopass.list.retention_duration.<unit>`) in `NowoYopassBundle+intl-icu.<locale>.yaml`.

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

**The read audit trail is never deleted with its share (1.16).** `share_id` is `ON DELETE SET NULL`: deleting a share
(manage delete, delete all, retention purge, or the deletion of its creator, which deletes the creator's shares) keeps
every log row with `share_id` NULL. Each row also stores `share_ref`, the SHA-256 (hex) of the share id: it keeps the
rows of one share together and lets an auditor match a share id they already know
(`DoctrineOrmShareAccessLogRepository::findByShareId()`), without storing the link path. Rows keep the client IP and
user agent: include the table in your own retention / personal-data policy. Migration:
[UPGRADING › To 1.16.0](UPGRADING.md#to-1160).

When disabled (or with the MongoDB / custom non-relational backends, which always disable it) the
`ShareAccessLog` entity is **not mapped**: it is excluded from the Doctrine ORM attribute drivers, so no
`<table_prefix>_share_access_logs` table is created. See [UPGRADING](UPGRADING.md#access-log-table-no-longer-mapped-when-disabled)
for an existing empty table.

## Security headers

```yaml
nowo_yopass:
    security_headers:
        enabled: true   # default
```

Every bundle route (manage, create, created, preview, extend, revoke, delete, delete all, reveal, consume) sends
`Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`,
`X-Frame-Options: DENY` and CSP `frame-ancestors 'none'` (appended to the host policy when it has no `frame-ancestors`,
or sent alone without a policy). Report-Only policies are left alone. Only disable it when the host sends equivalent
headers on these routes.

## Content-Security-Policy

The pages that load `js/yopass.js` (manage list, "share created", public reveal) run libsodium, which compiles
WebAssembly: under a CSP they need **`'wasm-unsafe-eval'`** in `script-src`. The bundle never adds it on its own.

- **Nonce.** The `<script type="module">` of `yopass.js` gets `nonce="…"` from the request attribute `csp_nonce`
  (the same hook the UiKit layouts read). Set it in your CSP listener (`$request->attributes->set('csp_nonce', $nonce)`)
  and `script-src 'nonce-…' 'strict-dynamic'` policies accept the module; without it the tag has no nonce.
- **Scope `'wasm-unsafe-eval'` to the three pages.** Their route names are in the container parameter
  `nowo_yopass.csp.wasm_routes` (defaults `nowo_yopass_index`, `nowo_yopass_created`, `nowo_yopass_public_share`;
  values follow `routes.manage.name`, `routes.created.name`, `routes.public_show.name`). Compare them with
  `$request->attributes->get('_route')` in your own CSP listener, or let the bundle do it:

```yaml
nowo_yopass:
    csp:
        wasm_unsafe_eval: false   # opt-in
```

With `true`, `WasmCspListener` (kernel.response, priority `-128`, after NelmioSecurityBundle / host listeners) edits
the existing `Content-Security-Policy` and `Content-Security-Policy-Report-Only` headers of those routes only:
`'wasm-unsafe-eval'` is appended to `script-src`, or a `script-src` copying `default-src` is added when only
`default-src` exists. It never creates a policy, never touches other routes, leaves `'none'` / empty script policies
alone and does nothing when `'wasm-unsafe-eval'` or `'unsafe-eval'` is already allowed. `'unsafe-eval'` is never added.

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
| `ShareDeleteAllEvent` | `YopassEvents::SHARE_DELETE_ALL` | Scope (`setSubject()`), take over (`markHandled()`) or `veto()` the manage "delete all" |

**Row actions follow these grants:** the manage list asks `SHARE_ACCESS_CHECK` once per row and action (`Preview` →
View, `Extend`, `Revoke`, `Delete`) and renders only the granted actions (revoke / delete CSRF forms only for those
rows), on top of the page-wide `security.roles.*` flags. The routes keep their own server-side checks. Do not grant
`View` (the "share created" page) to other users: it reads the key from the creator's `sessionStorage`.

**"Delete all"** is shown only when the viewer owns shares: `ShareListQueryEvent::setOwnTotal($n)` from a listener,
else the default query's total, else `countByCreator($viewer)`.

**Host columns:** `YopassEvents::SHARE_LIST_COLUMNS` (`ShareListColumnsEvent`, after the list is loaded) lets the host
append columns without overriding the template:

```php
#[AsEventListener(event: YopassEvents::SHARE_LIST_COLUMNS)]
public function __invoke(ShareListColumnsEvent $event): void
{
    $names = $this->creators->namesFor($event->getShares()); // batch lookup, host side
    $event->addColumn(new ShareListColumn(
        'createdBy',
        new Label('secrets.created_by', 'messages'),     // or a plain (escaped) string
        static fn (SecureShare $share): ?string => $names[$share->getId()] ?? null,
        priority: 2,                                     // hidden on narrow screens like the built-in dates
    ));
}
```

Keys are `[a-zA-Z][a-zA-Z0-9_]*` and may not reuse a built-in key. Values are plain text (scalar, `Stringable` or
null → "—") printed with Twig auto-escaping; never return secret material.

**The list on a host page:** `YopassEvents::SHARE_LIST_ROUTE` (`ShareListRouteEvent::setRoute($name, $params)`, with
`getViewer()` / `getRequest()`) sets the page that shows the list when a host renders it on its own page (forwarding
to the list controller). Allowed routes: the manage route plus `web_ui.list_routes`; parameters: scalars with string
keys (anything else throws). The pager, the filter form action and clear link, and the redirects after revoke / delete /
delete all use it. The action forms carry a hidden `_list` token (route name + parameters, HMAC-SHA256 with
`kernel.secret`, bound to the viewer's user identifier); the handlers accept it only when the signature matches the
current viewer and the route is still allowed, and the router builds the redirect URL. Anything else redirects to the
manage route. No URL is posted or accepted. The target page enforces its own access.

**Host filters:** `YopassEvents::SHARE_LIST_FILTERS` (`ShareListFiltersEvent::getFormBuilder()`) collects filter
fields into a GET form named `filters` (no CSRF: read-only search), rendered in the UiKit filters region. Its data is
`ShareListQueryEvent::getFilters()`; apply it in your `SHARE_LIST_QUERY` listener (`overrideList()`). Without fields
there is no filter form. The filter values are user input: validate them (prefer `ChoiceType`), bind them as query
parameters (never concatenate them into DQL / SQL / a MongoDB query), and return from `overrideList()` only shares the
viewer may see — `overrideList()` skips `CreatorSubjectGuard`, so the listener owns that check.

**Retention** (`shares.retention`) never runs on a list GET: the creator's old shares are purged when they create a
share, and every old share by the `nowo:yopass:purge-old-shares` command (schedule it). The `created_at` /
`expires_at` columns are indexed.

`ShareListQueryEvent` carries the requested page: `getPage()`, `getPageSize()` (0 = not paginated), `getOffset()`,
`isPaginated()`. An `overrideList($shares, $total)` listener should return that page only and the overall total.

`ShareDeleteAllEvent::getSubject()` (default: the viewer) is what `ShareRepositoryInterface::removeAllByCreator()`
receives. Only the viewer or another instance of `nowo_yopass.user_class` is accepted as subject (same rule for
`ShareListQueryEvent::setListSubject()`): the creator queries compare `creator = :subject`, and Doctrine would bind a
tenant / team entity by its id and match the **user** with that id. Any other subject throws a `LogicException`
before anything is queried or removed (fails closed: no share is deleted, the request errors so the misconfiguration
is visible). For tenant / team scopes remove the shares with your own query and call `markHandled($count)` (list:
`overrideList()`). After `veto()` nothing is removed and the user sees `yopass.error.delete_all_denied`; a `veto()`
after `markHandled()` is ignored (the shares are gone), logged as a warning, and the bulk-deleted event still fires,
so veto from a listener with a higher priority than the one that removes.

The manage list clamps `?page=` to `ShareListQueryEvent::MAX_PAGE` (1,000,000) and to the largest page whose offset
fits in an integer; a page beyond the last one renders the last page.

Default per-share grant: viewer is the creator. Copy example listeners from [`examples/access-control/`](../examples/access-control/) — see [Access control events](examples/AccessControl.md).

Services involved: `ShareLister` (manage list), `ShareAccessGuard` (created/preview/extend/revoke/delete),
`ShareDeleter` (delete / delete all).

### Share lifecycle events

| Event | Constant | When | Payload |
|-------|----------|------|---------|
| `ShareCreatedEvent` | `YopassEvents::SHARE_CREATED` | After `ShareCreator` persisted a share | share id, creator, created / expires at, max reads, payload kind |
| `ShareConsumedEvent` | `YopassEvents::SHARE_CONSUMED` | After a public link read was stored (atomic consume succeeded; never for a refused read). Since 1.17 | share id, creator id, read number, max reads, reads left, consumed at, payload kind (no IP address, no user agent) |
| `ShareDeletedEvent` | `YopassEvents::SHARE_DELETED` | After a manage "delete" | share id, creator, deleting user, dates, max reads, reads left, payload kind, revoked at |
| `ShareRevokedEvent` | `YopassEvents::SHARE_REVOKED` | After a manage "revoke" | share id, creator, revoking user, revoked at, payload kind |
| `ShareExtendedEvent` | `YopassEvents::SHARE_EXTENDED` | After a manage "extend" | share id, creator, extending user, old / new expiry, old / new max reads, payload kind |
| `ShareBulkDeletedEvent` | `YopassEvents::SHARES_BULK_DELETED` | After "delete all" or a retention purge removed ≥ 1 share | reason (`delete_all` / `retention`), subject (creator / tenant, `null` for the console purge), count, acting user |

**Zero-knowledge:** these payloads are metadata only. They never contain the ciphertext, the decryption key, a
password or decrypted content, and they do not expose the `SecureShare` entity, so they are safe to log or forward
(audit trail, notifications, metrics).

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
        create_mode: page                # page (create card) | dialog (New share opens a <dialog>)
        list_routes: []                  # host routes allowed to show the list (ShareListRouteEvent)
        icons:                           # create form Text / File tab icons (UiKit tabs item icon; ~ or '' = label only)
            create_tab_text: 'tabler:file-text'
            create_tab_file: 'tabler:file-upload'
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
| `web_ui.list_routes` | `[]` | Host routes allowed to show the manage list (`ShareListRouteEvent::setRoute()`); the manage route is always allowed. |
| `web_ui.icons.create_tab_text` / `web_ui.icons.create_tab_file` | `tabler:file-text` / `tabler:file-upload` | Icons of the create form's **Text** / **File** tabs (UiKit `tabs` item `icon`): a canonical UiKit kind or a prefixed name. A prefixed name renders only with an icon set that has it (`ux_icon`, or `tabler-icons` for `tabler:` / `bootstrap-icons` for `bi:`); any other set shows the label only. The host tab style (`data-tab-style`: `text`, `icon-text`, `icon`) picks what shows. `~` or `''` = label only. Checked when the container is built: `[prefix:]name` with lowercase letters, digits and single hyphens (the kits' shared grammar, as WikiBundle `web_ui.tab_icons`); anything else is an `InvalidConfigurationException`. |
| `web_ui.create_mode` | `page` | `page`: create card above the list, POST + redirect to the created page. `dialog`: **New share** (header + empty state) opens a `<dialog>`; the browser encrypts, posts with `fetch` and shows the links in the dialog. See [Create mode](#create-mode). |
| `web_ui.layout_template` | `null` | Layout the manage pages extend (`page.layout`). Null = `nowo_ui_kit.layouts.admin`. **Set the project admin layout in UiKit once** (`nowo_ui_kit.layouts.admin`); use this key only for a Yopass-specific layout. A 1.x layout without the UiKit canonical blocks (e.g. a `base.html.twig` with `body`) is wrapped in the deprecated `_legacy_layout.html.twig` bridge. The 1.x default `@NowoYopassBundle/layout.html.twig` maps to null. |
| `web_ui.public_layout_template` | `null` | Layout of the public reveal page. Null = `nowo_ui_kit.layouts.guest`. **Must not load third-party scripts** (they could read the decryption key and the decrypted secret); see [Usage — layout security](USAGE.md#public-reveal-page-layout-security). |
| `web_ui.templates.<page>` | `null` | Per-page template override (`manage`, `created`, `public`): a host template that `{% extends '@!NowoYopassBundle/...' %}` and overrides one data block. |
| `web_ui.css_framework` | `null` | **Deprecated since 1.10**: set `nowo_ui_kit.css_framework`. When set (and `nowo_ui_kit.css_framework` is not), it is copied to UiKit (`bootstrap` → `bootstrap5`). |
| `web_ui.icon_set` | `null` | **Deprecated since 1.10**: set `nowo_ui_kit.icon_set`. When set (and `nowo_ui_kit.icon_set` is not), it is copied to UiKit. |

**Deprecated root keys (1.10, removed in 2.0):** `route_prefix` → `web_ui.path_prefix`; `templates.layout` →
`web_ui.layout_template`; `templates.manage` / `templates.created` / `templates.public` → `web_ui.templates.<page>`.
The canonical key wins when both are set.

### Create mode

`web_ui.create_mode: dialog` keeps the zero-knowledge model of the page mode:

1. The browser encrypts the secret (libsodium) **before** any request; only the ciphertext and the CSRF token are
   posted (the secret, file and password controls have no `name`).
2. `nowo-yopass-create` posts with `fetch(form.action, {method: 'POST', credentials: 'same-origin', mode:
   'same-origin', redirect: 'error', cache: 'no-store', headers: {Accept: 'application/json'}})`.
3. `ShareManageController::create()` answers JSON when `application/json` is the first accepted type:

   | Status | Body | When |
   |--------|------|------|
   | `201` | `{"id", "public_path", "created_url"}` | Share stored (`ShareCreatedEvent` dispatched) |
   | `422` | `{"error": "CREATE_FAILED"}` / `{"error": "FILE_NOT_ENABLED"}` | Invalid form (CSRF, ciphertext, options) / file shares disabled |
   | `403` | `{"error": "CROSS_SITE"}` | `Sec-Fetch-Site` sent and not `same-origin` (also enforced in page mode: plain 403) |

   JSON responses carry `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`, no flash, and never the
   ciphertext, a key or plaintext.
4. The links are built in the browser from `public_path` (must be a same-origin path) and the key kept in memory; the
   key never reaches `sessionStorage` or the server.
5. Every close of the dialog wipes the form, the result and the key; after a create the cancelable
   `nowo-yopass-create:created` event (`detail.id`) fires, then the page reloads unless prevented. A close while the
   request is in flight aborts it and invalidates its response (a late answer writes nothing and keeps no key); the
   event then fires with `detail.id = null` (the share may exist), so the list still refreshes.

CSP: the `fetch` needs `connect-src 'self'` (or `default-src 'self'`); the script keeps the `csp_nonce` hook and the
`'wasm-unsafe-eval'` routes of [Content-Security-Policy](#content-security-policy).

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
