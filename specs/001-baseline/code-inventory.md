# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/yopass-bundle`  
**Last audited**: 2026-11-16 (creator mapping, private entity manager, events, hardening listeners, UiKit pages; audit: `git ls-files src | grep -v '\.test\.ts$' | wc -l` → 153; 2026-11-19: +4 concurrency units, REQ-RUNTIME-003)

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and `*.test.ts` under `src/` are out of Packagist scope. Built assets under `Resources/public/` are documented as Vite/build outputs.

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/FileHandlerPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Compiler/PublicRateLimitCachePass.php` | Compiler pass | FR-SEC-002 |
| `DependencyInjection/Compiler/ShareFileHandlerPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/YopassExtension.php` | DI extension | FR-CFG-002 |
| `YopassBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Compiler/ManageWebUiSecurityPass.php` | Manage UI SecurityBundle guard | FR-UI-002 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/PurgeOldSharesCommand.php` | CLI maintenance | FR-CLI-004 |

## Controllers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/PublicShareController.php` | Public reveal controller | FR-PUB-001 |
| `Controller/ShareManageController.php` | Share manage controller | FR-UI-001 |

## Persistence

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Document/SecureShareDocument.php` | Persistence model | FR-ENTITY-001 |
| `Entity/SecureShare.php` | Persistence model | FR-ENTITY-001 |
| `Entity/ShareAccessLog.php` | Persistence model | FR-ENTITY-001 |
| `Repository/DoctrineMongoShareRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/DoctrineOrmShareAccessLogRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/DoctrineOrmShareRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/NullShareAccessLogRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/ShareAccessLogRepositoryInterface.php` | Repository contract | FR-REPO-001 |
| `Repository/ShareRepositoryInterface.php` | Repository contract | FR-REPO-001 |
| `Repository/ResolvesDocumentManagerTrait.php` | Per-call DocumentManager (closed-manager reset) | FR-WORKER-001, FR-REPO-001 |
| `Repository/ResolvesEntityManagerTrait.php` | Per-call EntityManager (closed-manager reset; the bundle's own manager when set) | FR-WORKER-001, FR-REPO-001 |
| `Doctrine/YopassEntityManager.php` | Bundle-owned private EntityManager, boundary clear (1.19.0) | FR-WORKER-001, FR-REPO-001 |

## Forms

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/ShareCreateType.php` | Symfony form type | FR-FORM-001 |

## Domain models

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Dto/ShareCreateData.php` | Transfer object | FR-DTO-001 |
| `Ui/CssFramework.php` | CSS stack enum | FR-UI-002 |
| `Ui/IconSet.php` | Icon set enum | FR-UI-002 |

## Application services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Event/ShareAccessAction.php` | Domain events | FR-EVT-001 |
| `Event/ShareAccessCheckEvent.php` | Domain events | FR-EVT-001 |
| `Event/ShareListQueryEvent.php` | Domain events | FR-EVT-001 |
| `Event/ShareListResultEvent.php` | Domain events | FR-EVT-001 |
| `Event/YopassEvents.php` | Domain events | FR-EVT-001 |
| `Resources/assets/src/yopass-crypto.ts` | Client-side E2E crypto | FR-CRYPT-002 |
| `Resources/assets/src/yopass-share-keys.ts` | Share key helpers | FR-CRYPT-002 |
| `Resources/assets/src/yopass-share-url.ts` | Share URL builder | FR-SHARE-008 |
| `Resources/assets/src/yopass.ts` | Yopass UI bootstrap | FR-UI-010 |
| `Service/DefaultShareFileHandler.php` | Default file attachment handler | FR-FILE-001 |
| `Service/ShareAccessLogger.php` | Share access audit log | FR-AUDIT-001 |
| `Service/ShareCreator.php` | Share create | FR-SHARE-001 |
| `Service/ShareExtender.php` | Share TTL extend | FR-SHARE-004 |
| `Service/ShareFileHandlerInterface.php` | Application service | FR-SVC-001 |
| `Service/ShareLister.php` | Share list for owner | FR-SHARE-003 |
| `Service/ShareRetentionPurger.php` | Expired share purge | FR-RET-001 |
| `Service/ShareRetriever.php` | Share retrieve/decrypt | FR-SHARE-002 |

## Security

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/ConfigurableYopassAccessChecker.php` | Configurable access checker | FR-SEC-001 |
| `Security/PublicEndpointRateLimiter.php` | Rate limiting | FR-SEC-002 |
| `Security/ShareEncryptionService.php` | Payload encryption | FR-CRYPT-001 |
| `Security/YopassAccessCheckerInterface.php` | Access checker contract (deprecated since 1.19.0) | FR-SEC-001 |
| `Security/YopassAccessPolicyInterface.php`, `Security/UserRef.php` | Framework-free access policy port and user VO (1.19.0) | FR-SEC-001 |
| `Security/YopassAccessDecider.php` | Calls the policy (UserRef) or the legacy checker (1.19.0) | FR-SEC-001 |
| `Service/ShareAccessGuard.php` | Share access guard | FR-SEC-001 |

## Routing

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Routing/YopassRouteLoader.php` | Route loader | FR-ROUTE-001 |

## Persistence integration

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Database/DatabaseDriver.php` | Persistence integration | FR-DB-001 |
| `Doctrine/SecureShareDocumentMetadataListener.php` | Persistence integration | FR-DB-001 |
| `Doctrine/SecureShareMetadataListener.php` | Persistence integration | FR-DB-001 |

## Support utilities

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Support/UserIdResolver.php` | Support utility | FR-UTIL-001 |
| `ValueObject/Uuid.php` | Support utility | FR-UTIL-001 |

## Exceptions

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Exception/ShareExtendException.php` | Domain exception | FR-ERR-001 |

## Frontend TypeScript

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/logger.ts` | Frontend logger | FR-UI-012 |
| `Resources/assets/src/share-reveal-controller.ts` | Public reveal Stimulus | FR-PUB-002 |
| `Resources/assets/src/yopass-create-controller.ts` | Create share Stimulus | FR-SHARE-005 |
| `Resources/assets/src/yopass-created-controller.ts` | Post-create Stimulus | FR-SHARE-006 |
| `Resources/assets/src/yopass-manage-preview-controller.ts` | Manage preview Stimulus | FR-SHARE-007 |
| `Resources/public/js/yopass.js` | Built frontend asset | FR-BUILD-001 |

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoYopassBundle.de.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoYopassBundle.en.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoYopassBundle.es.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoYopassBundle.fr.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoYopassBundle.it.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoYopassBundle.nl.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoYopassBundle.pt.yaml` | i18n messages | FR-I18N-004 |

## Twig views

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/manage/created.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/manage/index.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/public/reveal.html.twig` | Public reveal template | FR-VIEW-006 |
| `Twig/YopassTwigExtension.php` | Manage UI Twig globals | FR-UI-002 |
| `Resources/views/layout_integrate_host.html.twig` | Host layout bridge | FR-UI-002 |

## Creator mapping

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Creator/CreatorClassPolicy.php` | Which security users map to a creator by `getId()` (`user_class` plus `accepted_user_classes`); other identity domains fail closed | FR-CREATOR-001 |
| `Creator/CreatorMapping.php` | Values of `nowo_yopass.user_mapping` | FR-CREATOR-001 |
| `Creator/CreatorId.php` | Creator known only by id (`user_mapping: id`); exposes `getId()` like a host user | FR-CREATOR-001 |
| `Creator/CreatorReferenceResolverInterface.php` | Port: user handed to the bundle to stored creator object and scalar id | FR-CREATOR-002 |
| `Creator/DoctrineCreatorReferenceResolver.php` | Default resolver: `user_class` unchanged, accepted classes become a Doctrine reference (no query) or a `CreatorId`, others refused | FR-CREATOR-002 |
| `Doctrine/HostCreatorReference.php` | Creator exposed by lifecycle events as the same user in the application's own manager | FR-CREATOR-003 |
| `Support/CreatorSubjectGuard.php` | Accepts only the viewer or an instance of the creator class as subject of creator queries | FR-CREATOR-004 |
| `Repository/CreatorIdShareRepositoryInterface.php` | Creator queries by plain id (find, count, remove all) for erasure handlers | FR-CREATOR-005 |

## Compiler passes (security and mapping)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/AccessCheckerAliasPass.php` | Keeps the access interface aliases only when the configured checker implements them | FR-DI-003 |
| `DependencyInjection/Compiler/AccessLogMappingPass.php` | Excludes `ShareAccessLog` from ORM metadata drivers when the access log is disabled, non-relational or host-provided | FR-DI-003 |
| `DependencyInjection/Compiler/CsrfProtectionPass.php` | Fails the container build when the manage UI is on without form CSRF protection | FR-SEC-003 |

## Private entity manager and worker safety

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/PrivateEntityManagerPass.php` | Attaches the rollback middleware only to the private manager's connection | FR-WORKER-003 |
| `DependencyInjection/Compiler/YopassCommitTrackingPass.php` | Scopes the commit-tracking middleware to the bundle's connection, or reuses transaction-doctrine's ledger | FR-DB-005 |
| `Doctrine/PrivateEntityManagerMiddleware.php` | DBAL middleware that reports rolled-back transactions and savepoints | FR-WORKER-003 |
| `Doctrine/PrivateEntityManagerDriver.php` | Wraps the driver connections of the private manager | FR-WORKER-003 |
| `Doctrine/PrivateEntityManagerConnection.php` | Reports rollbacks, failed commits and savepoint rollbacks | FR-WORKER-003 |
| `Doctrine/PendingWork.php` | Counts scheduled inserts, updates and deletes of a manager, including uncomputed changes | FR-WORKER-003 |
| `Doctrine/UnitOfWorkFrames.php` | Tracks units in flight (requests, messages, commands) so only the outermost boundary acts | FR-WORKER-002 |
| `Doctrine/YopassEntityManagerBoundarySubscriber.php` | Drops the private manager at every outermost boundary so no row stays managed in a kept-alive kernel | FR-WORKER-002 |
| `Doctrine/PrivateManagerRepositoryFactory.php` | Repository factory owned by one manager so replaced managers are collected | FR-WORKER-002 |
| `Doctrine/YopassSavepoint.php` | Runs bulk DQL writes inside a savepoint when the application has a transaction open | FR-DB-002 |
| `Doctrine/ShareWriteUnit.php` | One transaction per write unit (consume + access log, extend) through transaction-doctrine's runner | FR-DB-004 |
| `Doctrine/ExactCollation.php` | Binary NO PAD collation for exact identifier matching on MySQL / MariaDB | FR-DB-003 |

## Lifecycle and list events

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Event/ShareCreatedEvent.php` | After a share was persisted; metadata only | FR-EVT-002 |
| `Event/ShareConsumedEvent.php` | After one read was consumed; ids and counters only | FR-EVT-002 |
| `Event/ShareRevokedEvent.php` | After a manage-UI revoke; metadata only | FR-EVT-002 |
| `Event/ShareDeletedEvent.php` | After a single manage-UI delete; metadata only | FR-EVT-002 |
| `Event/ShareExtendedEvent.php` | After expiration or max reads were extended | FR-EVT-002 |
| `Event/ShareBulkDeletedEvent.php` | After a bulk removal (delete all, retention purge); scope and count only | FR-EVT-002 |
| `Event/ShareDeleteAllEvent.php` | Before "delete all": narrow the subject, veto or mark as handled | FR-EVT-003 |
| `Event/ShareListColumnsEvent.php` | Hosts append extra list columns | FR-EVT-004 |
| `Event/ShareListFiltersEvent.php` | Hosts add GET filter fields to the manage list | FR-EVT-004 |
| `Event/ShareListRouteEvent.php` | A host page sets the route that shows the list | FR-EVT-004 |

## HTTP hardening listeners

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `EventListener/SecurityHeadersListener.php` | `no-store`, `no-referrer`, `noindex`, `X-Frame-Options` and `frame-ancestors` on every bundle route | FR-SEC-004 |
| `EventListener/ShareKeyQueryRedirectListener.php` | Redirects `?decrypt_key=` / `?key=` / `?password=` to the URL fragment (303) before routing | FR-SEC-005 |
| `EventListener/YopassConflictListener.php` | 409 / 503 + `CONFLICT_001` / `CONFLICT_002` for lock conflicts on the bundle's routes | FR-DB-005 |
| `EventListener/WasmCspListener.php` | Opt-in `'wasm-unsafe-eval'` on existing CSP headers of the pages that load `yopass.js` | FR-SEC-006 |

## Browser-only forms

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/ClientOnlyFieldsTrait.php` | Gives browser-only children an element id and an empty name so they are never posted | FR-FORM-002 |
| `Form/CsrfActionType.php` | CSRF-only form for revoke, delete and delete-all row actions | FR-FORM-002 |
| `Form/ShareCreatedLinksType.php` | Read-only result fields of the created page | FR-FORM-002 |
| `Form/SharePreviewType.php` | Preview and extend modal controls | FR-FORM-002 |
| `Form/ShareRevealType.php` | Public reveal password or key input | FR-FORM-002 |

## Services and support (lifecycle, retention, intervals)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Repository/ExpirationExtendingShareRepositoryInterface.php` | Optional conditional expiration update that only moves forward | FR-DB-004 |
| `Repository/MaxReadsRaisingShareRepositoryInterface.php` | Optional atomic raise of max reads (`reads_left = reads_left + delta`) | FR-REPO-003 |
| `Service/ShareConsumedNotifier.php` | Dispatches `SHARE_CONSUMED` after a stored read; a throwing listener is logged and ignored | FR-SHARE-009 |
| `Service/ShareDeleter.php` | Deletes one share or all of a viewer's shares and dispatches the events | FR-SHARE-010 |
| `Service/ShareRevoker.php` | Revokes a share and dispatches `SHARE_REVOKED` | FR-SHARE-010 |
| `Service/ShareListRouteResolver.php` | Resolves the list page and carries it through action forms as an HMAC-signed token | FR-UI-013 |
| `Support/RelativeInterval.php` | Expiration and retention interval helpers (PHP relative modifiers) | FR-RET-002 |

## UiKit page contract

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Ui/ListRoute.php` | Route name and scalar parameters of the page that shows the list | FR-UI-013 |
| `Ui/ShareListColumn.php` | Host-provided extra list column; plain text, escaped | FR-UI-014 |
| `Ui/YopassPageFactory.php` | Builds the UiKit view models of the Yopass pages | FR-UI-015 |
| `Ui/YopassPages.php` | Resolves layouts and per-page template overrides; wraps 1.x layouts in a deprecated bridge | FR-UI-015 |
| `Ui/RetentionNotice.php` | Translated label of the retention notice (ICU plurals for common intervals) | FR-UI-016 |

## Doctrine XML mappings

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/doctrine/shares_and_access_log/SecureShare.orm.xml` | `SecureShare` mapping used with the ORM access log | FR-ENTITY-002 |
| `Resources/config/doctrine/shares_and_access_log/ShareAccessLog.orm.xml` | `ShareAccessLog` mapping | FR-ENTITY-002 |
| `Resources/config/doctrine/shares_only/SecureShare.orm.xml` | `SecureShare` mapping without the access log | FR-ENTITY-002 |

## Frontend helpers, styles and templates

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/visibility.ts` | Show / hide helpers using the `hidden` attribute and inline `display: none !important` | FR-UI-017 |
| `Resources/assets/src/yopass-navigation.ts` | `reloadPage()` isolated so tests can replace it | FR-UI-017 |
| `Resources/assets/src/yopass-share-links.ts` | Builds and renders short and one-click links, clears them, copies to clipboard | FR-SHARE-006 |
| `Resources/assets/src/yopass-toast.ts` | Short UiKit toast inside a container such as an open dialog | FR-UI-017 |
| `Resources/public/css/yopass.css` | Layout layer for the bundle's `nowo-yopass-*` hooks on UiKit tokens; no inline styles | FR-UI-018 |
| `Resources/translations/NowoYopassBundle+intl-icu.de.yaml` | ICU retention duration plurals (de) | FR-I18N-005 |
| `Resources/translations/NowoYopassBundle+intl-icu.en.yaml` | ICU retention duration plurals (en) | FR-I18N-005 |
| `Resources/translations/NowoYopassBundle+intl-icu.es.yaml` | ICU retention duration plurals (es) | FR-I18N-005 |
| `Resources/translations/NowoYopassBundle+intl-icu.fr.yaml` | ICU retention duration plurals (fr) | FR-I18N-005 |
| `Resources/translations/NowoYopassBundle+intl-icu.it.yaml` | ICU retention duration plurals (it) | FR-I18N-005 |
| `Resources/translations/NowoYopassBundle+intl-icu.nl.yaml` | ICU retention duration plurals (nl) | FR-I18N-005 |
| `Resources/translations/NowoYopassBundle+intl-icu.pt.yaml` | ICU retention duration plurals (pt) | FR-I18N-005 |
| `Resources/views/_legacy_layout.html.twig` | Deprecated bridge wrapping a 1.x host layout (removed in 2.0) | FR-VIEW-001 |
| `Resources/views/manage/_create_controller_attrs.html.twig` | Attributes of the `nowo-yopass-create` controller root | FR-VIEW-007 |
| `Resources/views/manage/_create_dialog.html.twig` | Create dialog (`web_ui.create_mode: dialog`) | FR-VIEW-007 |
| `Resources/views/manage/_create_form.html.twig` | Create form shared by page and dialog modes | FR-VIEW-007 |
| `Resources/views/manage/_create_panel.html.twig` | Create card of the manage page (`create_mode: page`) | FR-VIEW-007 |
| `Resources/views/manage/_preview_dialog.html.twig` | Preview / extend dialog filled by the manage-preview controller | FR-VIEW-007 |

## Coverage summary

| Category | Mapped |
| --- | ---: |
| Bundle & DI | 8 |
| CLI | 1 |
| Controllers | 2 |
| Persistence | 12 |
| Forms | 1 |
| Domain models | 3 |
| Application services | 17 |
| Security | 8 |
| Routing | 1 |
| Persistence integration | 3 |
| Support utilities | 2 |
| Exceptions | 1 |
| Frontend TypeScript | 6 |
| Symfony config | 1 |
| Translations | 7 |
| Twig views | 5 |
| Creator mapping | 8 |
| Compiler passes (security and mapping) | 3 |
| Private entity manager and worker safety | 10 |
| Lifecycle and list events | 10 |
| HTTP hardening listeners | 3 |
| Browser-only forms | 5 |
| Services and support (lifecycle, retention, intervals) | 6 |
| UiKit page contract | 5 |
| Doctrine XML mappings | 3 |
| Frontend helpers, styles and templates | 18 |
| **Total** | **149** |

Audit: `git ls-files src | grep -v '\.test\.ts$' | wc -l` → 149 (co-located `*.test.ts` Vitest files excluded).
