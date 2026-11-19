# Feature Specification: YopassBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

---

## Summary

**Package**: `nowo-tech/yopass-bundle`  
**Configuration root**: `yopass`


Symfony bundle for **Yopass-style E2E encrypted secret sharing**: client-side encryption, one-time or limited reads, expiration, revoke, optional file attachments, and configurable routes/ACL.

---

## User Scenarios & Testing

Per SDD US-01–US-04: create share with `#key`, public reveal, owner revoke/extend, integrator route and access configuration.

---

## Requirements

### Share lifecycle

- **FR-SHARE-001–008**: Create, retrieve, list, extend shares; Stimulus controllers for create/reveal/manage flows; share URL/key helpers.
- **FR-CRYPT-002**: Browser `yopass-crypto.ts` E2E encrypt/decrypt (server stores ciphertext only).
- **FR-SEC-001 / FR-SEC-002**: Access checker and public endpoint rate limiter. The limiter MUST receive `cache.app` (and `logger`) as optional references resolved on the merged container, never via `has()` inside the extension (isolated container); `PublicRateLimitCachePass` MUST warn when `public_rate_limit.enabled` is true and `cache.app` is missing.
- **FR-AUDIT-001**: Access logging via `ShareAccessLogger`.
- **FR-RET-001**: `ShareRetentionPurger` + `PurgeOldSharesCommand`.
- **FR-FILE-001**: Pluggable `ShareFileHandlerInterface` with default handler and compiler passes.

### Persistence

- **FR-ENTITY-001**: ORM `SecureShare`, `ShareAccessLog`; optional Mongo `SecureShareDocument`.
- **FR-REPO-001 / FR-REPO-002**: Share and access-log repositories (ORM + Mongo + null log impl).
- **FR-DB-001**: Metadata listeners for table prefix / Mongo mapping.
- **FR-WORKER-001**: Repositories resolve the EntityManager / DocumentManager per call and replace one closed by an earlier failed flush (`ResolvesEntityManagerTrait`, `ResolvesDocumentManagerTrait`), so a long-running worker without kernel reset keeps working.
- **FR-EVT-001**: Access and list query/result events for ACL extension.

### HTTP & UI

- **FR-PUB-001 / FR-PUB-002**: Public reveal controller and Stimulus reveal flow.
- **FR-UI-001**: Manage controller and templates (index, created, reveal).
- **FR-UI-002**: Manage Web UI chrome (REQ-UI-001): `YopassTwigExtension` exposes the layout / CSS framework / icon set globals (`Ui\CssFramework`, `Ui\IconSet` enums), `layout_integrate_host.html.twig` bridges the host layout and `_action_icons.html.twig` renders the view / extend / revoke / delete actions; `ManageWebUiSecurityPass` fails the build when the manage UI requires authentication but SecurityBundle is missing.
- **FR-FORM-001**: Share create form.
- **FR-ROUTE-001**: Configurable `YopassRouteLoader`.
- **FR-UI-010 / FR-BUILD-001**: Bundled JS assets.

### Creator mapping (`FR-CREATOR-*`)

- **FR-CREATOR-001**: `nowo_yopass.user_mapping` selects how a share stores its creator (a relation to `user_class`, or the creator id only through `CreatorId`); `CreatorClassPolicy` maps only `user_class` instances and the classes listed in `accepted_user_classes` by `getId()`, so a user of another identity domain is never a creator or owner (fail closed; ids of 0, negative and empty are refused).
- **FR-CREATOR-002**: `CreatorReferenceResolverInterface` turns the user handed to the bundle into the stored creator and its scalar id; the default Doctrine resolver returns a `getReference()` (no query) or a `CreatorId` and refuses any other user.
- **FR-CREATOR-003**: Lifecycle events expose the creator as the same user in the application's own manager (`HostCreatorReference`), built lazily from the stored id.
- **FR-CREATOR-004**: `CreatorSubjectGuard` accepts only the viewer or an instance of the configured creator class as the subject of creator queries, so another entity with an equal id cannot select or delete a user's shares.
- **FR-CREATOR-005**: `CreatorIdShareRepositoryInterface` offers find, count and remove-all by plain creator id for hosts that only know the id (user-deleted handlers, erasure).

### Lifecycle, retention and list services

- **FR-SHARE-009**: `ShareConsumedNotifier` dispatches `SHARE_CONSUMED` only after a read was stored; a throwing listener is logged (share id and class) and ignored.
- **FR-SHARE-010**: `ShareDeleter` and `ShareRevoker` delete or revoke from the manage UI and dispatch the lifecycle events with metadata only.
- **FR-REPO-003**: A repository may implement `MaxReadsRaisingShareRepositoryInterface` to raise max reads atomically (`reads_left = reads_left + delta`) so concurrent reads are never given back.
- **FR-RET-002**: `RelativeInterval` parses `shares.expiration_options` and `shares.retention.max_age` relative modifiers (plain terms, negation, seconds, retention cutoff).

### Events (`FR-EVT-*`)

- **FR-EVT-002**: Share created, consumed, revoked, deleted, extended and bulk-deleted events carry ids, dates and counters only, never ciphertext, key, password, decrypted content or the `SecureShare` entity.
- **FR-EVT-003**: `ShareDeleteAllEvent` lets a listener narrow the subject (only the viewer or the creator class), veto or mark the removal as handled.
- **FR-EVT-004**: List events let hosts append columns (plain text, escaped), add GET filter fields (UiKit filters region, no CSRF token) and set the route of the page that shows the list.

### Private entity manager and worker mode (`FR-WORKER-*`, `FR-DB-*`)

- **FR-WORKER-002**: The private entity manager is dropped at every outermost unit-of-work boundary (request, message, command; tracked by `UnitOfWorkFrames`), so no row stays managed and no unflushed change of an abandoned unit is written later; application managers are never touched; each manager has its own repository factory so replaced managers are collected.
- **FR-WORKER-003**: A DBAL middleware registered only on the private manager's connection reports rolled-back transactions and savepoints so a stale identity map is replaced before the next write; `PendingWork` counts the work a boundary discards.
- **FR-DB-002**: Bulk DQL writes run inside a savepoint when the application already opened a transaction, so a failing bundle statement never aborts the application's transaction.
- **FR-DB-003**: On MySQL / MariaDB identifier columns use a binary NO PAD collation (`utf8mb4_nopad_bin`, `utf8mb4_0900_bin`, else `utf8mb4_bin` with a warning); PostgreSQL and SQLite get none.
- **FR-DB-004** (1.21.0, REQ-RUNTIME-003): the public consume (the read and its access log row) and the extension (the max reads raise and the new expiration) each run in one transaction of `ShareWriteUnit` (transaction-doctrine's runner on the private manager); events and the ciphertext come after the commit; the expiration only moves forward (`ExpirationExtendingShareRepositoryInterface`); the retention purge deletes in chunks of 500 primary keys.
- **FR-DB-005** (1.21.0, REQ-RUNTIME-003): a lock conflict on a route of the bundle answers 409 (configurable to 503) with `X-Conflict-Code` `CONFLICT_001` + `Retry-After` when nothing was committed, or `CONFLICT_002` without `Retry-After` when part of the request may be (`YopassConflictListener`, commit tracking scoped by `YopassCommitTrackingPass`); unique violations and lost connections are not mapped.
- **FR-ENTITY-002**: XML mappings of `SecureShare` and `ShareAccessLog` mirror the attribute mapping for the two Doctrine configurations (with and without the ORM access log).

### Container and HTTP hardening (`FR-DI-*`, `FR-SEC-*`)

- **FR-DI-003**: The access interface aliases point at the configured checker only when its class implements them; `AccessLogMappingPass` excludes `ShareAccessLog` from ORM drivers when the access log is disabled, non-relational or host-provided.
- **FR-SEC-003**: The container build fails when the manage UI is enabled without form CSRF protection.
- **FR-SEC-004**: `security_headers.enabled` (default true) sets `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`, `X-Frame-Options: DENY` and a `frame-ancestors 'none'` CSP on every bundle route.
- **FR-SEC-005**: Links carrying `?decrypt_key=`, `?key=` or `?password=` are answered with a 303 to the same path with the material in the URL fragment, before routing, so the key leaves the address bar and later requests.
- **FR-SEC-006**: `csp.wasm_unsafe_eval: true` adds only `'wasm-unsafe-eval'` to existing CSP headers of the pages that load `yopass.js`; a policy is never created.

### Forms, UI page contract and frontend

- **FR-FORM-002**: Browser-only form types (`ShareCreatedLinksType`, `SharePreviewType`, `ShareRevealType`, helpers in `ClientOnlyFieldsTrait`) have fixed ids and no name so secrets and keys are never posted; `CsrfActionType` carries only the CSRF token of the revoke, delete and delete-all actions.
- **FR-UI-013**: `ShareListRouteResolver` carries the list page through action forms as an HMAC-SHA256 token bound to the viewer and accepts only the manage route or a route in `web_ui.list_routes` with scalar parameters.
- **FR-UI-014**: Extra list columns come from `ShareListColumn` providers and are printed escaped.
- **FR-UI-015**: `YopassPageFactory` and `YopassPages` build UiKit view models, resolve `web_ui.layout_template` / `public_layout_template` and per-page overrides, and wrap 1.x layouts in a deprecated bridge (`_legacy_layout.html.twig`, removed in 2.0).
- **FR-UI-016**: `RetentionNotice` turns common `retention.max_age` modifiers into a translated duration (ICU plurals) and omits the duration for others.
- **FR-UI-017**: Frontend helpers `visibility.ts`, `yopass-navigation.ts` and `yopass-toast.ts` show / hide with the `hidden` attribute (no utility classes), isolate page reload and show a UiKit toast inside an open dialog.
- **FR-UI-018**: `css/yopass.css` is the layout layer for the `nowo-yopass-*` hooks on UiKit tokens, with no inline styles.
- **FR-VIEW-007**: Manage templates `_create_form`, `_create_panel`, `_create_dialog`, `_create_controller_attrs` and `_preview_dialog` implement the page and dialog create modes and the preview / extend dialog.
- **FR-I18N-005**: `NowoYopassBundle+intl-icu` catalogues (en, es, fr, de, it, nl, pt) hold the ICU plurals of the retention duration.

---

## Success Criteria

- **SC-001**: 100% of production files in `src/` appear in [`code-inventory.md`](code-inventory.md) with requirement IDs (149/149 mapped; the 13 co-located `*.test.ts` Vitest files are excluded).
- **SC-002**: Configuration keys in `docs/CONFIGURATION.md` match `Configuration.php`.
- **SC-003**: `composer qa` / `make release-check` pass in CI (PHPUnit, PHPStan, Vitest where applicable).
- **SC-004**: No Packagist-visible behavior change without spec, inventory, and test updates.

---

## Validation

| Check | Command |
| --- | --- |
| Full QA | `make release-check` or `composer qa` |
| Code inventory audit | `git ls-files src \| grep -v '\\.test\\.ts$' \| wc -l` |
| TS tests | `pnpm test` or `make test-ts` (when assets present) |

When changing behavior, update this spec, `code-inventory.md`, integrator docs, and tests.
