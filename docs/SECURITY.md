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
| **Key in URL query string** | A `?decrypt_key=` link sends the key to the server (web-server / proxy access logs, router log, APM, browser history). Up to 1.8.0 every created share offered such a link, and `sharing.allow_embed_in_url` / `default_embed_in_url` had no effect. | Since the Unreleased version the bundle never builds query-string keys. One-click links use the URL fragment (`#decrypt_key=`). `ShareKeyQueryRedirectListener` (before the router) answers a `GET /share/{id}?decrypt_key=…` (or `?key=` / `?password=`) of an old link with `303` to the fragment form, so the application does not log, render or cache it. The first request of an old link still reaches the web server / proxy. |
| **Key in URL fragment** | The fragment is never sent to the server, nor in `Referer`. It stays in the address bar and history, and anyone who gets the full link gets the secret. | The reveal script reads the fragment and removes it (`history.replaceState`). The one-click link is offered only with `sharing.allow_embed_in_url: true` (default); set it to `false` to offer only the short link + the key on separate channels. The manage preview follows the same rule. |
| **Public endpoint abuse** | Anonymous consume/show enables enumeration and DoS. | `public_rate_limit` per client IP (requires Symfony `cache.app`); HTTPS in production. |
| **Unauthorized share creation** | Anonymous users create shares. | Firewall + `YopassAccessCheckerInterface` on manage routes. |
| **CSRF on manage actions** | A cross-site POST revokes / deletes / extends shares. | Symfony form CSRF on every manage form (`CsrfActionType` for revoke / delete / delete all, explicit check on extend). **Fails closed**: `CsrfProtectionPass` refuses to compile the container when the manage Web UI is enabled without `security.csrf.token_manager` + `form.type_extension.csrf`, or with `framework.form.csrf_protection: false` (`CsrfActionType` declares its own CSRF options and would otherwise accept tokenless POSTs). `symfony/security-csrf` is required since the Unreleased version. |
| **IDOR on manage actions** | User previews/revokes another user's share. | Creator check via `ShareAccessGuard` (default); extend with `ShareAccessCheckEvent` listeners. |
| **Concurrent consume** | Two parallel consumes might exceed read limits. | Atomic `consumeReadIfAvailable()` in repositories (ORM DQL update / MongoDB find-and-update). |
| **Oversized ciphertext** | DoS via huge payloads. | `max_ciphertext_bytes` limit (default 700 KB). |
| **Tampered payload kind** | A forged `payloadKind` stores shares the reveal page cannot handle, or bypasses the file-share switch. | `ShareCreateType` only accepts `text` / `file` (`Choice` constraint, since 1.5.0); `file` is rejected unless a `file_handler` is configured. |
| **XSS on reveal page** | Decrypted secret injected unsafely. | Output in `<pre>` via `textContent` in Stimulus controller. |
| **Third-party scripts in the guest / public layout** | The reveal page extends a **host** layout (`web_ui.public_layout_template`, else `nowo_ui_kit.layouts.guest`). Any script that layout loads (analytics, tag managers, chat widgets, A/B testing, session replay, ad / CDN scripts) runs in the same origin and page as the decrypted secret: it can read the `#decrypt_key=` fragment before the reveal script strips it, the key / password input and the decrypted plaintext or file. The same applies to the admin layout for the "share created" page and the manage preview (stored key, one-click link, decrypted preview). | **Host responsibility.** The guest / public layout used for the reveal page must load **no third-party scripts** (first-party, self-hosted scripts only; no tag manager, no analytics / replay / chat / marketing snippet). Use a dedicated minimal layout through `web_ui.public_layout_template` when the site-wide guest layout carries such scripts, and add a strict `Content-Security-Policy` (`script-src 'self'`). See [Usage — Public reveal page](USAGE.md#public-reveal-page-layout-security) and [Templates](TEMPLATES.md#unique-pages). |
| **Caching and `Referer` of secret pages** | A shared / browser cache keeps the reveal or created page; a link followed from the page leaks its URL in `Referer`. | The public reveal page (`GET /share/{id}`) and the "share created" page send `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer` (since the Unreleased version), like the `303` redirect of old query-string links. |
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

The access log stores the **raw** client IP and user agent. Disable `access_log` if that conflicts with your privacy policy. Links built by the bundle keep keys in the URL fragment, so keys never appear in access logs; old `?decrypt_key=` links are redirected before the router logs them, but the web server / proxy may log their first request.

## Dependencies

- `ext-sodium` (PHP tests)
- `libsodium-wrappers-sumo` (browser bundle; the sumo build provides `crypto_pwhash` for password mode)

Run `composer audit` and Dependabot before releases.

## AI security audit (REQ-SEC-004)

Static / AI review posture for this package (catalog: monorepo `BUNDLES_SECURITY_ANALYSIS.md`, Appendix BD):

| Date | Scope | Grade | Risk | Record | Notes |
|------|-------|-------|------|--------|-------|
| 2026-07-27 | 1.3.x | Pass | Low | catalog | Public rate limits, manage UI defaults (`ROLE_ADMIN`, `allow_unauthenticated: false`), ciphertext-only storage, no secrets in demo env examples. |
| 2026-10-29 | v1.8.0 | Pass (conditional) | Medium | Appendix BD | M1 embed-in-URL options inert (one-click `?decrypt_key=` always offered), M2 Flex recipe wrote `APP_SECRET=ChangeMeInProduction`; Low L1 password mode broken (non-sumo libsodium), L2 `web_ui.enabled: false` kept the manage routes, L3 ciphertext kept after consume, L4 decrypted file blob MIME, L5 per-IP limiter behind proxies. M1, M2, L1, L2 fixed in **1.9.0**. |
| **2026-10-30** | **delta v1.9.0..1089498** (UiKit page contract, toward 1.10.0) | **Pass (good)** | **Low** | Appendix BD — "Delta re-audit 1.10.0" | Reveal page now extends the host-overridable UiKit guest layout (third-party scripts there could read the fragment key / plaintext) — documented as a host rule, and the reveal / created responses send `Cache-Control: no-store, private` + `Referrer-Policy: no-referrer`; the manage preview wipes decrypted text / key inputs / file blob URL on every dialog close (button, Escape, backdrop). Fixed in Unreleased. |

| Area | Status |
|------|--------|
| E2E ciphertext-only server storage | Pass |
| Manage UI defaults (`ROLE_ADMIN`, `allow_unauthenticated: false`) | Pass |
| Public consume/show rate limits (`public_rate_limit` + hashed IP logs) | Pass |
| No secrets in committed demo env examples | Pass |
| Keys only in the URL fragment (never the query string; old links `303`-redirected) | Pass (1.9.0) |
| Secret pages not cached, no `Referer` (`no-store, private` + `no-referrer`) | Pass (Unreleased) |
| No decrypted plaintext left in the hidden manage preview after close | Pass (Unreleased) |
| No third-party scripts on the reveal page layout | Host responsibility (documented) |

**Latest audit:** **Pass (good)** / **Low** — AI delta re-audit **2026-10-30** of v1.9.0..1089498 (catalog Appendix BD, "Delta re-audit 1.10.0"). Open Low from 2026-10-29: L3 ciphertext kept after consume, L4 decrypted file blob MIME, L5 per-IP limiter behind proxies. Residual: the host must keep `allow_unauthenticated: false`, `cache.app` and `framework.trusted_proxies` (behind a proxy) for public rate limits, and keep third-party scripts out of the guest / public layout.

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for coordinated disclosure.

## Release security checklist (12.4.1)

Before each tag the maintainer confirms every row below (a note in the release PR or tag message is enough).

| # | Item (12.4.1) | What to confirm for YopassBundle | Status |
| - | ------------- | -------------------------------- | ------ |
| 1 | `SECURITY.md` | This file and [.github/SECURITY.md](../.github/SECURITY.md) are current (attack surface, threats, reporting channel). | Maintainer sign-off before tag |
| 2 | `.env` in `.gitignore` | Root `.gitignore` ignores `.env`, `.env.local`, `.env.*.local`; `demo/symfony8/.gitignore` ignores `.env`, `.env.dev`, `.env.local*`, `.env.s3.local`. Only `.env.example` / `.env.test` / `.env.s3.example` are versioned. | Maintainer sign-off before tag |
| 3 | No secrets | No real keys, passwords or ciphertexts in the repo, demo env examples or test fixtures (placeholders / runtime-generated values only; GitGuardian green). | Maintainer sign-off before tag |
| 4 | Safe `config` / recipe | Flex recipe (`.symfony/recipe/`) and shipped defaults stay safe: manage UI requires `ROLE_ADMIN`, `security.allow_unauthenticated: false`, `sharing.default_embed_in_url: false` (and `when@prod` keeps it off), public routes limited to `/share/{id}` and `/share/{id}/consume`. The recipe sets no environment variable (the `APP_SECRET=ChangeMeInProduction` block of recipes 1.0.0 / 1.1.0 is removed; hosts set their own secret). | Maintainer sign-off before tag |
| 5 | Input / output | Create form: ciphertext required and length-capped by `max_ciphertext_bytes`, `payloadKind` restricted to text/file, `expiresIn` / `maxReads` restricted to the configured option lists, CSRF on every mutating route (`CsrfProtectionPass` fails closed). Output: Twig auto-escaping only (no `|raw` on user data); the server never decrypts — plaintext is rendered only in the recipient's browser. | Maintainer sign-off before tag |
| 6 | Dependencies | `composer audit` clean (CI `Composer audit` job) and `pnpm audit` reviewed; Dependabot PRs handled; `libsodium-wrappers-sumo` / `ext-sodium` on supported versions. | Maintainer sign-off before tag |
| 7 | No-secret logs | Logs and the access log never contain plaintext, ciphertext, keys or passwords; rate-limit warnings carry a **hashed** client IP only (see [Logging and observability](#logging-and-observability-req-obs-001)). | Maintainer sign-off before tag |
| 8 | Cryptography | Browser-side libsodium `crypto_secretbox` with a random 32-byte key (key mode) or `crypto_pwhash`-derived key (password mode); no custom primitives; server-side `ShareEncryptionService` is test-only. Password mode needs the sumo build (`crypto_pwhash`), covered by an unmocked Vitest round trip. Keys travel outside the stored envelope (separate channel, or the `#decrypt_key=` fragment when `sharing.allow_embed_in_url` is true; never the query string). | Maintainer sign-off before tag |
| 9 | Permissions / exposure | Manage routes behind the host firewall and `YopassAccessCheckerInterface` (empty role lists grant nothing; owner-only by default via `ShareAccessCheckEvent`); reads are consumed atomically (`consumeReadIfAvailable`) so a one-time share cannot be read twice; revoked / expired shares return no ciphertext. | Maintainer sign-off before tag |
| 10 | Limits / DoS | `public_rate_limit` (per client IP, needs `cache.app`) on show/consume; `max_ciphertext_bytes` / `max_secret_chars` / file handler size caps; retention purge (`nowo:yopass:purge-old-shares`) bounds stored data. | Maintainer sign-off before tag |
| 11 | Public routes documented | Public and manage routes listed in [INSTALLATION.md](INSTALLATION.md) with firewall guidance. | Maintainer sign-off before tag |
| 12 | **AI security audit (REQ-SEC-004)** | Latest recorded grade — see [AI security audit](#ai-security-audit-req-sec-004) and `BUNDLES_SECURITY_ANALYSIS.md`. | **Pass (good) / Low** — AI delta re-audit 2026-10-30 (v1.9.0..1089498), catalog Appendix BD "Delta re-audit 1.10.0"; M1 / M2 / L1 / L2 of the 2026-10-29 audit fixed in 1.9.0 |
