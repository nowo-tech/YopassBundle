# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/yopass-bundle`  
**Last audited**: 2026-07-07

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and `*.test.ts` under `src/` are out of Packagist scope. Built assets under `Resources/public/` are documented as Vite/build outputs.

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/FileHandlerPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Compiler/ShareFileHandlerPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/YopassExtension.php` | DI extension | FR-CFG-002 |
| `YopassBundle.php` | Bundle entry | FR-BUNDLE-001 |

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

## Forms

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/ShareCreateType.php` | Symfony form type | FR-FORM-001 |

## Domain models

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Dto/ShareCreateData.php` | Transfer object | FR-DTO-001 |

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
| `Security/YopassAccessCheckerInterface.php` | Access checker contract | FR-SEC-001 |
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
| `Resources/views/layout.html.twig` | Layout template | FR-VIEW-001 |
| `Resources/views/manage/created.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/manage/index.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/public/reveal.html.twig` | Public reveal template | FR-VIEW-006 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 6 | 6 |
| CLI | 1 | 1 |
| Controllers | 2 | 2 |
| Persistence | 9 | 9 |
| Forms | 1 | 1 |
| Domain models | 1 | 1 |
| Application services | 17 | 17 |
| Security | 5 | 5 |
| Routing | 1 | 1 |
| Persistence integration | 3 | 3 |
| Support utilities | 2 | 2 |
| Exceptions | 1 | 1 |
| Frontend TypeScript | 6 | 6 |
| Symfony config | 1 | 1 |
| Translations | 7 | 7 |
| Twig views | 4 | 4 |
| **Total production sources** | **67** | **67** |

Audit: `find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' | wc -l`
