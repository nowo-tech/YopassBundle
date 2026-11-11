# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.18.0](#to-1180)
- [To 1.17.0](#to-1170)
- [To 1.16.0](#to-1160)
  - [Behaviour change: only accepted user classes own shares](#behaviour-change-only-accepted-user-classes-own-shares)
  - [Access log rows outlive their shares (migration)](#access-log-rows-outlive-their-shares-migration)
  - [Other changes](#other-changes)
- [To 1.15.1](#to-1151)
- [To 1.15.0](#to-1150)
  - [Retention: a cutoff not in the past is refused](#retention-a-cutoff-not-in-the-past-is-refused)
  - [Retention: every term of `max_age` counts (it deletes secrets)](#retention-every-term-of-max_age-counts-it-deletes-secrets)
  - [Manage page](#manage-page)
- [To 1.14.1](#to-1141)
- [To 1.14.0](#to-1140)
- [To 1.13.0](#to-1130)
  - [One create action per screen](#one-create-action-per-screen)
  - [The list on a host page](#the-list-on-a-host-page)
  - [Preview dialog layout and stylesheet](#preview-dialog-layout-and-stylesheet)
  - [Security headers on every route](#security-headers-on-every-route)
  - [Retention: no purge on GET, new indexes (migration)](#retention-no-purge-on-get-new-indexes-migration)
  - [Host list filters](#host-list-filters)
- [To 1.12.1](#to-1121)
- [To 1.12.0](#to-1120)
  - [Create in a dialog (opt-in)](#create-in-a-dialog-opt-in)
  - [Per-row actions, revoke / extend events, host columns](#per-row-actions-revoke--extend-events-host-columns)
  - [Template overrides](#template-overrides)
- [To 1.11.0](#to-1110)
  - [Access log table no longer mapped when disabled](#access-log-table-no-longer-mapped-when-disabled)
  - [CSP: nonce and wasm-unsafe-eval](#csp-nonce-and-wasm-unsafe-eval)
  - [New events](#new-events)
  - [Translation wording](#translation-wording)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
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

Minor release (1.19.0). No configuration change is required, and no schema change: the tables stay where they are.

**The bundle's own entity manager (default).** With the `doctrine_orm` driver, shares and the access log are now
written through a private entity manager built on `database.entity_manager`, on the same connection and mapping:

- `doctrine:schema:validate` and `doctrine:migrations:diff` show no change, because the entities stay mapped in
  `database.entity_manager`, so keep your migrations there;
- nothing to migrate in the data, because the private manager reads and writes the same rows;
- if your code relied on a share write flushing your own pending changes, flush them yourself. That was the bug this
  release fixes (REQ-ARCH-001 rule 6);
- **BC, relation mapping:** `SecureShare::getCreator()` on a share the bundle loaded returns a reference in the
  bundle's manager. Read it, or use `getCreatorId()`, but do not relate it to your own entities (load the user from
  your manager instead). The lifecycle events' `getCreator()` already returns the user in **your** manager;
- the SQL filters enabled on `database.entity_manager`, with their parameters, are applied to the bundle's manager on
  every call. A filter you enable through DoctrineBundle's `filters:` config or in a request listener therefore also
  applies to share reads, as before;
- **ORM only:** the MongoDB driver still flushes `database.document_manager`, and the custom driver uses your
  repository; neither changes in this release;
- to keep the old behaviour for now: `database.private_entity_manager: false` (deprecated, removed in 2.0).

**Deprecations (removed in 2.0)**

| Deprecated | Replacement |
|---|---|
| `Security\YopassAccessCheckerInterface` (`canX(?UserInterface)`) | `Security\YopassAccessPolicyInterface` (`canX(?UserRef)`): `$user->getId()` becomes `$user->id`, `getUserIdentifier()` becomes `$user->identifier`, `in_array('ROLE_X', $user->getRoles())` becomes `$user->hasRole('ROLE_X')` |
| `database.private_entity_manager: false` | the default (`true`) |

`UserRef::$id` is the security user's `getId()`; when it has none, the identifier stands in and `UserRef::$idIsIdentifier`
is true. Each interface is aliased to your checker only if its class implements that interface.

A checker that implements only `YopassAccessCheckerInterface` keeps working, is called exactly as before, and triggers
one `E_USER_DEPRECATED` notice when the manage controller is built. Implementing both interfaces is fine: the policy is
used.

**XML mapping (optional).** Hosts that map by XML only can set `database.mapping: xml`, which gives the same tables and
columns. Do not also keep the attribute mapping entry for `Nowo\YopassBundle\Entity` in your own Doctrine config.

## To 1.18.0

From **1.17.0** (minor):

```bash
composer update nowo-tech/yopass-bundle
```

**Standard hosts:** nothing to do. `user_mapping` defaults to `relation` (the Doctrine relation to `user_class`, `ON
DELETE CASCADE`), `access_log.repository` to `null`, and the schema does not change.

**BC notes:** `SecureShare` / `SecureShareDocument` hold the creator internally as `object|string` (the id with the id
mapping); `getCreator()` keeps its `object` return type. `DoctrineOrmShareRepository` and
`DoctrineMongoShareRepository` implement the new `CreatorIdShareRepositoryInterface`; `ShareRepositoryInterface` is
unchanged, so custom repositories keep working.

**Opt-in: id mapping for DDD / hexagonal hosts.** New installs can store creator ids instead of a relation:

```yaml
nowo_yopass:
    user_class: App\Infrastructure\Security\SecurityUser   # its getId() is stored; need not be a Doctrine class
    user_mapping: id
```

With an existing **relational** database, switching from `relation` to `id` turns `creator_id` from a foreign key into
`VARCHAR(128)`: generate the migration (`doctrine:migrations:diff`) and check that it drops the foreign key, keeps the
values, adds the `<table>_creator_idx` index and, on MySQL / MariaDB, sets `CHARACTER SET utf8mb4 COLLATE utf8mb4_bin`
on the column (SQL Server: `COLLATE Latin1_General_100_BIN2`), then migrate. Creator ids are compared byte for byte;
ids longer than 128 bytes or with leading / trailing whitespace are refused.

With **MongoDB**, existing shares keep the `creator` value the relation stored (an int or an ObjectId, not a string).
Convert them once, after the deploy:

```javascript
// mongosh, in the bundle's database (collection: database.collection, default yopass_secure_shares)
db.yopass_secure_shares.updateMany(
    { creator: { $exists: true, $not: { $type: "string" } } },
    [ { $set: { creator: { $toString: "$creator" } } } ]
);
```

Until then, lists and counts do not show those shares, but "delete all", the per-user retention purge,
`removeAllByCreator()` and the GDPR `removeAllByCreatorId()` also match the int / ObjectId forms, so no secret of an
erased user is left behind.

Shares are no longer deleted with their user: call `CreatorIdShareRepositoryInterface::removeAllByCreatorId()` when you
delete one.

**Opt-in: your own access log store** (`access_log.repository: app.yopass_access_log`, a service implementing
`ShareAccessLogRepositoryInterface`), with any driver. The bundle then stops mapping `ShareAccessLog`; an existing
`<prefix>_share_access_logs` table is left as is (migrate or drop it yourself).

## To 1.17.0

From **1.16.0**:

```bash
composer update nowo-tech/yopass-bundle
```

No breaking change, no schema change. New event `YopassEvents::SHARE_CONSUMED` (`ShareConsumedEvent`) after each
stored public read; nothing listens to it by default. `ShareRetriever` takes a new optional third constructor argument
(the internal `ShareConsumedNotifier`, autowired); code that builds it by hand keeps working and dispatches nothing.
Listeners must be idempotent: with Doctrine ORM two concurrent reads can report the same counters (see USAGE). The Doctrine ORM access log is
unchanged (still written by the public controller, kept after share deletion as in 1.16). A listener that throws does
not fail the reader's request; it is logged at `error` level.

## To 1.16.0

From **1.15.1**: one schema migration when `access_log.enabled` is true (the default with the Doctrine ORM driver);
nothing else to change. A security user that is your `user_class` entity is stored exactly as before.

### Behaviour change: only accepted user classes own shares

Only `user_class` instances create, list, bulk-delete, purge and own shares, unless you list other security-user
classes in `nowo_yopass.accepted_user_classes` (audit JX-Y1). This applies to every driver, the custom one included.
Up to 1.15 the per-share owner check compared `getId()` values whatever the class, so in a host with two identity
domains (Admin#5 next to Customer#5) one could manage the other's shares; it now fails closed. A host whose security
user is not `user_class` (a wrapper or read model, or a custom-driver host's own user class) lists that class;
otherwise those users get a 403 and are no longer the owner of their shares. Ids of 0, negative ids and empty ids are
refused. Single-class hosts change nothing. `SHARE_ACCESS_CHECK` listeners can still grant per share.

### Access log rows outlive their shares (migration)

`share_access_logs.share_id` becomes nullable with `ON DELETE SET NULL` and a new indexed column `share_ref` holds the
SHA-256 (hex) of the share id. Deleting a share — or a user, whose shares are still deleted with them — no longer
deletes its read history. `ShareAccessLog::getShare()` returns `?SecureShare`: code that reads it must handle null.

Prefer `php bin/console doctrine:migrations:diff` (it uses your table prefix and constraint names). The statements
below are for the default `table_prefix` (`yopass_`); the foreign key / index names are Doctrine's for that table name
(`FK_558A5CBE2AE63FDB`, `IDX_558A5CBE2AE63FDB`) — check yours with `SHOW CREATE TABLE` / `\d`. Run the backfill
**before** any share is deleted on 1.16, so the rows of shares deleted later keep their reference.

MySQL 8 / MariaDB:

```sql
ALTER TABLE yopass_share_access_logs DROP FOREIGN KEY FK_558A5CBE2AE63FDB;
ALTER TABLE yopass_share_access_logs ADD share_ref CHAR(64) DEFAULT NULL, CHANGE share_id share_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE yopass_share_access_logs ADD CONSTRAINT FK_558A5CBE2AE63FDB FOREIGN KEY (share_id) REFERENCES yopass_secure_shares (id) ON DELETE SET NULL;
CREATE INDEX yopass_share_access_logs_share_ref_idx ON yopass_share_access_logs (share_ref);
UPDATE yopass_share_access_logs SET share_ref = SHA2(share_id, 256) WHERE share_ref IS NULL AND share_id IS NOT NULL;
```

PostgreSQL (11+):

```sql
ALTER TABLE yopass_share_access_logs DROP CONSTRAINT FK_558A5CBE2AE63FDB;
ALTER TABLE yopass_share_access_logs ADD share_ref CHAR(64) DEFAULT NULL;
ALTER TABLE yopass_share_access_logs ALTER share_id DROP NOT NULL;
ALTER TABLE yopass_share_access_logs ADD CONSTRAINT FK_558A5CBE2AE63FDB FOREIGN KEY (share_id) REFERENCES yopass_secure_shares (id) ON DELETE SET NULL NOT DEFERRABLE;
CREATE INDEX yopass_share_access_logs_share_ref_idx ON yopass_share_access_logs (share_ref);
UPDATE yopass_share_access_logs SET share_ref = encode(sha256(share_id::bytea), 'hex') WHERE share_ref IS NULL AND share_id IS NOT NULL;
```

SQLite (a foreign key change rebuilds the table):

```sql
PRAGMA foreign_keys = OFF;
BEGIN;
CREATE TEMPORARY TABLE __temp__yopass_share_access_logs AS SELECT accessed_at, id, read_number, ip_address, user_agent, share_id FROM yopass_share_access_logs;
DROP TABLE yopass_share_access_logs;
CREATE TABLE yopass_share_access_logs (accessed_at DATETIME NOT NULL, id VARCHAR(36) NOT NULL, read_number INTEGER NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(512) DEFAULT NULL, share_id VARCHAR(36) DEFAULT NULL, share_ref CHAR(64) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_558A5CBE2AE63FDB FOREIGN KEY (share_id) REFERENCES yopass_secure_shares (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);
INSERT INTO yopass_share_access_logs (accessed_at, id, read_number, ip_address, user_agent, share_id) SELECT accessed_at, id, read_number, ip_address, user_agent, share_id FROM __temp__yopass_share_access_logs;
DROP TABLE __temp__yopass_share_access_logs;
CREATE INDEX IDX_558A5CBE2AE63FDB ON yopass_share_access_logs (share_id);
CREATE INDEX yopass_share_access_logs_share_ref_idx ON yopass_share_access_logs (share_ref);
COMMIT;
PRAGMA foreign_keys = ON;
```

SQLite has no SHA-256 function: backfill `share_ref` once from PHP (for example in the migration's `postUp()`):

```php
foreach ($connection->fetchAllAssociative('SELECT id, share_id FROM yopass_share_access_logs WHERE share_ref IS NULL AND share_id IS NOT NULL') as $row) {
    $connection->update('yopass_share_access_logs', ['share_ref' => hash('sha256', $row['share_id'])], ['id' => $row['id']]);
}
```

Rows of shares deleted **before** the upgrade are already gone (1.15 cascaded them). SQLite applies `ON DELETE`
actions only with `PRAGMA foreign_keys = ON` on the connection.

### Other changes

- **Security user that is no Doctrine entity:** it works now when it exposes the persisted user's id through
  `getId()`; otherwise alias `Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface` to your own resolver
  ([USAGE](USAGE.md#using-it-from-a-ddd--hexagonal-application)).
- **Event listeners:** prefer the new scalar getters (`getCreatorId()`, `getRevokedById()`…) over casting the user
  objects.
- **Custom repositories:** implement `MaxReadsRaisingShareRepositoryInterface` to make the max-reads raise atomic;
  without it `ShareExtender` saves the share as before.
- **MongoDB:** `readsLeft` is now written as an increment; no data change is needed.

## To 1.15.1

From **1.15.0**:

```bash
composer update nowo-tech/yopass-bundle
```

Target **1.15.1**. Nothing to change in your configuration.

- **MongoDB driver:** shares now load with their stored `createdAt` / `revokedAt`, and saving a share keeps its
  remaining reads and its first revocation date. Shares that were extended on an earlier version keep the reads that
  version gave back: if that matters, revoke them (or check `readsLeft` against your own access log). Hosts that set
  `resolve_target_documents` for `UserInterface` only to make loading work can keep it or drop it.
- **Custom repositories:** rebuild loaded shares with `SecureShare::reconstitute()` rather than the constructor and
  setters, so dates and read counts stay as stored.

## To 1.15.0

From **1.14.1**:

```bash
composer update nowo-tech/yopass-bundle
```

To keep shares for good, set `shares.retention.enabled: false` rather than a huge `max_age`. A translator that knows the retention keys but does not format ICU prints the raw message: use Symfony's ICU formatter, or define the key as plain text.

The constraint becomes `nowo-tech/ui-kit-bundle ^2.35`: Extend and Revoke use kinds that UiKit adds in 2.35. No
configuration change.

### Retention: a cutoff not in the past is refused

**Behaviour change: the container can fail to build.** With retention enabled, `shares.retention.max_age` must now put
the cutoff in the past. These values used to be accepted, and each purge then deleted every share of its scope, live ones
included:

| `max_age` | Cutoff on 2026-11-06 | Now |
| --- | --- | --- |
| `-14 days` | 2026-11-20 | refused when the container is built |
| `1 month ago` | 2026-12-06 | refused when the container is built |
| `1 month 2 days ago` | 2026-12-04 | refused when the container is built |
| `0 days` | the current moment | refused when the container is built |
| `1.5 days` | 2026-11-11 (PHP reads it as plus 5 days) | refused when the container is built |
| `100000 years` | a negative year: nothing deleted, the purge throws on MySQL and a create answers 500 with the share saved | refused when the container is built |

The error reads `The "shares.retention.max_age" value must be a valid PHP relative datetime modifier that puts the cutoff
in the past and after the year 1000…`. To fix it, write the age as a positive duration, such as `14 days`. `shares.retention.enabled: false` does
not read the value. Ordinary durations such as `14 days` or `1 month` are not affected.

The check runs when the container is built, not on every boot. `cache:clear` in production fails with exit 1 and leaves
the existing cache serving, but a host that builds its cache on the first request has no application until the value is
fixed. A value that depends on the date, such as `1 month -30 days`, passes the build on some dates and fails it on
others (it passes on 2026-11-06 and fails on 2026-12-06).

The purge has the same guard, for a value that reaches it another way or that depends on the date. It deletes nothing and
logs an error through the bundle's logger. The purge command then prints an error and exits with a failure code; on the
create path the error is only logged.

### Retention: every term of `max_age` counts (it deletes secrets)

Only hosts with **several terms** in `shares.retention.max_age` are affected. Check yours before you update, because the
next purge uses the new cutoff. Single-term values (`14 days`, `+14 days`, `1 month`) give the same cutoff as before.

| `max_age` | Cutoff before (on 2026-11-06) | Cutoff now | Effect |
| --- | --- | --- | --- |
| `1 month 2 days` | one month back, two days forward: 2026-10-08 | one month and two days back: 2026-10-04 | shares live 4 days longer |
| `+1 month +2 days` | 2026-10-08 | 2026-10-04 | shares live 4 days longer |
| `1 month -2 days` | 2026-10-04 | one month back, two days forward: 2026-10-08 | **shares are deleted 4 days sooner** |
| `1 year -1 month` | 2025-10-06 | 2025-12-06 | **shares are deleted 2 months sooner** |
| `1 month -30 days` | 2026-09-06 | 2026-11-05 | **61 days of retention become 1 day** |

A later term with a minus now shortens the retention, so the cutoff moves forward by twice that term. Check any value
with a minus before you update: the shares it now reaches are deleted on the next purge and cannot be recovered.

Spaces, tabs, line breaks, no-break spaces and commas all separate terms, so a YAML block scalar reads the same.

A modifier with anything other than `<N> <unit>` terms keeps the old reading: a single `-` in front. Words can delete far
more than they read: `0 days midnight` deletes everything not created today, and `14 days yesterday` keeps one day. Each
purge with such a value logs a `warning`; refusing them waits for a major version. Use `<N> <unit>` terms only.

### Manage page

For a host that changes nothing:

| Where | Before | After |
| --- | --- | --- |
| View (row) | `variant: primary`: `nowo-ui-btn--primary`, slot `action_primary` | no variant: `nowo-ui-btn--secondary`, slot `action_neutral`. Same kind, label, hooks |
| New share (header and empty state, dialog mode) | `nowo-ui-action--create`, label only | `nowo-ui-action--share`, `share` icon + label. Still primary; same `data-testid` and dialog |
| Delete all (header) | label only | `delete` icon + label. Same confirm dialog, POST and CSRF form |
| Extend (row) | kind `restore`, `nowo-ui-action--restore`, restore icon | kind `extend`, `nowo-ui-action--extend`, its own icon. Same label, hooks, preview dialog |
| Revoke (row) | kind `cancel`, `nowo-ui-action--cancel`, close icon | kind `revoke`, `nowo-ui-action--revoke`, its own icon. Same label, confirm dialog, POST and CSRF form |
| Delete (row) | kind `delete` | unchanged |
| Retention notice, `max_age: 14 days` | `14 days` in every locale | translated: `14 días`, `14 jours`, `14 Tage`… |
| Retention notice, `max_age: +14 days` or `1 days` | printed as written | `14 days`, `1 day` |
| Retention notice, any other modifier, e.g. `1 month 2 days` | printed as written | `yopass.list.retention_notice_unspecified`, no duration |

Row actions follow `nowo_ui_kit.row_actions_display` as before: `text` shows no icon. With `icon_set: none`, the header
actions stay text.

To check on your side:

- Selectors on the manage page: `.nowo-ui-action--create` becomes `.nowo-ui-action--share`, `--restore` becomes
  `--extend`, `--cancel` becomes `--revoke`. The Share created page keeps kind `create`.
- A theme that painted View through `action_primary` now reads `action_neutral` for it.
- With `icon_set: ux_icon` and no `nowo_ui_kit.action_icons` entry for these kinds, UiKit asks UX Icons for `bi:share`,
  `bi:trash` (header), and for `bi:calendar-plus`, `bi:person-x` (rows, when the display shows icons). They must be
  available locally or through Iconify: a host with `ignore_not_found: false` and neither fails the page.
- An override of `yopass.list.retention_notice` keeps working: `%max_age%` is now the translated duration. Translate
  `yopass.list.retention_notice_unspecified` too if you add a locale, plus the `yopass.list.retention_duration.*`
  ICU messages in `NowoYopassBundle+intl-icu.<locale>.yaml`.
- A translator of your own that does not know `yopass.list.retention_duration.<unit>` gets the notice without a
  duration.
- With `ux:icons:lock`, import these by name, because they come from the kit's map:
  `bin/console ux:icons:import bi:share bi:trash bi:calendar-plus bi:person-x`.
- ICU messages need `ext-intl` or `symfony/polyfill-intl-messageformatter`. Without either, the notice has no duration.

## To 1.14.1

From **1.14.0**: `composer update nowo-tech/yopass-bundle`. No configuration change; the constraint stays
`nowo-tech/ui-kit-bundle ^2.29`.

- **Dialog height** comes from UiKit: update to UiKit **2.31** to get the content height (dialog rule v2); on 2.29 /
  2.30 a dialog with fields keeps the size's height. The bundle sets no height, so `nowo_ui_kit.theme.dialog.height`
  (UiKit 2.31) applies to its dialogs as to every other.
- **Create dialog: Done is now a secondary Close** (one primary per dialog, Beacon §16.6). Overrides of
  `manage/_create_dialog.html.twig` or `_create_form.html.twig` should render it with `btn_secondary` and
  `'yopass.preview.close'|trans`; the `data-testid="yopass-create-done"` / `data-nowo-yopass-create-target="done"` /
  `nowo-yopass-create#done` hooks are unchanged. Tests that look the button up by the text "Done" must use "Close"
  (or the test id).

## To 1.14.0

From **1.13.0**:

```bash
composer update nowo-tech/yopass-bundle nowo-tech/ui-kit-bundle   # ui-kit-bundle ^2.29 is required
php bin/console assets:install
```

- **Visual change, no configuration needed.** Dialogs use the UiKit 2.29 sizes (create: medium; preview: wide) with
  a fixed height and a scrolling body. A host that themed `dialog[data-nowo-yopass-dialog]` sizes should set the UiKit
  tokens instead (`--nowo-dialog-width-md`, `--nowo-dialog-height-md`, `--nowo-dialog-width-wide`,
  `--nowo-dialog-height-wide`).
- **Create dialog markup (overrides of `manage/_create_dialog.html.twig` / `_create_form.html.twig`):** the
  `nowo-yopass-create` controller moved from the form's root `<div>` to a wrapper `<div>` around the `<dialog>`
  (attributes in the new `manage/_create_controller_attrs.html.twig`); Create and Done moved to the dialog footer
  (`form="nowo-yopass-create-dialog-form"`), with a new Cancel. Re-copy a full-file override, or pass
  `controller_root`, `form_id` and `footer_actions` like the bundle template does.
- **Create tabs** are UiKit `tabs` links (`<a href="#" role="tab">`) instead of `<button>`s; selectors on
  `[data-yopass-tab]` keep working. Icons come from `web_ui.icons` (set them to `~` for label only).
- **Extend:** no page reload after a successful extension any more — the dialog updates in place. A host that relied
  on the reload to refresh its list should listen to `YopassEvents::SHARE_EXTENDED` server-side or reload on close.
  An override of `_preview_dialog.html.twig` needs the new targets `extendButton`, `extendPreview` and `extendError`
  (without them Extend works as before, and errors show in the dialog-level error region).
- **Stricter configuration:** `web_ui.icons.*` must match `[prefix:]name` (lowercase letters, digits, single
  hyphens) or be null / `''`, and each `shares.expiration_options.*.interval` must be a valid forward PHP relative
  modifier; an invalid value now fails the container build instead of a page.
- **Translations:** `yopass.extend.current` / `submit` / `expiration_field` / `reads_field` were reworded (7 locales);
  new keys listed in the CHANGELOG.

## To 1.13.0

From **1.12.1** (no breaking change):

```bash
composer update nowo-tech/yopass-bundle
php bin/console cache:clear
```

### One create action per screen

In `web_ui.create_mode: dialog` the header **New share** action now appears only when the list has rows; an empty list
shows it in the empty state only. Hosts that removed the duplicate in a `nowo_ui_page_header` override can drop it.

### The list on a host page

A host page that renders the list (forwarding to the list controller) declares itself:

```yaml
nowo_yopass:
    web_ui:
        list_routes: [app_project_secrets]   # host routes allowed to show the list
```

```php
#[AsEventListener(event: YopassEvents::SHARE_LIST_ROUTE)]
public function __invoke(ShareListRouteEvent $event): void
{
    $project = $event->getRequest()->attributes->get('project'); // set by the host before forwarding
    if (\is_string($project)) {
        $event->setRoute('app_project_secrets', ['id' => $project]); // scalars only
    }
}
```

The pager, the filter form and the redirects after revoke / delete / delete all then use that route. The action forms
carry it as a token signed for the viewer (bound to `getUserIdentifier()`; `kernel.secret` must be set; without it the
redirects keep going to the manage route). Hosts that rewrote the bundle's redirects (a "return" subscriber or session trick) can drop it.

### Preview dialog layout and stylesheet

The manage page now links `css/yopass.css` (asset package `nowo_yopass`) in the `nowo_ui_styles` block: run
`php bin/console assets:install` (or your asset pipeline). Your admin layout must render the canonical
`nowo_ui_styles` block (the UiKit layouts and bridge do). The file only lays out the bundle's `nowo-yopass-*` hooks with
UiKit tokens and is CSP-safe (`style-src 'self'`).

`manage/_preview_dialog.html.twig` was restructured (badge in the title, input groups, copy toast, extend grid). An
override of it should start again from the 1.13 partial; the Stimulus targets are unchanged, plus the optional
`unlock` and `outputPanel` targets and the `copyOutput` action. Wording changed: `yopass.preview.key_help`; new key
`yopass.link.copied`.

### Security headers on every route

Every bundle route now sends `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`,
`X-Robots-Tag: noindex, nofollow`, `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'` (appended to your
`Content-Security-Policy` when it has no `frame-ancestors`, or sent as the only directive when there is no policy).
Hosts that added these headers themselves (e.g. a response subscriber for the secrets pages) can remove that code. To
keep your own headers instead: `nowo_yopass.security_headers.enabled: false`.

### Retention: no purge on GET, new indexes (migration)

- The manage list no longer deletes old shares while rendering. With `shares.retention.enabled`, old shares are removed
  when the user creates a share (their own) and by `php bin/console nowo:yopass:purge-old-shares` (all shares):
  **schedule the command** (cron, Symfony Scheduler) if you relied on the list visit.
- New indexes on the shares table: `created_at` (retention purge) and `expires_at`. Doctrine ORM:

  ```bash
  php bin/console make:migration      # or doctrine:migrations:diff
  # CREATE INDEX <table>_created_at_idx ON <table> (created_at);
  # CREATE INDEX <table>_expires_at_idx ON <table> (expires_at);
  php bin/console doctrine:migrations:migrate
  ```

  `<table>` is your `table_prefix` + `secure_shares` (default `yopass_secure_shares`). MongoDB: run
  `php bin/console doctrine:mongodb:schema:update` (indexes on `createdAt` / `expiresAt`).

### Host list filters

Add filter fields instead of overriding `nowo_ui_list_filters`:

```php
#[AsEventListener(event: YopassEvents::SHARE_LIST_FILTERS)]
public function addFilters(ShareListFiltersEvent $event): void
{
    $event->getFormBuilder()->add('sharedWith', ChoiceType::class, ['required' => false, 'choices' => $this->audiences()]);
}

#[AsEventListener(event: YopassEvents::SHARE_LIST_QUERY)]
public function query(ShareListQueryEvent $event): void
{
    $sharedWith = $event->getFilters()['sharedWith'] ?? null;
    // … $event->overrideList($shares, $total) for that audience and page
}
```

The filter values are user input: validate them (prefer `ChoiceType`), bind them as query parameters, and return
from `overrideList()` only shares the viewer may see (`overrideList()` skips `CreatorSubjectGuard`).

## To 1.12.1

From **1.12.0**:

```bash
composer update nowo-tech/yopass-bundle
```

## To 1.12.0

From **1.11.0** (no breaking change with the default configuration):

```bash
composer update nowo-tech/yopass-bundle
php bin/console assets:install
php bin/console cache:clear
```

`js/yopass.js` changed: run `assets:install` (or your asset pipeline) so the browser gets the new controllers.

### Create in a dialog (opt-in)

```yaml
nowo_yopass:
    web_ui:
        create_mode: dialog   # default: page (1.11 behaviour)
```

- The create card disappears; **New share** (header and empty state) opens the create dialog. The result (links, key,
  copy buttons) is shown inside the dialog; **Done** (or any close) wipes the form, the result and the key, then
  reloads the page. To refresh differently, listen to `nowo-yopass-create:created` (bubbling, cancelable,
  `detail.id`, `null` when the dialog was closed while the request was in flight) and call `event.preventDefault()`.
- **Fetch metadata in both modes:** a create POST whose `Sec-Fetch-Site` header is present and not `same-origin` is
  refused (JSON: `403 {error: CROSS_SITE}`; page mode: 403). Browsers send `same-origin` for the bundle's own forms.
- **CSP:** the dialog posts with a same-origin `fetch`: `connect-src 'self'` (or a `default-src 'self'` fallback) must
  allow it. Nothing else changes (same script, same nonce hook, same `'wasm-unsafe-eval'` routes).
- **JSON endpoint:** `POST` on the create route with `Accept: application/json` answers `201` / `422` / `403` JSON
  (see [Configuration — Create mode](CONFIGURATION.md#create-mode)). Host code calling the route with that header now
  gets JSON instead of a redirect.
- Wording: `yopass.create.open` (New share) and `yopass.create.done` (Done) are new keys; override them in your
  `NowoYopassBundle` catalogue if needed.

### Per-row actions, revoke / extend events, host columns

- **Row actions follow `SHARE_ACCESS_CHECK`.** View / Extend / Revoke / Delete are now built per row through
  `ShareAccessGuard::canManage()` (`Preview`, `Extend`, `Revoke`, `Delete`). A listener that grants only `Preview`
  on someone else's share no longer shows Extend / Revoke / Delete on that row (they answered 404 before). With the
  default creator-only rule nothing visible changes.
- **"Delete all"** is shown only when the viewer owns shares. Hosts that override or re-scope the list can report the
  count with `ShareListQueryEvent::setOwnTotal()`; otherwise the bundle counts the viewer's shares (one extra
  `countByCreator()` when the list is overridden).
- **New events:** `YopassEvents::SHARE_REVOKED` (`ShareRevokedEvent::getRevokedBy()`) and
  `YopassEvents::SHARE_EXTENDED` (`ShareExtendedEvent`, old / new limits) for audit trails; metadata only.
- **Host columns:** listen to `YopassEvents::SHARE_LIST_COLUMNS` and `addColumn(new ShareListColumn('createdBy',
  new Label('secrets.created_by', 'messages'), fn (SecureShare $s) => $names[$s->getId()] ?? null, 2))` instead of
  overriding `nowo_ui_list_head` / `nowo_ui_list_row`. Values are plain text (escaped).
- **Created page:** it reads the key from the creator's `sessionStorage`; do not grant `ShareAccessAction::View` on it to
  other users (they would only see "key unavailable").
- **Retention:** `shares.retention` purges the viewer's own old shares when the list opens; shares of others shown to
  the viewer are purged by `nowo:yopass:purge-old-shares` (schedule it).

### Template overrides

The create form moved to `manage/_create_form.html.twig`, and its `nowo-yopass-create` controller now sits on the
wrapping `<div class="nowo-yopass-create__root">` (it was on the `<form>`). An override of `_create_panel.html.twig`
that still renders the 1.11 form markup keeps working in page mode. Dialog mode needs the bundle's
`_create_form.html.twig` (or an override of it that keeps the `result*` targets). The type switch is rendered only
when file shares are enabled.

## To 1.11.0

From **1.10.1** (no breaking change; every new constructor argument is optional):

```bash
composer update nowo-tech/yopass-bundle
php bin/console cache:clear
```

### Access log table no longer mapped when disabled

With `nowo_yopass.access_log.enabled: false` (and with the MongoDB / custom non-relational backends, which always
disable it) the `Nowo\YopassBundle\Entity\ShareAccessLog` entity is now excluded from the Doctrine ORM mapping
(conditional mapping through `AccessLogMappingPass`). New installs no longer get an empty
`<table_prefix>_share_access_logs` table.

If the table already exists, the next `make:migration` / `doctrine:schema:update --dump-sql` proposes
`DROP TABLE <table_prefix>_share_access_logs`. **Do not apply it blindly:** if the access log was **ever enabled**
(it is the default) the table holds the access history recorded until then (time, read number, client IP and user
agent of every link open), and dropping it deletes that history for good. To keep it, exclude the table from the
schema diff, e.g.:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        schema_filter: ~^(?!yopass_share_access_logs$)~   # adapt to your table_prefix
```

Apply the `DROP TABLE` only when you are sure the history is not needed (or the table is empty). Nothing changes when
the access log is enabled.

### CSP: nonce and wasm-unsafe-eval

- `yopass.js` now carries the CSP nonce when the host sets the request attribute `csp_nonce` (same hook as the UiKit
  layouts). Hosts with a nonce-based `script-src` (`'strict-dynamic'`) need nothing else; without a nonce the tag is
  unchanged.
- libsodium compiles WebAssembly: the three pages that load `yopass.js` need `'wasm-unsafe-eval'` in `script-src`.
  Add it only on the route names in the `nowo_yopass.csp.wasm_routes` parameter (by default `nowo_yopass_index`,
  `nowo_yopass_created`, `nowo_yopass_public_share`; renamed routes are followed), or opt in to
  `nowo_yopass.csp.wasm_unsafe_eval: true` to let the bundle append it to your existing policy on those routes. See
  [Configuration — Content-Security-Policy](CONFIGURATION.md#content-security-policy).

### New events

- `YopassEvents::SHARE_CREATED`, `SHARE_DELETED`, `SHARES_BULK_DELETED`: audit / notification hooks with metadata
  only (never ciphertext, keys, passwords or plaintext).
- `YopassEvents::SHARE_DELETE_ALL`: take over (tenant / team scope with your own query + `markHandled()`) or veto
  the manage "delete all". Hosts that replaced the `nowo_yopass_delete_all` route to scope it can drop the override
  and listen to this event instead. `setSubject()` only accepts the viewer or another `nowo_yopass.user_class`
  instance; any other object throws a `LogicException` before anything is removed (a tenant entity would otherwise be
  matched by id against user ids). Veto **before** handling: `veto()` after `markHandled()` is ignored and logged.
- **Behaviour change for `ShareListQueryEvent::setListSubject()`:** a subject that is neither the viewer nor a
  `nowo_yopass.user_class` instance now throws a `LogicException` unless the listener also calls `overrideList()`.
  Up to 1.10 such a subject (team / tenant entity) was silently matched by its id against creator ids. A custom
  `ShareRepositoryInterface` that understood team subjects must now be fed through `overrideList()`.
- `ShareListQueryEvent` exposes `getPage()` / `getPageSize()` / `getOffset()` / `isPaginated()`; `overrideList()`
  listeners should return only the requested page when paginated.

### Translation wording

Spanish (and German, Italian, Portuguese, Dutch, French) wording changed ("share" → "secreto", "Freigabe", …). Hosts
overriding these keys in their own `NowoYopassBundle.<locale>` catalogue keep their values. New key:
`yopass.error.delete_all_denied`.

## To 1.10.1

From **1.10.0**:

```bash
composer update nowo-tech/yopass-bundle
```

No action needed when you import the `nowo_yopass` route loader (`resource: .`, `type: nowo_yopass`, the documented setup). If on Symfony >= 7.3 the share pages answered without that import (through the default `controllers: { resource: routing.controllers }` of `config/routes.yaml`), they are gone now: import the loader (see [INSTALLATION](INSTALLATION.md)).

## To 1.10.0

From **1.9.0**:

```bash
composer update nowo-tech/yopass-bundle
```

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

**Security:** the reveal page now renders inside your guest layout. That layout (or `web_ui.public_layout_template`)
must **not load third-party scripts** (analytics, tag manager, session replay, chat widgets): they could read the
decryption key and the decrypted secret. Point `web_ui.public_layout_template` at a dedicated minimal layout if your
site-wide guest layout carries them. The reveal and "share created" pages now send `Cache-Control: no-store, private`
and `Referrer-Policy: no-referrer`. See [Usage — layout security](USAGE.md#public-reveal-page-layout-security).

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
