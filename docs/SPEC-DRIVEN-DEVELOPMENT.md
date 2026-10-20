# Spec-driven development — YopassBundle

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **YopassBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** (and **Vitest** when applicable) enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Table of contents

- [Product vision](#product-vision)
- [Bundle functional scope](#bundle-functional-scope)
- [User stories](#user-stories)
- [REQ traceability](#req-traceability)
- [Validation](#validation)
- [Engram](#engram)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [See also](#see-also)

## Product vision

One-time encrypted secret sharing (Yopass-style) for Symfony apps: E2E encryption, expiration, read limits, revoke.

## Bundle functional scope

**Goal:** one-time encrypted secret sharing inside a Symfony application: the browser encrypts, the server stores ciphertext with expiration and read limits, and recipients reveal or consume the secret through public routes.

**In scope**

- Browser-side encryption (libsodium secretbox) with an auto-generated key or a custom password; text and file payloads (file tab only when a `file_handler` service exists).
- Share lifecycle: expiration options, max-read limits, revoke, list pagination, retention purge (`nowo:yopass:purge-old-shares`).
- Authenticated manage UI, anonymous public reveal/consume routes with per-IP rate limiting, configurable routes, table prefix and templates.
- Pluggable access control and share events for list queries and per-share access.

**Explicit non-goals**

- Server-side decryption or key escrow: the server never receives the decryption key.
- A built-in team / grant ACL: hosts implement it through share events and the access checker.
- A file storage backend: the host provides the `file_handler` service ([local](examples/LocalStorage.md) and [S3](examples/S3.md) examples are documentation only).
- `demo/` trees: illustrative only, not part of the Packagist contract.

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

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR: acceptance criteria for the **product** and, if relevant, **Makefiles/demos** (`REQ-*`).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes: add or adjust `REQ-*` comments and the requirement table.
4. **Ship integrator docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), and [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

## See also

- [GitHub Spec Kit manual](SPEC-KIT.md) and [baseline spec](../specs/001-baseline/spec.md)
- [Usage](USAGE.md), [Configuration](CONFIGURATION.md), [Installation](INSTALLATION.md)
- [Contributing](CONTRIBUTING.md), [Release](RELEASE.md)
- [Engram](ENGRAM.md)
