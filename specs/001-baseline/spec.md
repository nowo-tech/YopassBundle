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
- **FR-SEC-001 / FR-SEC-002**: Access checker and public endpoint rate limiter.
- **FR-AUDIT-001**: Access logging via `ShareAccessLogger`.
- **FR-RET-001**: `ShareRetentionPurger` + `PurgeOldSharesCommand`.
- **FR-FILE-001**: Pluggable `ShareFileHandlerInterface` with default handler and compiler passes.

### Persistence

- **FR-ENTITY-001**: ORM `SecureShare`, `ShareAccessLog`; optional Mongo `SecureShareDocument`.
- **FR-REPO-001 / FR-REPO-002**: Share and access-log repositories (ORM + Mongo + null log impl).
- **FR-DB-001**: Metadata listeners for table prefix / Mongo mapping.
- **FR-EVT-001**: Access and list query/result events for ACL extension.

### HTTP & UI

- **FR-PUB-001 / FR-PUB-002**: Public reveal controller and Stimulus reveal flow.
- **FR-UI-001**: Manage controller and templates (index, created, reveal).
- **FR-FORM-001**: Share create form.
- **FR-ROUTE-001**: Configurable `YopassRouteLoader`.
- **FR-UI-010 / FR-BUILD-001**: Bundled JS assets.

---

## Success Criteria

- **SC-001**: 100% of production files in `src/` appear in [`code-inventory.md`](code-inventory.md) with requirement IDs (67/67 mapped).
- **SC-002**: Configuration keys in `docs/CONFIGURATION.md` match `Configuration.php`.
- **SC-003**: `composer qa` / `make release-check` pass in CI (PHPUnit, PHPStan, Vitest where applicable).
- **SC-004**: No Packagist-visible behavior change without spec, inventory, and test updates.

---

## Validation

| Check | Command |
| --- | --- |
| Full QA | `make release-check` or `composer qa` |
| Code inventory audit | `find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' \| wc -l` |
| TS tests | `pnpm test` or `make test-ts` (when assets present) |

When changing behavior, update this spec, `code-inventory.md`, integrator docs, and tests.
