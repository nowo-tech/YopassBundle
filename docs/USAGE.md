# Usage

## Manage shares

Authenticated users open the manage UI (default `/tools/yopass`):

- Create text or file shares (encrypted in the browser, submitted via standard Symfony form)
- Set expiration and max reads
- List own shares (paginated when `shares.list_page_size` > 0)
- Preview content without consuming a read
- Extend expiration or max reads
- Revoke, delete individual shares, or delete all

After creating a share, the **created** page shows:

- **Short link** — `/share/{id}` (key shared separately or pasted on the reveal page)
- **One-click link** — `/share/{id}?decrypt_key=…`
- **Decryption key** — copy for separate delivery

## Public reveal

Recipients open `/share/{id}`:

| Link type | URL | Behaviour |
|-----------|-----|-----------|
| Short | `/share/{id}` | Enter decryption key in the form, then click **Reveal secret** |
| One-click | `/share/{id}?decrypt_key=…` | Key read from query string; auto-reveal when possible |

Each successful consume consumes one read. Decryption happens client-side; the server returns ciphertext only.

Legacy URLs with `#key`, `?key=`, or `?password=` are still accepted when opening a link.

## Console

Purge shares older than the configured retention age:

```bash
php bin/console nowo:yopass:purge-old-shares
```

Retention also runs when the manage list is loaded (per creator).

## Custom access control

Implement `Nowo\YopassBundle\Security\YopassAccessCheckerInterface` for team-based ACL:

```yaml
nowo_yopass:
    security:
        access_checker: App\Security\TeamYopassAccessChecker
```

## Twig overrides

Place templates under:

```
templates/bundles/NowoYopassBundle/
├── manage/index.html.twig
├── manage/created.html.twig
├── public/reveal.html.twig
└── layout.html.twig
```

Or configure paths in `nowo_yopass.templates.*`.

## Translations

Domain: `NowoYopassBundle`. Override in `translations/NowoYopassBundle.en.yaml`.
