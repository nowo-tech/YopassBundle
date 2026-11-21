# Usage

## Table of contents

- [Manage shares](#manage-shares)
- [Public reveal](#public-reveal)
  - [Public reveal page: layout security](#public-reveal-page-layout-security)
- [Console](#console)
- [Custom access control](#custom-access-control)
  - [Route-level checker](#route-level-checker)
  - [Share events (teams, grants, roles)](#share-events-teams-grants-roles)
- [Standard Symfony host](#standard-symfony-host)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
  - [Id-based ports (2.1)](#id-based-ports-21)
  - [Events after the application's commit](#events-after-the-applications-commit)
  - [DDD host: the bundle's own entity manager](#ddd-host-the-bundles-own-entity-manager)
  - [DDD host: the bundle's own document manager (MongoDB)](#ddd-host-the-bundles-own-document-manager-mongodb)
  - [Done in 2.0](#done-in-20)
  - [DDD host: XML mapping](#ddd-host-xml-mapping)
- [Twig overrides](#twig-overrides)
  - [Forms in templates (REQ-TWIG-005)](#forms-in-templates-req-twig-005)
- [Clock (REQ-DI-001)](#clock-req-di-001)
- [Translations](#translations)

## Manage shares

Authenticated users open the manage UI (default `/tools/yopass`):

- Create text or file shares (encrypted in the browser; submitted via the Symfony form, or in a dialog with `fetch`
  when `web_ui.create_mode: dialog` — the links then appear in the dialog)
- Set expiration and max reads
- List own shares by default (paginated when `shares.list_page_size` > 0); customize via share list events
- Preview content without consuming a read (row action **View** opens the native `<dialog>` preview)
- Extend expiration or max reads (row action **Extend**, same dialog)
- Revoke, delete individual shares, or delete all — POST + CSRF behind the UiKit confirm `<dialog>` (no `window.confirm()`)

The page is the UiKit **list scaffold** (create panel above the list; pagination, empty state, row actions and confirm
dialogs come from UiKit). See [Templates](TEMPLATES.md).

After creating a share, the **created** page shows:

- **Short link** — `/share/{id}` (key shared separately or pasted on the reveal page)
- **Decryption key** — copy for separate delivery
- **One-click link** — `/share/{id}#decrypt_key=…`, only when `sharing.allow_embed_in_url` is `true` (listed first when `sharing.default_embed_in_url` is `true`). The key is in the URL fragment, which browsers never send to the server.

The page is the UiKit **show scaffold** (back link and "Create another secret" in the page header).

## Public reveal

Recipients open `/share/{id}`:

| Link type | URL | Behaviour |
|-----------|-----|-----------|
| Short | `/share/{id}` | Enter decryption key in the form, then click **Reveal secret** |
| One-click | `/share/{id}#decrypt_key=…` | Key read from the URL fragment (never sent to the server), then removed from the address bar; auto-reveal when possible |

Each successful consume consumes one read. Decryption happens client-side; the server returns ciphertext only.

The reveal page is a unique page on the UiKit **guest layout** (`nowo_ui_kit.layouts.guest`, or
`web_ui.public_layout_template`).

### Public reveal page: layout security

The decryption key (URL fragment or form input) and the decrypted secret live in the reveal page's DOM, which is
rendered inside **your** guest / public layout. Every script that layout loads runs in the same page and can read them.

- The guest / public layout used for the reveal page **must not load third-party scripts**: no tag manager, analytics,
  session replay, chat widget, A/B testing, marketing or ad snippet, and no script from a third-party CDN. Only
  first-party, self-hosted scripts (UiKit, your own assets, `js/yopass.js`).
- If your site-wide guest layout carries such scripts, point `web_ui.public_layout_template` at a dedicated minimal
  layout (extend `@NowoUiKit/layout/guest.html.twig` or use the UiKit bridge) without them.
- Send a strict `Content-Security-Policy` for `/share/{id}` (at least `script-src 'self'`; no `unsafe-inline` /
  third-party origins). libsodium needs `'wasm-unsafe-eval'` on the pages that load `yopass.js`; scope it to those
  routes and pass your nonce through the `csp_nonce` request attribute
  ([Configuration — Content-Security-Policy](CONFIGURATION.md#content-security-policy)).
- The same rule applies to the admin layout for the "share created" page and the manage preview (stored key, one-click
  link, decrypted preview).

The bundle sends `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer` on the reveal page
(`GET /share/{id}`) and the "share created" page; do not override them in a listener or a reverse proxy.

Legacy URLs keep working: a raw `#key` fragment is read as before, and query-string keys (`?decrypt_key=`, `?key=`, `?password=`, used by links created up to 1.8.0) are redirected by the server (`303`, before routing) to the `#decrypt_key=` form.

## Console

Purge shares older than the configured retention age:

```bash
php bin/console nowo:yopass:purge-old-shares
```

Retention also runs when a user creates a share (their own old shares); it never runs on a list GET. Schedule the command.

## Custom access control

Three layers — use only what you need:

| Layer | Interface / event | Question |
|-------|-------------------|----------|
| Route features | `YopassAccessPolicyInterface` | Can the user open manage, create, list, or revoke at all? |
| List query | `ShareListQueryEvent` | Which shares should the repository load? |
| List filter | `ShareListResultEvent` | Which loaded shares should appear? |
| Per share | `ShareAccessCheckEvent` | Can the user view/preview/extend/revoke/delete this share? (also decides which row actions are rendered) |
| Extra columns | `ShareListColumnsEvent` | Which host columns ("Created by", "Shared with") does the list show? |
| Delete all | `ShareDeleteAllEvent` | Which shares does "delete all" remove (tenant scope), or is it vetoed? |

Audit / notification hooks: `ShareCreatedEvent`, `ShareDeletedEvent`, `ShareBulkDeletedEvent` (metadata only, never
secret material). See [Configuration — Share lifecycle events](CONFIGURATION.md#share-lifecycle-events).

### Route-level checker

```yaml
nowo_yopass:
    security:
        access_checker: App\Security\TeamYopassAccessPolicy
```

Implement the framework-free `YopassAccessPolicyInterface` (the service must implement it, or the container build fails): the signed-in user arrives as a `UserRef`
(`id`: `getId()` as a string, else the identifier with `idIsIdentifier: true`, which an id-keyed policy must
check and deny; `identifier`; `roles` as the security user reports them, without
the role hierarchy), or `null` when nobody is signed in. Deny by default: return `true` only for what you grant.

```php
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;

final readonly class TeamYopassAccessPolicy implements YopassAccessPolicyInterface
{
    public function __construct(private TeamMembers $members) {}

    public function canAccess(?UserRef $user = null): bool { return $user !== null && $this->members->isMember($user->id); }
    public function canCreate(?UserRef $user = null): bool { return $this->canAccess($user); }
    public function canList(?UserRef $user = null): bool { return $this->canAccess($user); }
    public function canRevoke(?UserRef $user = null): bool { return $user?->hasRole('ROLE_TEAM_ADMIN') ?? false; }
}
```

`YopassAccessCheckerInterface` (`?UserInterface`, deprecated since 1.19) was removed in 2.0; see
[UPGRADING](UPGRADING.md#removed) for the method mapping.

### Share events (teams, grants, roles)

The bundle stores the share **creator** but does not manage teams or permissions. Register Symfony event listeners — see [examples/AccessControl.md](examples/AccessControl.md) and copy from `examples/access-control/`:

- `TeamShareListListener` — list team members' shares
- `TeamShareAccessListener` — same-team access
- `IndividualShareGrantListener` — per-share collaborator grants
- `RoleBasedShareAccessListener` — e.g. auditor = preview only

```php
use Nowo\YopassBundle\Event\YopassEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: YopassEvents::SHARE_ACCESS_CHECK)]
final class MyShareAccessListener
{
    public function __invoke(\Nowo\YopassBundle\Event\ShareAccessCheckEvent $event): void
    {
        // grant() or deny() based on your rules
    }
}
```

## Standard Symfony host

Nothing to configure beyond `user_class`: the entities are mapped by attributes, the creator is a Doctrine relation to
your `User` entity, the default role checker guards the manage routes (`security.roles.*`), and the shares
and the access log are written through the bundle's own entity manager, built on `database.entity_manager` (same
connection, same tables): a share write never flushes the changes your controller has pending. See
[the bundle's own entity manager](#ddd-host-the-bundles-own-entity-manager) for the details; they apply to both hosts.

## Using it from a DDD / hexagonal application

**Layer.** YopassBundle is a small, self-contained bounded context ("ephemeral secret sharing") with its own
persistence (`SecureShare`, optional `ShareAccessLog`) and its own UI (manage pages, public reveal page, routes).
Secrets are encrypted in the browser; the server only stores ciphertext. Keep it outside your domain model and
integrate through events and ports.

**Keep your domain free of the bundle**

- Do not reference `Nowo\YopassBundle\Entity\SecureShare` from domain code. React to the lifecycle events in an
  infrastructure listener and translate them into your own commands/events (they carry metadata only, never
  ciphertext): `YopassEvents::SHARE_CREATED`, `SHARE_CONSUMED`, `SHARE_REVOKED`, `SHARE_DELETED`, `SHARE_EXTENDED`,
  `SHARES_BULK_DELETED`. Since 1.16 they also carry scalar ids: `getCreatorId()`, `getRevokedById()`,
  `getDeletedById()`, `getExtendedById()`, `ShareBulkDeletedEvent::getSubjectId()` / `getViewerId()` (the `getId()`
  value as a string), so your listener never has to know the user class. `SecureShare::getCreatorId()` does the same
  on the port.
- Put authorisation rules behind the bundle's ports: implement the framework-free `YopassAccessPolicyInterface`
  (`?UserRef`, 1.19; see [Route-level checker](#route-level-checker)) and set `security.access_checker`, and listen to `YopassEvents::SHARE_ACCESS_CHECK` (`grant()` / `deny()`) for per-share
  rules. Use `SHARE_LIST_QUERY` / `SHARE_DELETE_ALL` for team or tenant scoping.
- To own the storage, set `database.driver: custom` and `database.repository` to a service implementing
  `ShareRepositoryInterface` (its `consumeReadIfAvailable()` must be atomic; implement
  `MaxReadsRaisingShareRepositoryInterface` too so raising max reads is atomic, and rebuild loaded shares with
  `SecureShare::reconstitute()`).

**The creator.** With the `doctrine_orm` / `doctrine_mongodb` drivers, `SecureShare.creator` is a Doctrine relation
to `nowo_yopass.user_class`, so the host needs a Doctrine user class (an infrastructure persistence model is enough).
Your **security user does not have to be that entity** (since 1.16), but the bundle only trusts the classes you list.
The port `CreatorReferenceResolverInterface` turns the token user into the stored creator. The default
(`DoctrineCreatorReferenceResolver`) keeps `user_class` instances as they are and turns an instance of a class listed
in `accepted_user_classes` (a DTO, a read model carrying the `user_class` id) into `getReference(user_class, id)` on
the bundle's entity / document manager, without a query:

```yaml
nowo_yopass:
    user_class: App\Entity\User
    accepted_user_classes: [App\Security\SecurityUser]   # default: [] = user_class only
```

Any other user is refused (`AccessDeniedException`, 403) on create, list, delete all and the retention purge, and is
never the owner of a share (`ShareAccessGuard`): in a host with a second identity domain (Admin#5 next to Customer#5)
the ids never cross. Ids of 0, negative ids and empty ids are refused. Alias the interface to your own service when
the security user's id is not the persisted user's id:

```yaml
services:
    Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface: '@App\Infrastructure\Yopass\CreatorReferences'
```

A `SHARE_ACCESS_CHECK` listener can still grant other users (team admins) per share.

**Plain creator ids for a strict DDD host (opt-in, 1.18).** When your users are plain PHP objects, are mapped in XML
in another entity manager or are not in Doctrine at all, store ids instead of a relation:

```yaml
nowo_yopass:
    user_class: App\Infrastructure\Security\SecurityUser   # its getId() is stored (UUIDs welcome)
    user_mapping: id
```

The share then holds `creator_id VARCHAR(128)` (MongoDB: a string `creator` field) without any foreign key, indexed and
compared byte for byte (since 1.20.4 `utf8mb4_0900_bin` on MySQL 8.0.17+ and `utf8mb4_nopad_bin` on MariaDB, the PAD
SPACE `utf8mb4_bin` with a logged warning on MySQL 5.7; `Latin1_General_100_BIN2` on SQL Server; the repository also
keeps only exact matches in lists, pages, counts and deletions, and refuses ids with spaces, control or invisible
characters), and
`SecureShare::getCreator()` returns a `CreatorId` (`getId()`); prefer `getCreatorId()` in your code. Owner checks,
events, listings and the fail-closed rule for other identity domains work as with the relation. Nothing cascades when
a user is deleted: call the id-typed repository port from your handler:

```php
use Nowo\YopassBundle\Repository\CreatorIdShareRepositoryInterface;

final readonly class DeleteUserSharesOnUserDeleted
{
    public function __construct(private CreatorIdShareRepositoryInterface $shares) {}

    public function __invoke(UserDeleted $event): void
    {
        $this->shares->removeAllByCreatorId($event->userId->toString());
    }
}
```

With the id mapping nothing ties the share table to your user class any more. Since 1.19 the bundle writes through its
own entity manager in both mappings (next section).

### Id-based ports (2.1)

A DDD handler talks to the bundle through three interfaces of `Nowo\YopassBundle\Share` (autowired by type), keyed
by ids and returning `ShareView` read models (scalars and dates, never the ciphertext):

```php
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Share\ShareAccessGuardInterface;
use Nowo\YopassBundle\Share\ShareCommandsInterface;
use Nowo\YopassBundle\Share\ShareCriteria;
use Nowo\YopassBundle\Share\ShareQueryInterface;

final readonly class SecretShares // Infrastructure/Kit adapter of your own Application port
{
    public function __construct(
        private ShareCommandsInterface $commands,
        private ShareQueryInterface $query,
        private ShareAccessGuardInterface $access,
    ) {}

    public function revoke(string $userId, string $shareId): bool
    {
        if (!$this->access->canManageId(new UserRef($userId), $shareId, ShareAccessAction::Revoke)) {
            return false;
        }

        return $this->commands->revoke($shareId, $userId) !== null;
    }

    /** @param list<string> $visibleIds share ids your own context table grants */
    public function page(string $userId, array $visibleIds, int $page): array
    {
        $result = $this->query->page(new ShareCriteria([$userId], $visibleIds, matchAny: true), 20, ($page - 1) * 20);

        return [$result->items, $result->total];
    }
}
```

- `ShareCommandsInterface`: `create(CreatorId, ShareCreateData)` (the browser already encrypted the ciphertext; the
  create form's limits apply and `ShareCommandException` refuses the rest), `revoke()`, `extend()`, `delete()`,
  `deleteByIds(list, ?CreatorId)` and `deleteAllOf(CreatorId)`. The optional actor id ends up in the events. No access
  check is made: check first.
- `ShareQueryInterface`: `find()`, `count()`, `page()` and `ids()` over a `ShareCriteria`: creator ids and / or share
  ids (intersection, or the union with `matchAny`), a state filter (`any`, `active`, `inactive`) and the order
  (newest first by default). Ids compare exactly; unusable ids are refused with an `InvalidArgumentException`.
- `ShareAccessGuardInterface::canManageId(UserRef, shareId, action)`: the owner rule by exact creator id, then the
  `SHARE_ACCESS_CHECK` event (listeners decide on `getUserId()` / `getShareId()` / `getCreatorId()`). Pass the id of
  `nowo_yopass.user_class`.

Under the relation mapping a `CreatorId` becomes `getReference(user_class, id)` on the bundle's manager; an integer
key only takes a plain decimal id (`07` and `abc` are refused, never cast: `create()` throws `ShareCommandException`
`invalid_creator`, `deleteAllOf()` answers `0`, since 2.1.1).

Keep your own tables keyed by a plain `share_id` column instead of a relation to `SecureShare`: see
[UPGRADING](UPGRADING.md#id-based-ports-ddd-hosts).

### Events after the application's commit

Opt-in (2.1; recommended for DDD hosts, planned as the 3.0 default). With the `doctrine_orm` driver, doctrine/dbal 4 and
`events.after_host_commit: true` (default `false`: events are dispatched at once, as in 2.0), a bundle write made
inside **your** transaction holds its lifecycle event (`share_created`, `share_deleted`, `share_revoked`,
`share_extended`, `share_consumed`, `shares_bulk_deleted`) until the outermost commit of that connection:

- a rollback drops it; a rollback to a savepoint drops the events raised inside that savepoint;
- after the commit it is dispatched as soon as the connection is idle again: at the next bundle event, at the main
  request's `kernel.response`, or at the end of the request, Messenger message or console command. (Not inside the
  commit itself: DBAL updates its transaction level only after the driver commit returns.) A listener that throws then
  is logged (event name and exception class) and does not undo anything: the data is committed;
- without an open transaction (the manage UI, the public reveal, a handler that does not wrap the call) the event is
  dispatched at once, as before, and a listener's exception reaches the caller;
- the pre-action events (`share_delete_all`, `share_access_check`, the list events) are never held;
- in FrankenPHP worker mode, nothing held survives the unit of work (uncommitted events are dropped with a warning).

With DBAL 3, MongoDB or a custom repository, events are always dispatched at once.

### DDD host: the bundle's own entity manager

With the `doctrine_orm` driver (since 1.19; the only mode since 2.0) the shares and the access log are read and written
through a **private** entity manager built on the connection, mapping configuration
and event manager of `database.entity_manager` (default `default`). The bundle never flushes, clears or closes your
entity manager:

- your pending changes stay pending: a share create / consume / extend / revoke / delete or an access log entry never
  writes them, and they are written by your own `flush()` only;
- the bundle's rows are in the database when its call returns (no write waits for your flush, none is lost if you
  `clear()`);
- the connection is shared: when you call the bundle inside your own transaction (`wrapInTransaction()`), its rows
  commit or roll back with yours, and since 2.1 its events can wait for your commit (opt-in)
  ([Events after the application's commit](#events-after-the-applications-commit));
- a failed bundle write closes only the private manager, which is replaced on the next call; its identity map is
  emptied at every request, command and Messenger message boundary (FrankenPHP worker mode without kernel reset);
- the tables do not move, and `doctrine:schema:*` / migrations keep seeing the entities through
  `database.entity_manager`.

With the relation mapping, `SecureShare::getCreator()` is a reference **in the private manager** (same class and id):
use `getCreatorId()`, and load the user from your own manager if you need the entity. The lifecycle events'
`getCreator()` already hands you the user in **your** manager (the managed instance, or a lazy reference from the
id), so an event listener may relate it to its entities and flush. Shares returned by the bundle are never in your unit
of work. The SQL filters enabled on `database.entity_manager` (with their parameters) are applied to the private
manager on every call.

This covers the Doctrine ORM driver. The MongoDB driver gives the same guarantees from a private document manager built
on `database.document_manager`: see [DDD host: the bundle's own document manager](#ddd-host-the-bundles-own-document-manager-mongodb).
With `database.driver: custom` your repository stores the shares; on a relational platform the bundle's access log is
written through the bundle's own entity manager on `database.entity_manager` (same connection and table), never
through yours.

There is no host-manager mode any more: `database.private_entity_manager: false` (deprecated since 1.19) fails the
container build since 2.0, and `true` is a deprecated no-op.

### DDD host: the bundle's own document manager (MongoDB)

The `doctrine_mongodb` driver (1.22 opt-in, the only mode since 2.0) reads and writes shares through a **private** document manager built on the client, configuration and event manager of
`database.document_manager`. The bundle never flushes, clears, closes or resets your document manager:

- your pending documents stay pending: a share write never writes them;
- the shares stay in the same database and collection, and are never in your identity map;
- the private manager is dropped at every outermost request, command and Messenger message boundary and at
  `kernel.reset` (FrankenPHP worker mode without kernel reset), and replaced when closed;
- its queries use your document manager's own BSON filter collection: the same filter instances, parameters and
  property state (nothing is copied).

With the relation mapping, `SecureShare::getCreator()` is a reference **in the private manager**; the lifecycle events'
`getCreator()` hands you the user of **your** document manager. `database.private_document_manager: false` fails the
container build since 2.0; `true` is a deprecated no-op.

### Done in 2.0

2.0 removed only the deprecations 1.x announced: the host-manager modes (ORM and MongoDB), the custom driver's access
log on your entity manager, `YopassAccessCheckerInterface`, and the 1.10 configuration keys, templates and Twig
globals. 1.x gets 6 months of security support after 2.0.0 (Moderate and higher backported). See
[UPGRADING](UPGRADING.md#to-200).

### DDD host: XML mapping

Hosts that map by XML only set `database.mapping: xml` (1.19): the bundle registers its XML mapping
(`src/Resources/config/doctrine/shares_and_access_log`, or `shares_only` when the bundle does not map the access log)
instead of the attribute mapping. Same tables, columns, keys and indexes; `table_prefix`, `user_mapping` and the
indexes are applied the same way.

```yaml
nowo_yopass:
    user_class: App\Infrastructure\Security\SecurityUser
    user_mapping: id
    database:
        mapping: xml
    security:
        access_checker: App\Infrastructure\Yopass\TeamYopassAccessPolicy   # YopassAccessPolicyInterface
```

`tests/Integration/DddHost/DddHostKernelTest.php` boots both host kinds: the standard host (attributes, relation, own
entity manager by default) and the DDD host (XML-mapped aggregate and bundle entities, creator ids, an access policy on
`UserRef`, pending aggregate changes left untouched by share writes).

**Deleting a user deletes their shares, never the read history (relation mapping).** The creator foreign key is `ON DELETE CASCADE`: a
share is a secret its creator handed out, and the bundle does not keep secrets of an erased account. The access-log
rows of those shares stay (1.16, `share_id` `ON DELETE SET NULL`), with `share_ref` (SHA-256 of the share id) so the
trail stays meaningful; see [CONFIGURATION › Access log](CONFIGURATION.md#access-log). With the MongoDB driver there is
no access log.

**Adapter sketch** — forward share creation to your application layer:

```php
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: YopassEvents::SHARE_CREATED)]
final readonly class YopassShareCreatedAdapter
{
    public function __construct(private AuditTrail $audit) {} // your port

    public function __invoke(ShareCreatedEvent $event): void
    {
        $this->audit->secretShared(
            shareId: $event->getShareId(),
            userId: $event->getCreatorId(),
            expiresAt: $event->getExpiresAt(),
            maxReads: $event->getMaxReads(),
        );
    }
}
```

**Reacting to reads.** `YopassEvents::SHARE_CONSUMED` (`ShareConsumedEvent`, since 1.17) fires once per stored read
of a public link, with every driver: `getShareId()`, `getCreatorId()`, `getReadNumber()`, `getMaxReads()`,
`getReadsLeft()`, `getConsumedAt()`, `getPayloadKind()`. The reader is anonymous, so there is no actor id, and the
event carries no IP address or user agent. It never fires for a refused read. The read is already spent when it
fires: a listener that throws is logged and ignored, so do slow or fallible work asynchronously (e.g. dispatch a
Messenger message).

**Keep listeners idempotent.** The number of events is exact (one per stored read), but with Doctrine ORM the
counters are a snapshot re-read right after the conditional `UPDATE`, not part of it: under concurrent reads two
events can report the same `getReadNumber()` / `getReadsLeft()` (both `0`, for example), and a creator raising the
max reads in between can mean no event ever reports `0`. MongoDB returns the exact post-update counters. Do not key a
once-only action on `getReadsLeft() === 0` alone; deduplicate by share id:

```php
#[AsEventListener(event: YopassEvents::SHARE_CONSUMED)]
final readonly class YopassShareConsumedAdapter
{
    public function __construct(private NotifyCreator $notify) {} // your application port

    public function __invoke(ShareConsumedEvent $event): void
    {
        if ($event->getCreatorId() === null) {
            return;
        }

        // Idempotent on the share id: the port records "notified" once and ignores repeats.
        $this->notify->secretWasReadOnce($event->getCreatorId(), $event->getShareId(), $event->getConsumedAt());
    }
}
```

**Your own access log store (1.18).** Set `access_log.repository` to a service implementing
`ShareAccessLogRepositoryInterface` to keep the read history in your own store, with any driver (MongoDB and custom
included); the bundle then stops mapping `ShareAccessLog`. Or listen to `YopassEvents::SHARE_CONSUMED` (since 1.17;
ids and counters, no IP address or user agent).

**Both paths in short**

| | Standard Symfony project (default) | Strict DDD / hexagonal host (opt-in) |
|---|---|---|
| Creator storage | Doctrine relation to `user_class` (`ON DELETE CASCADE`) | `user_mapping: id`: string id, no foreign key |
| Security user | `user_class`, or a class in `accepted_user_classes` | `user_class` (any PHP class with `getId()`), or listed classes |
| User deletion | cascades to the shares | call `removeAllByCreatorId()` |
| Access log | ORM entity (`access_log.enabled`) | `access_log.repository`, or `SHARE_CONSUMED` |

**Known limitations**

- `ShareRepositoryInterface` still takes creator objects and returns the `SecureShare` entity (the id-typed
  `CreatorIdShareRepositoryInterface` sits next to it); services are final classes (replace them through the ports
  above).
- `ShareAccessLogRepositoryInterface` is still typed on the `ShareAccessLog` / `SecureShare` classes.

## Twig overrides

The bundle registers the Twig namespace **`NowoYopassBundle`** with `TwigPathsPass` (always on; no feature flag). When `templates/bundles/NowoYopassBundle/` exists in your application at container compile time it is **prepended** to the namespace, and the bundle views (`src/Resources/views/`) are **appended** as the fallback — so an application file at the override path **always wins** over the shipped one. Controllers render logical names (`@NowoYopassBundle/...`) only.

**Prefer upgrade-safe customisation first (no Twig fork):**

| Depth | How | On bundle upgrade |
| ----- | --- | ----------------- |
| **Preferred** | Theme UiKit once: `nowo_ui_kit.layouts.{admin,guest}`, `nowo_ui_kit.css_framework` / `icon_set` / `row_actions_display`, `nowo_ui_kit.theme` (class / attribute maps, tokens, `theme.bundles.NowoYopassBundle`), the host form theme, translations, CSS on `nowo-ui-*` / `nowo-yopass-*` / `--nowo-ui-*` — see [Configuration — Web UI](CONFIGURATION.md#web-ui-uikit-page-contract) | Vendor pages and fixes apply automatically |
| **Surgical** | `nowo_yopass.web_ui.templates.<page>` (`manage`, `created`, `public`) pointing at a host template that `{% extends '@!NowoYopassBundle/...' %}` and overrides one data block (`nowo_ui_list_row`, `nowo_ui_show_sections`, `nowo_ui_content`, …) with `{{ parent() }}` where possible, or override a single partial (`manage/_create_panel.html.twig`, `manage/_preview_dialog.html.twig`) | Only that block / partial is yours; the rest keeps upgrading |
| **Full-file override** | Copy a page into `templates/bundles/NowoYopassBundle/<subpath>` | That `<subpath>` is **frozen** (see below) |

**Freeze rule.** An application file at the override path always wins and will **not** pick up vendor changes for that path until you remove or merge it. A full-file override is an escape hatch for deep redesigns; every `composer update` keeps serving your copy of that `<subpath>` (including its JavaScript hooks and CSRF form wiring) while the rest of the bundle upgrades. For a one-time-secret UI this matters: a frozen `manage/*.html.twig` or `public/reveal.html.twig` will not receive security-relevant template fixes.

**Procedure (full-file override)**

1. Pick the `<subpath>` from the table below.
2. Create `templates/bundles/NowoYopassBundle/<subpath>` in your application (same relative path and file name).
3. Clear the cache (`php bin/console cache:clear`) — the override directory is detected when the container is compiled, so the cache must be rebuilt the first time you create it.
4. Treat the file as a fork: diff it against the vendor copy on each bundle upgrade.

| `<subpath>` (under `src/Resources/views/`) | Purpose |
| ------------------------------------------ | ------- |
| `manage/index.html.twig` | Manage page (UiKit list scaffold): data block `nowo_ui_list_row`; `nowo_ui_content` adds the create panel and the preview dialog. |
| `manage/_create_panel.html.twig` | Create-share card (page mode). |
| `manage/_create_form.html.twig` | Create form shared by the card and the dialog (browser-side encryption; result region in dialog mode). |
| `manage/_create_dialog.html.twig` | Create dialog (`web_ui.create_mode: dialog`). |
| `manage/_preview_dialog.html.twig` | Preview / extend native `<dialog>` (UiKit `dialog` partial). |
| `manage/created.html.twig` | Page shown after creating a share (UiKit show scaffold; short link, decryption key, one-click link). |
| `public/reveal.html.twig` | Public reveal page (unique page on the UiKit guest layout; key or password input, client-side decryption). |

Removed in 1.10: `layout.html.twig` (default shell loading CSS frameworks from CDNs; the 1.x value maps to the UiKit
layout) and `_action_icons.html.twig` (row actions are UiKit `Action`s). Removed in 2.0: the 1.x layout bridges
`_legacy_layout.html.twig` and `layout_integrate_host.html.twig` (use a layout that renders the UiKit canonical
blocks). Full page list, view models and data blocks:
[Templates](TEMPLATES.md).

Alternatively point `nowo_yopass.web_ui.templates.*` (`manage`, `created`, `public`) at your own application templates instead of using the override path.

**Recovering upstream after a freeze:** delete (or rename aside) the application override, clear the cache (`php bin/console cache:clear`), and re-apply only the surgical customisation you still need. Diff your copy against the new vendor file under `vendor/nowo-tech/yopass-bundle/src/Resources/views/` before re-copying.

### Forms in templates (REQ-TWIG-005)

Every control in the bundle views comes from a PHP Symfony Form Type (Form Kit `yopass` profile) built by the controller and rendered with `form_start` / the child loop / `form_end`:

| View | Variable | Form Type |
| ---- | -------- | --------- |
| `manage/_create_panel.html.twig` (from `manage/index.html.twig`) | `create_form` | `ShareCreateType` (ciphertext, payload kind, expiry, max reads; plus the browser-only secret, file, encryption mode and custom password controls) |
| `manage/index.html.twig` (UiKit `Action::$form` of the Revoke / Delete row actions and the Delete all header action) | `revoke_forms`, `delete_forms` (share id → form), `delete_all_form` | `CsrfActionType` (flat `_token`, token ids `revoke-share-<id>`, `delete-share-<id>`, `yopass_delete_all`) |
| `manage/_preview_dialog.html.twig` | `preview_form` | `SharePreviewType` (preview / extend dialog controls) |
| `manage/created.html.twig` | `links_form` | `ShareCreatedLinksType` (read-only links and key) |
| `public/reveal.html.twig` | `reveal_form` | `ShareRevealType` (password or key input) |

Browser-only controls (plaintext secret, file, passwords, decryption keys) are unmapped children with fixed ids and Stimulus targets and an **empty `name`**, so they are never sent to the server; the create form stays url-encoded and posts the same payload as before. Render form bodies with the canonical loop after any custom children:

```twig
{{ form_start(form) }}
    {% for child in form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
    <button type="submit">…</button>
{{ form_end(form) }}
```

Template overrides and demos **must not** reintroduce raw `<form>`, `<input>`, `<textarea>` or `<select>` markup; `make check-no-raw-html-form` (part of `release-check`) fails when they do.

## Clock (REQ-DI-001)

Every time-dependent service takes an optional `Psr\Clock\ClockInterface` (autowired to the framework `clock` service when `symfony/clock` is installed, otherwise the system clock): `ShareCreator` (expiration date of new shares), `ShareExtender`, `ShareRetriever`, `ShareRetentionPurger`, the Doctrine ORM / MongoDB share repositories (atomic consume) and `PublicEndpointRateLimiter` (fixed window). Freeze time in tests with `Symfony\Component\Clock\MockClock`.

Justified exception: the `SecureShare` / `SecureShareDocument` / `ShareAccessLog` persistence objects stamp `createdAt`, `revokedAt` and `accessedAt` with the wall clock in their own methods (entities do not receive services), and `SecureShare::isAvailable()` compares against the current time. Availability decisions in the HTTP flow go through the clock-aware services above.

## Translations

Domain: `NowoYopassBundle`. Bundled locales: **en**, **es**, **de**, **fr**, **it**, **nl**, **pt**. Override in `translations/bundles/NowoYopassBundle/` or `translations/NowoYopassBundle.en.yaml`.
