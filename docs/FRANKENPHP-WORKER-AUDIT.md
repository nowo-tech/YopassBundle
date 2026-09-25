# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/yopass-bundle` (`symfony-bundle`) |
| Audited revision | `v1.4.6` |
| Audit date | 2026-09-25 |
| Method | Manual review of every PHP file under `src/` (controllers, services, repositories, Doctrine listeners, form type / model transformers, route loader, Twig extension, command, DI extension, compiler passes, `Resources/config/services.yaml`) + PHPStan `ruleset-worker-no-kernel-reset.neon` |
| **Verdict** | ✅ **Viable under scenario B** (after remediation) — the bundle's own services are stateless; share reads always hit the database; a closed EntityManager / DocumentManager is replaced on the next call. Clearing the identity map between requests (memory) remains the application's responsibility |
| Remediation (2026-09-23 / 2026-09-25) | W-01 resolved (ORM + MongoDB registry reopen), W-02 accepted (host cache configuration), W-03 `clear()` note resolved. Form `ShareCreateType` model transformers / PRE_SUBMIT are request-scoped closures; FormKit `withBuilder()` restores bound builder in `finally` (no SearchForm-style sticky model setters on the shared form type). Regression tests simulate consecutive requests on the same repository instances without `reset()` |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | All bundle services are `readonly` (or only hold `readonly` promoted config); no memoized caches or `$this->current*` |
| Static properties / `static` locals | ✅ | Only pure static helpers (`UserIdResolver`, `DatabaseDriver`, `Uuid`, UI enums); no static properties |
| `ResetInterface` / `kernel.reset` coverage | ✅ N/A | Nothing in the bundle needs resetting; Doctrine managers are reset by Doctrine's own registries |
| Request / user / locale captured in services | ✅ | User comes from `getUser()` / `Security::isGranted()` per call; `Request` is always a method argument |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used; config is compiled into container parameters |
| Doctrine / EntityManager | ✅ (resolved, bundle side) | ORM repositories resolve the manager from `ManagerRegistry` per call and reset it when closed; `find()` refreshes (ORM `HINT_REFRESH`, ODM `refresh()`); the bundle no longer calls `clear()` |
| Output, headers, `exit`, shutdown functions | ✅ | None; controllers return `Response` objects |
| Resources (files, sockets, cURL) held open | ✅ | None (DB connections are owned by Doctrine) |
| Memory growth across requests | ⚠️ Low (B only, application-owned) | Only via the Doctrine identity map when nothing clears it (one share + one access log per public open); the bundle does not clear the application's manager |
| Blocking I/O and timeouts | ✅ | Only database / MongoDB queries; no HTTP, DNS, `Process` or `sleep` |
| Third-party static state | ✅ | FormKit `FormOptionsTrait` restores its bound builder in `finally`; nothing else |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Controller\ShareManageController` | yes (public) | none (`readonly` deps + config arrays) | ✅ | ✅ (own state) |
| `Controller\PublicShareController` | yes (public) | none | ✅ | ✅ (own state) |
| `Security\ConfigurableYopassAccessChecker` (`nowo_yopass.access_checker.default`) | yes | none; asks `Security::isGranted()` on every call | ✅ | ✅ |
| `Security\PublicEndpointRateLimiter` | yes | none; counters live in `cache.app` keyed by action + client IP hash | ✅ | ✅ (see W-02) |
| `Service\ShareAccessGuard`, `ShareLister` | yes | none; events are created per call | ✅ | ✅ |
| `Service\ShareCreator`, `ShareExtender`, `ShareRetriever`, `ShareRetentionPurger` | yes | none (`readonly` config + `Clock`) | ✅ | ✅ (own state) |
| `Service\ShareAccessLogger` | yes | none (`readonly` bool + repository) | ✅ | ✅ (own state) |
| `Repository\DoctrineOrmShareRepository` / `DoctrineOrmShareAccessLogRepository` | yes | none itself; resolves the EntityManager from `doctrine` per call, reset when closed | ✅ | ✅ |
| `Repository\DoctrineMongoShareRepository` | yes | none itself; resolves DocumentManager from `doctrine_mongodb` per call, reset when closed; `find()` refreshes | ✅ | ✅ |
| `Repository\NullShareAccessLogRepository` | yes | none | ✅ | ✅ |
| `Doctrine\SecureShareMetadataListener` / `SecureShareDocumentMetadataListener` | yes | none (`readonly` table / class names); only runs on `loadClassMetadata` | ✅ | ✅ |
| `Form\ShareCreateType` | yes | FormKit trait fields (see W-03 note) | ✅ | ✅ |
| `Routing\YopassRouteLoader` | yes | none; deliberately no `$loaded` flag (`src/Routing/YopassRouteLoader.php:61-62`) | ✅ | ✅ |
| `Twig\YopassTwigExtension` | yes | none; globals are fixed config strings | ✅ | ✅ |
| `Command\PurgeOldSharesCommand` | yes | none; CLI only | ✅ | ✅ |

`Security\ShareEncryptionService` and `Service\DefaultShareFileHandler` are stateless; the first is test tooling and is removed from the container because nothing references it. Entities, the ODM document, DTOs, events and value objects are created per request/call and are never stored in a service property. `new DateTimeImmutable()` in entity constructors (`src/Entity/SecureShare.php:49`, `src/Entity/ShareAccessLog.php:37`, `src/Document/SecureShareDocument.php:44`) runs per object, not per service, so it is correct in a worker.

## Findings

### W-01 — Correctness depends on Doctrine resetting the managers (Medium)

- **Where:** `src/Repository/DoctrineOrmShareRepository.php:27-32` (`find()` through the identity map), `:119-136` (`persist()` / `flush()`); `src/Repository/DoctrineMongoShareRepository.php:30-35`, `:130-152`; flush callers `src/Service/ShareCreator.php:35-36`, `src/Service/ShareExtender.php:60-61`, `src/Service/ShareAccessLogger.php:44-45`, `src/Controller/ShareManageController.php:279-280`, `:303-304`.
- **Worker impact:** the repositories keep no state of their own, but they use the shared `EntityManager` / `DocumentManager`. Under **A** this is fine: DoctrineBundle's registry and the ODM `ManagerRegistry` (`ResetInterface`, tagged `kernel.reset`) clear or replace the managers between requests. Under **B**:
  - The identity map survives. `find()` can return a `SecureShare` loaded by an earlier request, so a share revoked, extended, consumed or deleted by another worker can still show its old state (public page rendered, `preview()` returning ciphertext of a deleted share to its creator, wrong `consumed`/`expired` status). The actual read consumption stays safe because `consumeReadIfAvailable()` uses an atomic DQL `UPDATE` / Mongo `findAndUpdate`, and ownership checks compare the creator id stored on the share, so no cross-user leak was found.
  - Managed entities accumulate in the identity map for the whole worker life (memory growth).
  - A `flush()` that throws (for example a DB error while creating a share or writing an access log) closes the ORM EntityManager; every following request in that worker then fails with "EntityManager is closed".
- **Recommendation:** keep `services_resetter` enabled (the default in FrankenPHP runtime integrations). If an integrator really runs without any reset, they must call `ManagerRegistry::resetManager()` / `clear()` themselves after each request, or set a low `max_requests`. No code change is needed in the bundle for scenario A.
- **Status:** Resolved (bundle side) — new `src/Repository/ResolvesEntityManagerTrait.php` used by `DoctrineOrmShareRepository` and `DoctrineOrmShareAccessLogRepository`: the manager comes from `ManagerRegistry` (`doctrine`, wired in `src/DependencyInjection/YopassExtension.php` with `database.entity_manager`) on every call and is replaced with `resetManager()` when a previous flush closed it (new constructor arguments are optional). `DoctrineOrmShareRepository::find()` is a DQL query with `Query::HINT_REFRESH`, and `DoctrineMongoShareRepository::find()` calls `DocumentManager::refresh()`, so revoked / extended / consumed / deleted shares are never served from a stale identity map. Consumption stays atomic (conditional `UPDATE` / `findAndUpdate`). The bundle does not clear the application's manager; identity-map growth under scenario B remains the application's responsibility. Tests: `DoctrineOrmShareRepositoryTest::testFindRefreshesManagedShareOnEveryCall`, `testClosedEntityManagerIsResetOnNextRequestWithoutKernelReset`, `testFallsBackToInjectedEntityManagerWhenRegistryReturnsAnotherManagerType`, `testConsumeReadIfAvailableReloadsShareWithoutClearingEntityManager`; `DoctrineOrmShareAccessLogRepositoryTest::testFlushUsesReopenedManagerAfterPreviousFailureWithoutKernelReset`; `DoctrineMongoShareRepositoryTest::testFindRefreshesManagedDocumentSoOtherWorkerChangesAreSeen`.

### W-02 — Rate limiter effectiveness depends on the `cache.app` adapter (Low)

- **Where:** `src/Security/PublicEndpointRateLimiter.php:35-67`, wired with `cache.app` in `src/DependencyInjection/YopassExtension.php:89-96`.
- **Worker impact:** the limiter itself is `readonly` and stateless; counters live in the cache pool. With the default filesystem (or Redis/APCu) adapter this works across requests and workers. If a host sets `cache.app` to an in-memory `ArrayAdapter`, counters are per-worker only, are wiped by `kernel.reset` under A (limiter becomes ineffective), and grow with the number of distinct client IPs under B until entries expire.
- **Recommendation:** use a shared, persistent adapter (Redis, filesystem, APCu) for `cache.app` in production workers. Not a bundle defect.
- **Status:** Accepted — host cache configuration; the limiter keeps no in-process state.

### W-03 — Notes (Info)

- `DoctrineOrmShareRepository::consumeReadIfAvailable()` calls `EntityManager::clear()` (`src/Repository/DoctrineOrmShareRepository.php:53`). This detaches every managed entity of the current request, including host entities. It is not worker-specific and, under B, it actually limits identity-map growth on the public consume path.
  **Status:** Resolved — `clear()` removed; the consumed share is re-read through `find()` (refresh hint), so host entities stay managed.
- `ShareCreateType` uses FormKit's `FormOptionsTrait`. The trait binds the builder only for the duration of `withBuilder()` and restores the previous value in a `finally` block (`vendor/nowo-tech/form-kit-bundle/src/Form/FormOptionsTrait.php:130-140`); the memoized profile name comes from the class attribute and never changes. No leak between requests.
- `YopassRouteLoader` explicitly avoids a sticky `$loaded` flag so route reloads keep working in a long-lived worker (`src/Routing/YopassRouteLoader.php:61-62`). Good pattern.
- The demo (`demo/symfony8/Caddyfile`) runs FrankenPHP in worker mode (`php_server { worker { ... } }`).

No other findings. No High findings: no user, token, request or share data is kept in any bundle service between requests.

## Usage recommendations in worker mode

- Keeping `services_resetter` / `kernel.reset` active is still recommended so Doctrine ORM and MongoDB ODM identity maps are cleared between requests; without it the bundle stays correct (fresh reads, closed-manager recovery) but memory is bounded only by `max_requests`.
- Use a persistent, shared cache adapter for `cache.app` when `public_rate_limit` is enabled.
- A custom `security.access_checker` service or `database.repository` (driver `custom`) must stay stateless, or implement `ResetInterface`. Do not cache the current user, token or share list in properties.
- Event listeners on `YopassEvents::SHARE_ACCESS_CHECK` / `SHARE_LIST_QUERY` / `SHARE_LIST_RESULT` must not keep the event's user or shares in properties; they receive fresh objects on every dispatch.
- If the application runs without any reset, set a conservative `max_requests` (or `FRANKENPHP_LOOP_MAX`) to bound identity-map growth.

## Re-audit triggers

Re-run this audit when a change adds: non-`readonly` properties to controllers, services or repositories; a share or permission cache; storage of the current user or request in a service; a new Doctrine event listener that buffers changes (`onFlush` / `postFlush`); a new cache adapter default for the rate limiter; or any use of `$_SERVER` / `$_ENV` at runtime.
