# Security

## Table of contents

- [Attack surface](#attack-surface)
- [Threats and mitigations](#threats-and-mitigations)
- [E2E encryption model](#e2e-encryption-model)
- [Logging and observability (REQ-OBS-001)](#logging-and-observability-req-obs-001)
- [Dependencies](#dependencies)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
- [Reporting](#reporting)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Attack surface

| Input | Description |
|-------|-------------|
| **Ciphertext POST** | JSON body on create endpoint (`ciphertext`, metadata). |
| **Public consume/show** | Anonymous `/share/{id}` and `/share/{id}/consume`; rate limited per IP when `cache.app` is available. |
| **Manage routes** | Authenticated CRUD; default owner-only per share (`YopassAccessCheckerInterface` + `ShareAccessCheckEvent`). |
| **Configuration** | `nowo_yopass` YAML (routes, table prefix, access checker, rate limits). |
| **Client script** | `yopass.js` — encrypt/decrypt in browser with libsodium. |

## Threats and mitigations

| Threat | Risk | Mitigation |
|--------|------|------------|
| **Plaintext on server** | Secret stored or logged in clear. | Encryption in browser; server persists ciphertext only. |
| **Key in URL query string** | `?decrypt_key=` is sent in the HTTP request (proxy logs, Referer, browser history). | Prefer **short links** (`/share/{id}`) and deliver the key on a separate channel. **`sharing.default_embed_in_url` defaults to `false`** so one-click links are opt-in. JS strips the param after load but the first request may already be logged. Legacy `#fragment` keys are not sent to the server. |
| **Key in URL fragment** | Fragment `#key` may leak via Referer on subresource requests. | Prefer query param or separate delivery; document trade-offs in [USAGE.md](USAGE.md). |
| **Public endpoint abuse** | Anonymous consume/show enables enumeration and DoS. | `public_rate_limit` per client IP (requires Symfony `cache.app`); HTTPS in production. |
| **Unauthorized share creation** | Anonymous users create shares. | Firewall + `YopassAccessCheckerInterface` on manage routes. |
| **CSRF on manage actions** | A cross-site POST revokes / deletes / extends shares. | Symfony form CSRF on every manage form (`CsrfActionType` for revoke / delete / delete all, explicit check on extend). **Fails closed**: `CsrfProtectionPass` refuses to compile the container when the manage Web UI is enabled without `security.csrf.token_manager` + `form.type_extension.csrf`, or with `framework.form.csrf_protection: false` (`CsrfActionType` declares its own CSRF options and would otherwise accept tokenless POSTs). `symfony/security-csrf` is required since the Unreleased version. |
| **IDOR on manage actions** | User previews/revokes another user's share. | Creator check via `ShareAccessGuard` (default); extend with `ShareAccessCheckEvent` listeners. |
| **Concurrent consume** | Two parallel consumes might exceed read limits. | Atomic `consumeReadIfAvailable()` in repositories (ORM DQL update / MongoDB find-and-update). |
| **Oversized ciphertext** | DoS via huge payloads. | `max_ciphertext_bytes` limit (default 700 KB). |
| **Tampered payload kind** | A forged `payloadKind` stores shares the reveal page cannot handle, or bypasses the file-share switch. | `ShareCreateType` only accepts `text` / `file` (`Choice` constraint, since 1.5.0); `file` is rejected unless a `file_handler` is configured. |
| **XSS on reveal page** | Decrypted secret injected unsafely. | Output in `<pre>` via `textContent` in Stimulus controller. |
| **Third-party CDN CSS** | Tabler loaded from jsDelivr. | Pin version; use `crossorigin="anonymous"`; self-host in high-security deployments. |

## E2E encryption model

- **Create:** browser encrypts with `libsodium` secretbox; server stores JSON envelope.
- **Reveal:** server returns ciphertext once per read; browser decrypts locally.
- **Password mode:** key derived with `crypto_pwhash`; salt stored in envelope (not secret).

Server-side `ShareEncryptionService` exists for **tests only** — not used in production flow.

## Logging and observability (REQ-OBS-001)

| Logged | Not logged |
|--------|------------|
| Share access audit (when `access_log.enabled`): share id, creator, client IP, user-agent, timestamp of successful public consume | Plaintext secrets, decryption keys/passwords, ciphertext payloads |
| Rate-limit warnings: action name, limit/interval, **hashed** client IP | Raw IP in application logs (hash only); never keys or ciphertext |

Disable `access_log` if storing client IP/UA conflicts with your privacy policy. Prefer short share links without `?decrypt_key=` so keys never appear in proxy access logs.

## Dependencies

- `ext-sodium` (PHP tests)
- `libsodium-wrappers` (browser bundle)

Run `composer audit` and Dependabot before releases.

## AI security audit (REQ-SEC-004)

Static / AI review posture for this package (re-audited 2026-07-27 after public rate limits and manage UI hardening):

| Area | Status |
|------|--------|
| E2E ciphertext-only server storage | Pass |
| Manage UI defaults (`ROLE_ADMIN`, `allow_unauthenticated: false`) | Pass |
| Public consume/show rate limits (`public_rate_limit` + hashed IP logs) | Pass |
| No secrets in committed demo env examples | Pass |

**Grade:** **Pass (good)** / **Low** (re-audit **2026-08-20**). Residual: users may still opt into `?decrypt_key=` (default and Flex `when@prod` keep embed off); host must keep `allow_unauthenticated: false` and `cache.app` for public rate limits.

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for coordinated disclosure.

## Release security checklist (12.4.1)

Before each tag the maintainer confirms every row below (a note in the release PR or tag message is enough).

| # | Item (12.4.1) | What to confirm for YopassBundle | Status |
| - | ------------- | -------------------------------- | ------ |
| 1 | `SECURITY.md` | This file and [.github/SECURITY.md](../.github/SECURITY.md) are current (attack surface, threats, reporting channel). | Maintainer sign-off before tag |
| 2 | `.env` in `.gitignore` | Root `.gitignore` ignores `.env`, `.env.local`, `.env.*.local`; `demo/symfony8/.gitignore` ignores `.env`, `.env.dev`, `.env.local*`, `.env.s3.local`. Only `.env.example` / `.env.test` / `.env.s3.example` are versioned. | Maintainer sign-off before tag |
| 3 | No secrets | No real keys, passwords or ciphertexts in the repo, demo env examples or test fixtures (placeholders / runtime-generated values only; GitGuardian green). | Maintainer sign-off before tag |
| 4 | Safe `config` / recipe | Flex recipe (`.symfony/recipe/`) and shipped defaults stay safe: manage UI requires `ROLE_ADMIN`, `security.allow_unauthenticated: false`, `sharing.default_embed_in_url: false` (and `when@prod` keeps it off), public routes limited to `/share/{id}` and `/share/{id}/consume`. The recipe only adds a placeholder `APP_SECRET` when the host has none — hosts must set a real one. | Maintainer sign-off before tag |
| 5 | Input / output | Create form: ciphertext required and length-capped by `max_ciphertext_bytes`, `payloadKind` restricted to text/file, `expiresIn` / `maxReads` restricted to the configured option lists, CSRF on every mutating route (`CsrfProtectionPass` fails closed). Output: Twig auto-escaping only (no `|raw` on user data); the server never decrypts — plaintext is rendered only in the recipient's browser. | Maintainer sign-off before tag |
| 6 | Dependencies | `composer audit` clean (CI `Composer audit` job) and `pnpm audit` reviewed; Dependabot PRs handled; `libsodium-wrappers` / `ext-sodium` on supported versions. | Maintainer sign-off before tag |
| 7 | No-secret logs | Logs and the access log never contain plaintext, ciphertext, keys or passwords; rate-limit warnings carry a **hashed** client IP only (see [Logging and observability](#logging-and-observability-req-obs-001)). | Maintainer sign-off before tag |
| 8 | Cryptography | Browser-side libsodium `crypto_secretbox` with a random 32-byte key (key mode) or `crypto_pwhash`-derived key (password mode); no custom primitives; server-side `ShareEncryptionService` is test-only. Keys travel outside the stored envelope (separate channel or opt-in `?decrypt_key=`). | Maintainer sign-off before tag |
| 9 | Permissions / exposure | Manage routes behind the host firewall and `YopassAccessCheckerInterface` (empty role lists grant nothing; owner-only by default via `ShareAccessCheckEvent`); reads are consumed atomically (`consumeReadIfAvailable`) so a one-time share cannot be read twice; revoked / expired shares return no ciphertext. | Maintainer sign-off before tag |
| 10 | Limits / DoS | `public_rate_limit` (per client IP, needs `cache.app`) on show/consume; `max_ciphertext_bytes` / `max_secret_chars` / file handler size caps; retention purge (`nowo:yopass:purge-old-shares`) bounds stored data. | Maintainer sign-off before tag |
| 11 | Public routes documented | Public and manage routes listed in [INSTALLATION.md](INSTALLATION.md) with firewall guidance. | Maintainer sign-off before tag |
| 12 | **AI security audit (REQ-SEC-004)** | Latest recorded grade — see [AI security audit](#ai-security-audit-req-sec-004) and `BUNDLES_SECURITY_ANALYSIS.md`. | **Pass (good)** — see section above; residual Low: key-in-URL opt-in only; Flex ships `security_nowo_yopass.yaml` + `when@prod` embed off |
