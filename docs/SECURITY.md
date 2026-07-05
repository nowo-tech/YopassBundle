# Security

## Table of contents

- [Attack surface](#attack-surface)
- [Threats and mitigations](#threats-and-mitigations)
- [E2E encryption model](#e2e-encryption-model)
- [Dependencies](#dependencies)
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
| **Key in URL query string** | `?decrypt_key=` is sent in the HTTP request (proxy logs, Referer, browser history). | Prefer **short links** (`/share/{id}`) and deliver the key on a separate channel. One-click links are convenience-only; JS strips the param after load but the first request may already be logged. Legacy `#fragment` keys are not sent to the server. |
| **Key in URL fragment** | Fragment `#key` may leak via Referer on subresource requests. | Prefer query param or separate delivery; document trade-offs in [USAGE.md](USAGE.md). |
| **Public endpoint abuse** | Anonymous consume/show enables enumeration and DoS. | `public_rate_limit` per client IP (requires Symfony `cache.app`); HTTPS in production. |
| **Unauthorized share creation** | Anonymous users create shares. | Firewall + `YopassAccessCheckerInterface` on manage routes. |
| **IDOR on manage actions** | User previews/revokes another user's share. | Creator check via `ShareAccessGuard` (default); extend with `ShareAccessCheckEvent` listeners. |
| **Concurrent consume** | Two parallel consumes might exceed read limits. | Atomic `consumeReadIfAvailable()` in repositories (ORM DQL update / MongoDB find-and-update). |
| **Oversized ciphertext** | DoS via huge payloads. | `max_ciphertext_bytes` limit (default 700 KB). |
| **XSS on reveal page** | Decrypted secret injected unsafely. | Output in `<pre>` via `textContent` in Stimulus controller. |
| **Third-party CDN CSS** | Tabler loaded from jsDelivr. | Pin version; use `crossorigin="anonymous"`; self-host in high-security deployments. |

## E2E encryption model

- **Create:** browser encrypts with `libsodium` secretbox; server stores JSON envelope.
- **Reveal:** server returns ciphertext once per read; browser decrypts locally.
- **Password mode:** key derived with `crypto_pwhash`; salt stored in envelope (not secret).

Server-side `ShareEncryptionService` exists for **tests only** — not used in production flow.

## Dependencies

- `ext-sodium` (PHP tests)
- `libsodium-wrappers` (browser bundle)

Run `composer audit` and Dependabot before releases.

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for coordinated disclosure.

## Release security checklist (12.4.1)

- [ ] No secrets in repo or demo `.env` committed
- [ ] `composer audit` clean
- [ ] Public routes documented in INSTALLATION
- [ ] Access checker and share events documented for integrators
