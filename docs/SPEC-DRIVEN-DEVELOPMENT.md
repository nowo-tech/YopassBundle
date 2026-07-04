# Spec-driven development — YopassBundle

## Product vision

One-time encrypted secret sharing (Yopass-style) for Symfony apps: E2E encryption, expiration, read limits, revoke.

## User stories

| ID | Story |
|----|-------|
| US-01 | As an authenticated user, I create a share and get a link with optional `#key`. |
| US-02 | As a recipient, I open the link and reveal the secret once (or N times). |
| US-03 | As a creator, I revoke my share before expiry. |
| US-04 | As an integrator, I configure routes, table prefix, and access rules. |

## REQ traceability

| REQ | Makefile / demo |
|-----|-----------------|
| REQ-TEST-001 | `make test`, `composer test` |
| REQ-TEST-008 | `make test-coverage` + `.scripts/php-coverage-percent.sh` |
| REQ-TEST-009 | `make test-ts` |
| REQ-DEMO-005 | `demo/symfony8/Makefile` → `Demo started at: http://localhost:<PORT>` |
| REQ-DEMO-007 | `demo/symfony8` target `update-bundle` |

## Validation

- PHPUnit: services, routing, access checker, crypto round-trip
- Vitest: `yopass-crypto` encrypt/decrypt
- Demo manual: create → reveal → revoke

## Engram

See [ENGRAM.md](ENGRAM.md) for product memory in IDE workflows.
