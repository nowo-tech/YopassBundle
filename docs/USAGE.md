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
- [Clock (REQ-DI-001)](#clock-req-di-001)
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
- **Decryption key** — copy for separate delivery
- **One-click link** — `/share/{id}#decrypt_key=…`, only when `sharing.allow_embed_in_url` is `true` (listed first when `sharing.default_embed_in_url` is `true`). The key is in the URL fragment, which browsers never send to the server.

## Public reveal

Recipients open `/share/{id}`:

| Link type | URL | Behaviour |
|-----------|-----|-----------|
| Short | `/share/{id}` | Enter decryption key in the form, then click **Reveal secret** |
| One-click | `/share/{id}#decrypt_key=…` | Key read from the URL fragment (never sent to the server), then removed from the address bar; auto-reveal when possible |

Each successful consume consumes one read. Decryption happens client-side; the server returns ciphertext only.

Legacy URLs keep working: a raw `#key` fragment is read as before, and query-string keys (`?decrypt_key=`, `?key=`, `?password=`, used by links created up to 1.8.0) are redirected by the server (`303`, before routing) to the `#decrypt_key=` form.

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

The bundle registers the Twig namespace **`NowoYopassBundle`** with `TwigPathsPass` (always on; no feature flag). When `templates/bundles/NowoYopassBundle/` exists in your application at container compile time it is **prepended** to the namespace, and the bundle views (`src/Resources/views/`) are **appended** as the fallback — so an application file at the override path **always wins** over the shipped one. Controllers render logical names (`@NowoYopassBundle/...`) only.

**Prefer upgrade-safe customisation first (no Twig fork):**

| Depth | How | On bundle upgrade |
| ----- | --- | ----------------- |
| **Preferred** | Set `web_ui.layout_template` (your admin shell or the `layout_integrate_host.html.twig` bridge), `web_ui.css_framework`, `web_ui.icon_set`, translations, CSS on the bundle classes — see [Configuration — Web UI](CONFIGURATION.md#web-ui-manage-pages) | Vendor pages and fixes apply automatically |
| **Surgical** | Point one `nowo_yopass.templates.*` key at your own template that `{% extends '@NowoYopassBundle/...' %}` and overrides a single block with `{{ parent() }}`, or override only the `_action_icons.html.twig` partial | Only that block / partial is yours; the rest keeps upgrading |
| **Full-file override** | Copy a page into `templates/bundles/NowoYopassBundle/<subpath>` | That `<subpath>` is **frozen** (see below) |

**Freeze rule.** An application file at the override path always wins and will **not** pick up vendor changes for that path until you remove or merge it. A full-file override is an escape hatch for deep redesigns; every `composer update` keeps serving your copy of that `<subpath>` (including its JavaScript hooks and CSRF form wiring) while the rest of the bundle upgrades. For a one-time-secret UI this matters: a frozen `manage/*.html.twig` or `public/reveal.html.twig` will not receive security-relevant template fixes.

**Procedure (full-file override)**

1. Pick the `<subpath>` from the table below.
2. Create `templates/bundles/NowoYopassBundle/<subpath>` in your application (same relative path and file name).
3. Clear the cache (`php bin/console cache:clear`) — the override directory is detected when the container is compiled, so the cache must be rebuilt the first time you create it.
4. Treat the file as a fork: diff it against the vendor copy on each bundle upgrade.

| `<subpath>` (under `src/Resources/views/`) | Purpose |
| ------------------------------------------ | ------- |
| `layout.html.twig` | Default standalone HTML shell (used when `web_ui.layout_template` is not set); blocks `title`, `stylesheets` / `nowo_ui_styles`, `body` / `nowo_ui_content`, `javascripts` / `nowo_ui_scripts`. |
| `layout_integrate_host.html.twig` | Bridge that extends the host `base.html.twig`; meant to be referenced from `web_ui.layout_template` rather than overridden. |
| `manage/index.html.twig` | Manage page: create form, share list, preview / extend modal, revoke / delete actions. |
| `manage/created.html.twig` | Page shown after creating a share (short link, one-click link, decryption key). |
| `public/reveal.html.twig` | Public reveal page (key or password input, client-side decryption). |
| `_action_icons.html.twig` | Macro for the view / extend / revoke / delete action icons. |

Alternatively point `nowo_yopass.templates.*` (`manage`, `created`, `public`) at your own application templates instead of using the override path.

**Recovering upstream after a freeze:** delete (or rename aside) the application override, clear the cache (`php bin/console cache:clear`), and re-apply only the surgical customisation you still need. Diff your copy against the new vendor file under `vendor/nowo-tech/yopass-bundle/src/Resources/views/` before re-copying.

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

## Clock (REQ-DI-001)

Every time-dependent service takes an optional `Psr\Clock\ClockInterface` (autowired to the framework `clock` service when `symfony/clock` is installed, otherwise the system clock): `ShareCreator` (expiration date of new shares), `ShareExtender`, `ShareRetriever`, `ShareRetentionPurger`, the Doctrine ORM / MongoDB share repositories (atomic consume) and `PublicEndpointRateLimiter` (fixed window). Freeze time in tests with `Symfony\Component\Clock\MockClock`.

Justified exception: the `SecureShare` / `SecureShareDocument` / `ShareAccessLog` persistence objects stamp `createdAt`, `revokedAt` and `accessedAt` with the wall clock in their own methods (entities do not receive services), and `SecureShare::isAvailable()` compares against the current time. Availability decisions in the HTTP flow go through the clock-aware services above.

## Translations

Domain: `NowoYopassBundle`. Bundled locales: **en**, **es**, **de**, **fr**, **it**, **nl**, **pt**. Override in `translations/bundles/NowoYopassBundle/` or `translations/NowoYopassBundle.en.yaml`.
