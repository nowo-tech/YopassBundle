# Templates

Pages of YopassBundle on the UiKit page contract (REQ-UI-003, UiKit `^2.15.4`).
`Nowo\YopassBundle\Ui\YopassPageFactory` builds the UiKit view models (`Nowo\UiKitBundle\View\*`) for the controllers;
`Nowo\YopassBundle\Ui\YopassPages` resolves the layouts (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`;
`web_ui.public_layout_template`, null = `nowo_ui_kit.layouts.guest`) and the per-page overrides (`web_ui.templates.<page>`).
The manage pages extend a UiKit scaffold and fill data blocks only; the public reveal page is a unique page on the guest
layout. Contract test: `tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict
missing-translation rule). Twig namespace: `@NowoYopassBundle` (views in `src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Bundle partials](#bundle-partials)
- [Scripts and dialogs](#scripts-and-dialogs)
- [Layout bridge (1.x layouts)](#layout-bridge-1x-layouts)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoYopassBundle'` (theme exceptions `nowo_ui_kit.theme.bundles.NowoYopassBundle`)
and `layout: YopassPages::layout()`. Labels are `NowoYopassBundle` translation keys (`YopassPages::label()`). The
destructive actions are POST + CSRF-only `FormView`s (`CsrfActionType`, flat `_token`) behind the UiKit confirm
`<dialog>`.

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Manage (`manage`) | `manage/index.html.twig` | `page/list` | `ListPage` (header `yopass.title` / `yopass.intro`, back link to `dashboard_route` when set, header action Delete all (POST + confirm, only with shares and revoke rights); columns type, created (priority 2), expires, reads (priority 2), status; rows = `list<SecureShare>`; row actions View and Extend (open the preview `<dialog>`, `Action::dialogId` = `nowo-yopass-preview`, Stimulus `nowo-yopass-manage-preview#open` / `#openExtend`), Revoke (kind `cancel`, active shares) and Delete (POST + confirm); `Pagination` (`shares.list_page_size`, only with more than one page); `EmptyState` `yopass.list.empty`; caption = retention notice; `data-testid="yopass-manage"`) | `nowo_ui_list_row` (type, dates, reads, status on `badge_success` / `badge_neutral` / `badge_warning`); `nowo_ui_content` prepends the create panel and wraps the scaffold content in the preview controller with the preview dialog (`{{ parent() }}`); `nowo_ui_scripts` (`{{ parent() }}` + `js/yopass.js`) |
| Share created (`created`) | `manage/created.html.twig` | `page/show` | `ShowPage` (header `yopass.result.title`, back link to the manage page, action Create another secret; `data-testid="yopass-created"`) | `nowo_ui_show_sections` (links card: remember notice on `flash_warning`, short link, decryption key and — only with `sharing.allow_embed_in_url` — the one-click link with the key in the URL fragment, each a read-only `ShareCreatedLinksType` field with a copy button); `nowo_ui_scripts` |

The controllers also pass the 1.x variables (`shares`, `shares_total`, `shares_page`, `shares_pages`, `list_page_size`,
`can_create`, `can_revoke`, `can_extend`, `can_share_file`, `max_file_bytes`, `max_secret_chars`, `dashboard_route`,
`routes`, `share_options`, `sharing_options`, `create_form`, `revoke_forms`, `delete_forms`, `delete_all_form`,
`preview_form`; created page: `share`, `public_path`, `links_form`) for the partials and for host templates set through
`web_ui.templates.<page>`.

## Unique pages

The public reveal page is the recipient's page, not an admin screen: it extends `web_ui.public_layout_template`
(`page_layout`), else the UiKit guest layout (`nowo_ui_layout.guest`), sets `nowo_ui_bundle` / `nowo_ui_page_type`
and fills `nowo_ui_document_title` (translated title), `nowo_ui_page_header` (`nowo_ui_page_header(header)`: title +
intro, breadcrumbs mode `own`), `nowo_ui_content` (the reveal panel on the `form_panel` slot: unavailable notice, or
the `ShareRevealType` key / password field, the Reveal button on `btn_primary`, the decrypted output on `code_block`,
the download link and the error on `flash_error`) and `nowo_ui_scripts` (`{{ parent() }}` + `js/yopass.js`).

- `public/reveal.html.twig` — public reveal page (`/share/{id}`, id `public`); variables `header` (`View\PageHeader`),
  `page_layout`, `share`, `decryption_mode` (`key` | `password`), `routes`, `reveal_form`; `data-testid="yopass-reveal"`.

The key / password never reaches the server: it is read from the URL fragment (`#decrypt_key=`) or the form, whose
fields have no `name`; legacy `?decrypt_key=` links are redirected (`303`) to the fragment before routing.

## Per-page overrides

- `nowo_yopass.web_ui.templates.<page>` (`manage`, `created`, `public`): a host template that extends
  `@!NowoYopassBundle/<template>` and overrides a data block (`nowo_ui_list_row`, `nowo_ui_show_sections`,
  `nowo_ui_content`, …). Prefer theming the UiKit slots (`nowo_ui_kit.theme`) and scaffolds once for every bundle.
- Record such overrides in the application's override inventory.
- Full-file overrides under `templates/bundles/NowoYopassBundle/<subpath>` freeze that file: see
  [Usage — Twig overrides](USAGE.md#twig-overrides) (subpath table, freeze rule, recovery).

## Bundle partials

| Partial | Role |
| --- | --- |
| `manage/_create_panel.html.twig` | Create-share panel (`card` slots): text / file tabs (`tabs`, `tab`, `tab_active`; the controller swaps the slot classes), secret + counter (`muted`, limit on `form_error`), expiration, max reads, encryption mode, custom password, error (`flash_error`), submit (`btn_primary`). Variables `create_form`, `can_share_file`, `max_file_bytes`, `max_secret_chars`, `bundle`. |
| `manage/_preview_dialog.html.twig` | Preview / extend dialog through the UiKit `dialog` partial (`<dialog>` with header / body / footer, size `lg`): availability (`badge_neutral`), key / password, decrypt, output (`code_block`), public link + copy, access log table (`table_wrap`, `table`, `table_head*`), extend form, close. Variables `preview_form`, `can_extend`, `bundle`. |
| `_legacy_layout.html.twig` | Deprecated 1.x layout bridge (see below). |
| `layout_integrate_host.html.twig` | Deprecated 1.x host bridge (`body` → `nowo_ui_content`), removed in 2.0. |

The bundle ships no layout, no local pagination, flash, confirm, modal, page header or empty-state partial and no inline
script or handler: the scaffolds and the UiKit partials (`action`, `confirm`, `dialog`, `empty`, `flashes`,
`pagination`, `page_header`, `scripts`) render them, and the host layout loads the CSS framework. Elements toggled by
the controllers use the `hidden` attribute (never `d-none`). Bundle-prefixed hooks for host CSS:
`nowo-yopass-create`, `nowo-yopass-create__secret-meta`, `nowo-yopass-shares`, `nowo-yopass-copy-field`,
`nowo-yopass-secret`, `nowo-yopass-reveal`.

## Scripts and dialogs

`js/yopass.js` (asset package `nowo_yopass`, ES module) registers the Stimulus controllers `nowo-yopass-create`,
`nowo-yopass-created`, `nowo-yopass-manage-preview` and `nowo-share-reveal`. The preview dialog opens through the UiKit
`dialog` runtime (`data-nowo-ui-dialog-open`) and the controller (`HTMLDialogElement.showModal()`); closing it (close
button, Escape, backdrop) resets the preview state. Revoke / Delete / Delete all use the UiKit confirm runtime. The
1.x `nowo-yopass-confirm` controller (`window.confirm()`) was removed in 1.10.

## Layout bridge (1.x layouts)

`web_ui.layout_template` may still point at a 1.x layout without the UiKit canonical blocks (e.g. the project
`base.html.twig` rendering `body`, or `@NowoYopassBundle/layout_integrate_host.html.twig`): `YopassPages` then wraps it
in `_legacy_layout.html.twig` (maps the canonical blocks into `body`, `title`, `stylesheets`, `javascripts`) and
triggers a deprecation. `nowo_ui_styles` / `nowo_ui_scripts` the 1.x layout renders itself are not printed twice. The
value `@NowoYopassBundle/layout.html.twig` (the removed 1.x default) is mapped to null. The bridge is removed in 2.0.

## Allowed exceptions

- (none) — the contract test declares no `allowedClasses()` and no `ignoredMissingTranslations()`.
