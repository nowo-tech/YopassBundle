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
| **Public consume** | Share ID on `/share/{id}/consume` (returns ciphertext only). |
| **Manage routes** | Authenticated CRUD; default owner-only per share (`YopassAccessCheckerInterface` + `ShareAccessCheckEvent`). |
| **Configuration** | `nowo_yopass` YAML (routes, table prefix, access checker). |
| **Client script** | `yopass.js` — encrypt/decrypt in browser with libsodium. |

## Threats and mitigations

| Threat | Risk | Mitigation |
|--------|------|------------|
| **Plaintext on server** | Secret stored or logged in clear. | Encryption in browser; server persists ciphertext only. |
| **Key in URL sent to server** | Fragment `#key` leaked via Referer. | Key stays in URL fragment (not sent in HTTP request); document separate-channel sharing. |
| **Unauthorized share creation** | Anonymous users create shares. | Firewall + `YopassAccessCheckerInterface` on manage routes. |
| **IDOR on manage actions** | User previews/revokes another user's share. | Creator check via `ShareAccessGuard` (default); extend with `ShareAccessCheckEvent` listeners. |
| **Oversized ciphertext** | DoS via huge payloads. | `max_ciphertext_bytes` limit (default 700 KB). |
| **XSS on reveal page** | Decrypted secret injected unsafely. | Output in `<pre>` via `textContent` in Stimulus controller. |

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
