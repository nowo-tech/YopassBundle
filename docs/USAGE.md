# Usage

## Table of contents

- [Manage shares](#manage-shares)
- [Public reveal](#public-reveal)
- [Console](#console)
- [Custom access control](#custom-access-control)
  - [Route-level checker](#route-level-checker)
  - [Share events (teams, grants, roles)](#share-events-teams-grants-roles)
- [Twig overrides](#twig-overrides)
  - [Forms in templates (REQ-TWIG-005)](#forms-in-templates-req-twig-005)
- [Translations](#translations)

## Manage shares

Authenticated users open the manage UI (default `/tools/yopass`):

- Create text or file shares (encrypted in the browser, submitted via standard Symfony form)
- Set expiration and max reads
- List own shares by default (paginated when `shares.list_page_size` > 0); customize via share list events
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

Three layers — use only what you need:

| Layer | Interface / event | Question |
|-------|-------------------|----------|
| Route features | `YopassAccessCheckerInterface` | Can the user open manage, create, list, or revoke at all? |
| List query | `ShareListQueryEvent` | Which shares should the repository load? |
| List filter | `ShareListResultEvent` | Which loaded shares should appear? |
| Per share | `ShareAccessCheckEvent` | Can the user view/preview/extend/revoke/delete this share? |

### Route-level checker

```yaml
nowo_yopass:
    security:
        access_checker: App\Security\TeamYopassAccessChecker
```

### Share events (teams, grants, roles)

The bundle stores the share **creator** but does not manage teams or permissions. Register Symfony event listeners — see [examples/AccessControl.md](examples/AccessControl.md) and copy from `examples/access-control/`:

- `TeamShareListListener` — list team members' shares
- `TeamShareAccessListener` — same-team access
- `IndividualShareGrantListener` — per-share collaborator grants
- `RoleBasedShareAccessListener` — e.g. auditor = preview only

```php
use Nowo\YopassBundle\Event\YopassEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: YopassEvents::SHARE_ACCESS_CHECK)]
final class MyShareAccessListener
{
    public function __invoke(\Nowo\YopassBundle\Event\ShareAccessCheckEvent $event): void
    {
        // grant() or deny() based on your rules
    }
}
```

## Twig overrides

Manage pages extend the configured layout (`nowo_yopass_layout_template`). Set `web_ui.layout_template` to your project layout so bundle pages render inside your admin shell — see [Configuration — Web UI](CONFIGURATION.md#web-ui-manage-pages).

Place template overrides under:

```
templates/bundles/NowoYopassBundle/
├── manage/index.html.twig
├── manage/created.html.twig
├── public/reveal.html.twig
└── layout.html.twig
```

Or configure paths in `nowo_yopass.templates.*` / `nowo_yopass.web_ui.layout_template`.

### Forms in templates (REQ-TWIG-005)

Every control in the bundle views comes from a PHP Symfony Form Type (Form Kit `yopass` profile) built by the controller and rendered with `form_start` / the child loop / `form_end`:

| View | Variable | Form Type |
| ---- | -------- | --------- |
| `manage/index.html.twig` | `create_form` | `ShareCreateType` (ciphertext, payload kind, expiry, max reads; plus the browser-only secret, file, encryption mode and custom password controls) |
| `manage/index.html.twig` | `revoke_forms`, `delete_forms` (share id → form), `delete_all_form` | `CsrfActionType` (flat `_token`, token ids `revoke-share-<id>`, `delete-share-<id>`, `yopass_delete_all`) |
| `manage/index.html.twig` | `preview_form` | `SharePreviewType` (preview / extend modal controls) |
| `manage/created.html.twig` | `links_form` | `ShareCreatedLinksType` (read-only links and key) |
| `public/reveal.html.twig` | `reveal_form` | `ShareRevealType` (password or key input) |

Browser-only controls (plaintext secret, file, passwords, decryption keys) are unmapped children with fixed ids and Stimulus targets and an **empty `name`**, so they are never sent to the server; the create form stays url-encoded and posts the same payload as before. Render form bodies with the canonical loop after any custom children:

```twig
{{ form_start(form) }}
    {% for child in form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
    <button type="submit">…</button>
{{ form_end(form) }}
```

Template overrides and demos **must not** reintroduce raw `<form>`, `<input>`, `<textarea>` or `<select>` markup; `make check-no-raw-html-form` (part of `release-check`) fails when they do.

## Translations

Domain: `NowoYopassBundle`. Bundled locales: **en**, **es**, **de**, **fr**, **it**, **nl**, **pt**. Override in `translations/bundles/NowoYopassBundle/` or `translations/NowoYopassBundle.en.yaml`.
