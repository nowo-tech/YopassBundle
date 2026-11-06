# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.14.1] - 2026-11-06](#1141---2026-11-06)
- [[1.14.0] - 2026-11-05](#1140---2026-11-05)
- [[1.13.0] - 2026-11-05](#1130---2026-11-05)
- [[1.12.1] - 2026-11-04](#1121---2026-11-04)
- [[1.12.0] - 2026-11-04](#1120---2026-11-04)
- [[1.11.0] - 2026-11-04](#1110---2026-11-04)
- [[1.10.1] - 2026-10-30](#1101---2026-10-30)
- [[1.10.0] - 2026-10-30](#1100---2026-10-30)
- [[1.9.0] - 2026-10-29](#190---2026-10-29)
- [[1.8.0] - 2026-10-29](#180---2026-10-29)
- [[1.7.0] - 2026-10-28](#170---2026-10-28)
- [[1.6.0] - 2026-10-26](#160---2026-10-26)
- [[1.5.5] - 2026-10-25](#155---2026-10-25)
- [[1.5.4] - 2026-10-24](#154---2026-10-24)
- [[1.5.3] - 2026-10-20](#153---2026-10-20)
- [[1.5.2] - 2026-10-18](#152---2026-10-18)
- [[1.5.1] - 2026-10-13](#151---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.9] - 2026-10-13](#149---2026-10-13)
- [[1.4.8] - 2026-10-09](#148---2026-10-09)
- [[1.4.7] - 2026-09-27](#147---2026-09-27)
- [[1.4.6] - 2026-09-25](#146---2026-09-25)
- [[1.4.5] - 2026-08-24](#145---2026-08-24)
- [[1.4.4] - 2026-08-20](#144---2026-08-20)
- [[1.4.3] - 2026-08-19](#143---2026-08-19)
- [[1.4.2] - 2026-08-18](#142---2026-08-18)
- [[1.4.1] - 2026-08-07](#141---2026-08-07)
- [[1.4.0] - 2026-08-04](#140---2026-08-04)
- [[1.3.2] - 2026-07-29](#132---2026-07-29)
- [[1.3.1] - 2026-07-27](#131---2026-07-27)
- [[1.3.0] - 2026-07-27](#130---2026-07-27)
- [[1.2.8] - 2026-07-22](#128---2026-07-22)
- [[1.2.7] - 2026-07-22](#127---2026-07-22)
- [[1.2.6] - 2026-07-16](#126---2026-07-16)
- [[1.2.5] - 2026-07-15](#125---2026-07-15)
- [[1.2.4] - 2026-07-15](#124---2026-07-15)
- [[1.2.3] - 2026-07-13](#123---2026-07-13)
- [[1.2.2] - 2026-07-08](#122---2026-07-08)
- [[1.2.1] - 2026-07-07](#121---2026-07-07)
- [[1.2.0] - 2026-07-05](#120---2026-07-05)
- [[1.1.0] - 2026-07-04](#110---2026-07-04)
- [[1.0.1] - 2026-07-04](#101---2026-07-04)
- [[1.0.0] - 2026-07-04](#100---2026-07-04)

## [Unreleased]

One icon per action on the manage page, and a retention notice in the viewer's language. See [UPGRADING](UPGRADING.md#unreleased).

### Changed

- **New share** (header and empty state, `web_ui.create_mode: dialog`) is kind `share` instead of `create`; it stays primary and keeps its label. **New share** and **Delete all** now draw their kind's icon next to the label (`Action::display: icon_text`, no icon name in the bundle). With `icon_set: none` they stay text.
- **Extend** is kind `extend` (was `restore`) and **Revoke** is kind `revoke` (was `cancel`), so they get their own icons. Requires **UiKit 2.35** (`^2.35`), the first release with these kinds.
- **View** no longer forces `variant: primary`: a filled primary never goes in a table row. It resolves to UiKit's neutral row variant, and a host paints it through the `action_neutral` slot.
- Dev image: `intl` and full ICU data in the root `Dockerfile`, needed by the tests of the new catalogues.

### Fixed

- **Retention purge (it deletes secrets, see [UPGRADING](UPGRADING.md#unreleased)):** `shares.retention.max_age` with several terms had only its first term negated, so `1 month 2 days` meant minus one month plus two days. Every `<N> <unit>` term is now negated, in the purge and in the config validator, whatever separates the terms (blanks, line breaks, no-break spaces, commas). Single-term values give the same cutoff as before.
- **Retention purge, data loss:** a `max_age` whose cutoff is not in the past (`-14 days`, `1 month ago`, `0 days`) deleted every live share. The config validator now refuses it when the container is built, so this is a behaviour change for such a host. The purge also skips such a cutoff, and a malformed one, and logs an error; `nowo:yopass:purge-old-shares` then prints an error and exits with a failure code. See [UPGRADING](UPGRADING.md#retention-a-cutoff-not-in-the-past-is-refused).
- **Retention purge, deep past:** a cutoff before the year 1001 (`100000 years`) made the purge throw on MySQL, so a create answered 500 with the share saved. It is refused the same way.
- **Retention purge, word forms:** a `max_age` that is not plain `<N> <unit>` terms (`0 days midnight`) logs a `warning` on each purge. It still purges; refusing it waits for a major version.
- **Retention notice**: `%max_age%` of `yopass.list.retention_notice` was the raw PHP modifier ("más de 14 days"). `N minutes|hours|days|weeks|months|years` (singular or plural, optional `+`) now print a translated duration in all 7 locales, through ICU plurals in the new `NowoYopassBundle+intl-icu.<locale>.yaml` catalogues. Any other modifier, a PHP without `MessageFormatter`, or a translator that does not know the duration prints the new `yopass.list.retention_notice_unspecified` without a duration. 

## [1.14.1] - 2026-11-06

Dialog rule v2: one primary in the create dialog; the result step offers Close.

Dialog rule v2 (owner decision D-20261105-54). No configuration change. See [UPGRADING](UPGRADING.md#unreleased).

### Changed

- **Create dialog: exactly one primary.** The result-step button (shown once the links are created, replacing
  Cancel and Create) is now a **secondary Close** (was a second primary **Done**; Beacon UI-PATTERNS §16.6: a result
  step has Close only). Its label reuses `yopass.preview.close` (7 locales); `yopass.create.done` is no longer
  rendered but stays in the catalogue. The dialog's only primary is **Create link**, Cancel first. The same applies
  to the in-body button of a non-footer async form. The preview dialog is unchanged (owner decision 1.14: **Show
  content** stays the single primary in the body, attached to the key / password field; footer Close only).
- **Dialog height: none of the bundle's own.** The create and preview dialogs keep UiKit's medium / wide widths and
  take UiKit's height; with UiKit **2.31** (dialog rule v2, its new default `theme.dialog.height: content`) that is the
  content's height capped at `min(--nowo-dialog-height-md|-wide, 100dvh - 2rem)`, only the body scrolling, header
  (title + ×) and footer always in view. The bundle adds no height rule, so a host's `theme.dialog.height` choice wins.
  The 1.14 CHANGELOG wording "fixed width and height" no longer describes the target: the width is fixed, the height
  is the kit's.

[1.14.1]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.14.1

## [1.14.0] - 2026-11-05

UiKit 2.29 dialog sizes and anatomy, icons on the create Text / File tabs, a clearer Extend section in the preview
dialog. Requires `nowo-tech/ui-kit-bundle` **^2.29**. See [UPGRADING](UPGRADING.md#unreleased).

### Changed

- **Dialogs follow UiKit 2.29 "Dialog sizes and anatomy"** (UiKit `docs/PAGE_CONTRACT.md`, Beacon UI-PATTERNS §16):
  the create dialog is **medium** and the preview dialog **wide**, with the size's fixed width and height (moving
  between steps never jumps); only the body scrolls. The create dialog's actions moved to the footer, outside the
  scrolling form: **Cancel** (secondary) + **Create link** (primary, `type="submit" form="nowo-yopass-create-dialog-form"`),
  replaced by **Done** once the links are shown. The `nowo-yopass-create` controller now sits on a wrapper `<div>`
  around the `<dialog>` (new partial `manage/_create_controller_attrs.html.twig`). Revoke / delete / delete-all
  confirmations are UiKit's and unchanged. The bundle's own dialog flex / scroll / max-width CSS is gone (UiKit owns it).
- **Preview dialog:** the single primary **Show content** stays in the body, attached to the key / password field
  (owner decision: Beacon §16 input-group rule); the footer holds only **Close**.
- **Extend section** (owner review): "Current limits" is now **Now: …**; the two selects and the button sit on **one
  row** (`[Add time ▾] [Raise reads to ▾] [Extend]`), bottom-aligned, wrapping to a column under ~560 px (container
  query); the button is renamed **Extend** (es "Ampliar", 7 locales), stays secondary and is **disabled** while both
  selects keep "No change…"; a live **Will be: …** line previews the result (new expiry from the option's interval,
  reads left after raising the maximum) with the existing date formatting. After a successful extension the dialog
  updates **in place** (header badge, "Now" line, both selects reset, inline success with `aria-live="polite"`) — no
  page reload any more; errors show inline next to the row (`role="alert"`). Shorter field labels in 7 locales.
- **Create tabs:** the Text / File switch renders through the UiKit `tabs` partial (`role="tablist"`, links
  `href="#"` with `role="tab"`), with an icon each, so it follows the host tab style.

### Added

- `web_ui.icons.create_tab_text` / `web_ui.icons.create_tab_file` (defaults `tabler:file-text` / `tabler:file-upload`;
  null or blank = label only); Twig context variable `nowo_yopass_icons`.
- Expiration `<option>`s of the extend select carry `data-seconds` (the interval in seconds) for the live preview.
- **Configuration validation at container build:** `web_ui.icons.*` must match the kits' icon grammar
  (`Configuration::TAB_ICON_PATTERN`, `[prefix:]name`, as WikiBundle 1.18; null / `''` = no icon), and every
  `shares.expiration_options.*.interval` must be a PHP relative modifier that moves time forward (a malformed one
  used to fail only on create / extend). New `Support\RelativeInterval`.
- Translations `yopass.tab.label`, `yopass.create.cancel`, `yopass.extend.preview`, `yopass.extend.summary`,
  `yopass.extend.reads_one`, `yopass.extend.reads_other` (7 locales).

### Fixed (pre-release audit FO)

- **FO-L1:** an extend response arriving after the preview dialog was closed (or another share opened) is ignored and
  its request aborted, so it can no longer write the previous share's limits — or its embedded key — into the open
  share's dialog.
- **FO-I1:** Extend is disabled while its request is in flight (a double click no longer extends twice).
- **FO-I3:** a malformed expiration interval no longer makes the manage page fail (500) when the preview form is
  built; the option simply has no live preview.

### Documentation

- `ShareListRouteResolver` docblock: the `_list` HMAC key is the raw `kernel.secret`, with the message label
  `nowo_yopass.list_route|` (audit EW-I1); docs say the token is bound to the viewer's `getUserIdentifier()` (EW-I2);
  CONFIGURATION / UPGRADING: host filter values are user input — validate them, bind them as query parameters, and
  return from `overrideList()` only shares the viewer may see, since it skips `CreatorSubjectGuard` (EW-I4).

[1.14.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.14.0

## [1.13.0] - 2026-11-05

One create action per screen, the manage list on a host page (allow-listed route, signed for the viewer), host list filters, hardening headers on every route, no purge on GET (indexed retention). See [UPGRADING](UPGRADING.md#to-1130).

### Added

- **Host page for the list:** `YopassEvents::SHARE_LIST_ROUTE` (`ShareListRouteEvent::setRoute($name, $params)`) lets a host that shows the manage list on its own page (e.g. `/projects/{id}/secrets`, forwarding to the list controller) set that page's route. The pager, the filter form and the redirects after revoke / delete / delete all use it. The route must be the manage route or listed in the new `web_ui.list_routes`; parameters must be scalars.
- **Redirect safety (REQ-SEC-004):** the action forms carry the list route as a hidden `_list` token: route name + scalar parameters, HMAC-SHA256 signed with `kernel.secret` for the viewer that rendered the list. The handlers verify the signature for the current viewer and the allow-list, then let the router build the URL; a missing, forged, foreign, not-allowed or unbuildable value falls back to the manage route. No URL is ever posted or accepted.
- **Host list filters:** `YopassEvents::SHARE_LIST_FILTERS` (`ShareListFiltersEvent::getFormBuilder()`): fields a host adds form a GET filter form (`filters[...]`, no CSRF: read-only search) rendered in the UiKit filters region (Symfony form rendering, escaped). Its data reaches `ShareListQueryEvent::getFilters()`; the filter query is kept across pages (scalar leaves only) and the clear link goes back to the list page.
- `ShareLister::list()` takes the filter data; `ShareListQueryEvent` has `getFilters()`; `CsrfActionType` option `list_token`.

- **Security headers on every bundle route (KIT-70):** new `security_headers.enabled` (default **true**): `SecurityHeadersListener` sends `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`, `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'` (appended to the host policy when it has none, or sent alone) on the manage, create, created, preview, extend, revoke, delete, delete-all, reveal and consume routes.
- **Indexes for the retention purge:** `<table>_created_at_idx` and `<table>_expires_at_idx` on the shares table (ORM, named after the configured table) and on `createdAt` / `expiresAt` (MongoDB). Generate a migration (see UPGRADING).

### Changed

- **Preview dialog layout** (owner review): the status badge sits inline next to the title; the key / password input is full width in an input group with **Show content** (the dialog's only primary button) attached, plus one short help line (`yopass.preview.key_help` reworded in 7 locales); the revealed text is a monospace block with **Copy**; the public link is a full-width read-only input with a copy icon button (UiKit `copy` kind) that shows a toast (`yopass.link.copied`, 7 locales); **Extend** is its own section after a divider with "Current limits" as a muted line, the two selects in a 2-column grid that stacks on phones and a **secondary** "Apply extension" aligned to the end; "Close" on the right; the body scrolls inside the dialog with no horizontal scroll at 390 px. Layout comes from the new `css/yopass.css` (layout only, on UiKit tokens; UiKit has no input-group / field-grid slot yet), loaded by the manage page in `nowo_ui_styles`. The dialogs carry `data-nowo-yopass-dialog` (the 1.12 `class` attr of the create dialog was ignored by UiKit's attribute merge). Extend has no other form (row action → same dialog).
- **No purge on the list GET (PERF-YOPASS-PURGE-ON-GET):** the manage list no longer deletes the viewer's old shares while rendering. Retention runs on the create write path (the creator's old shares) and in `nowo:yopass:purge-old-shares` (schedule it).
- **One create action per screen (dialog mode):** the header **New share** action is shown only when the list has rows; an empty list carries it in the empty state alone.

[1.13.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.13.0

## [1.12.1] - 2026-11-04

### Fixed

- **500 on the manage page in dialog create mode with the `ux_icon` icon set.** The "New share" header and empty-state actions passed `icon: 'add'`, which is not a UiKit icon kind (`ux_icon` resolved it to the non-existent `bi:add`). They now use the action kind `create` (UiKit's canonical "add" icon). New regression test `UiKitIconKindsTest`: every icon / kind the bundle hands to UiKit (view builders and Twig literals) must be a canonical UiKit icon kind.

[1.12.1]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.12.1

## [1.12.0] - 2026-11-04

Create in a dialog (opt-in `web_ui.create_mode: dialog`, async JSON create, result and wipe in the dialog), per-row actions from `SHARE_ACCESS_CHECK`, revoke / extend events, host list columns, "Delete all" only with own shares. See [UPGRADING](UPGRADING.md#to-1120).

### Added

- **`web_ui.create_mode: page|dialog`** (default `page` = 1.11 behaviour). In `dialog` mode the manage page has a primary **New share** header action and the same action in the empty state (`yopass.create.open`, icon `add`, `data-testid` `yopass-create-open` / `yopass-create-open-empty`); they open `manage/_create_dialog.html.twig` (UiKit `<dialog>`, id `nowo-yopass-create-dialog`) instead of rendering the create card.
- **Async create (dialog mode).** The browser still encrypts first; then `nowo-yopass-create` `fetch`es the form (same CSRF token, `Accept: application/json`, `credentials` / `mode: same-origin`, `redirect: error`, `cache: no-store`) instead of `form.submit()`. `ShareManageController::create()` answers JSON when the request asks for it: `201 {id, public_path, created_url}` (`ShareCreatedEvent` dispatched as before, `no-store` + `no-referrer`), `422 {error: CREATE_FAILED | FILE_NOT_ENABLED}`, `403 {error: CROSS_SITE}` when `Sec-Fetch-Site` is present and not `same-origin` (the same fetch-metadata check refuses a non same-origin page-mode POST with a plain 403); never a flash. The response never echoes the ciphertext, a key or plaintext.
- **Result in the dialog.** One-click link (first when `sharing.default_embed_in_url`, only when `sharing.allow_embed_in_url`), short link, decryption key, copy buttons and the remember notice, built by the new shared module `yopass-share-links.ts` (also used by the created page). Custom-password mode omits the key field and shows `yopass.result.password_note`. The key / password stays in the dialog's memory and fields only: never `sessionStorage`, never the server.
- **Wipe on every close** (Done `yopass.create.done`, close button, Escape, backdrop, `nowo:overlay-close`): form reset, secret / password / hidden ciphertext emptied, result fields cleared and hidden, any pending `sessionStorage` key removed. After a create, closing dispatches the cancelable bubbling `nowo-yopass-create:created` event (`detail: {id}`) and reloads the page unless a listener prevented it. A close during encryption sends nothing; a close while the request is in flight aborts it (`AbortController`) and a request token makes any late response write nothing (pre-release audit ET-L1); the event then carries `detail.id = null`. The client accepts only a same-origin `public_path` without `.` / `..` segments, `%2e`, `%5c`, backslashes or control characters (ET-I2).
- **Per-row actions from `SHARE_ACCESS_CHECK`.** The manage list builds each row's View / Extend / Revoke / Delete through `ShareAccessGuard::canManage()` (`Preview`, `Extend`, `Revoke`, `Delete`), once per row and action, on top of the page-wide `security.roles.*` flags; revoke / delete CSRF forms are only built for the rows that get the action. The routes keep their own server-side checks.
- **`YopassEvents::SHARE_REVOKED`** (`ShareRevokedEvent`: share id, creator, revoked by, revoked at, payload kind; new `Service\ShareRevoker`) and **`YopassEvents::SHARE_EXTENDED`** (`ShareExtendedEvent`: share id, creator, extended by, old / new expiry, old / new max reads, payload kind; `ShareExtender::extend()` takes the acting user), dispatched after the flush. Metadata only; covered by the zero-knowledge test.
- **Host list columns:** `YopassEvents::SHARE_LIST_COLUMNS` (`ShareListColumnsEvent::addColumn(new Ui\ShareListColumn($key, $label, $valueProvider, $priority))`). Columns are appended after the built-in ones; cell values are plain text printed with Twig auto-escaping (`—` when empty).
- **`ShareListQueryEvent::setOwnTotal()`**: the "Delete all" header action is shown only when the viewer owns shares (a listener's figure, else the default query's total, else a `countByCreator($viewer)`).
- Translation keys `yopass.create.open`, `yopass.create.done`, `yopass.result.password_note` (7 locales).

### Changed

- **The type switch** (Text / File tabs) is rendered only when file shares are enabled.
- **Create form markup:** the form moved to `manage/_create_form.html.twig` (shared by the card and the dialog) and the `nowo-yopass-create` controller now sits on a wrapping `<div class="nowo-yopass-create__root">` instead of the `<form>`. Host overrides of `_create_panel.html.twig` that copied the 1.11 form markup keep working (the controller attributes on the form still work); see UPGRADING.
- `ShareCreatedLinksType` options `target_controller` / `id_prefix` (defaults unchanged) so the dialog reuses it.
- `ShareManageController` takes optional `ShareRevoker`, event dispatcher and `$createMode` arguments (autowired / configured; appended with defaults).

[1.12.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.12.0

## [1.11.0] - 2026-11-04

Host hooks for 1.11.0: CSP nonce on yopass.js, scoped `'wasm-unsafe-eval'` helper, share lifecycle events (metadata only), scoped / vetoable "delete all", paging on `ShareListQueryEvent`, no `ShareAccessLog` mapping when the access log is disabled, natural Spanish (and other locales) wording. See [UPGRADING](UPGRADING.md#to-1110).

### Added

- **CSP nonce on `yopass.js`.** The `<script type="module">` of the manage, "share created" and public reveal pages carries `nonce="…"` when the host sets the request attribute `csp_nonce` (the hook the UiKit layouts use), so `script-src 'nonce-…' 'strict-dynamic'` policies no longer block it. No nonce: the tag is rendered exactly as before.
- **`'wasm-unsafe-eval'` scoped to the libsodium pages.** Parameter `nowo_yopass.csp.wasm_routes` (route names of the three pages that load yopass.js: `routes.manage`, `routes.created`, `routes.public_show`) and opt-in `nowo_yopass.csp.wasm_unsafe_eval` (default `false`): `EventListener\WasmCspListener` appends `'wasm-unsafe-eval'` to the existing `Content-Security-Policy` / `-Report-Only` `script-src` (or a `script-src` derived from `default-src`) on those routes only. It never creates a policy, never touches other routes, keeps `'none'` policies and is a no-op when the keyword or `'unsafe-eval'` is already there. Documented in CONFIGURATION and SECURITY.
- **Share lifecycle events (zero-knowledge payloads).** `YopassEvents::SHARE_CREATED` (`ShareCreatedEvent`, from `ShareCreator`), `YopassEvents::SHARE_DELETED` (`ShareDeletedEvent`, manage delete) and `YopassEvents::SHARES_BULK_DELETED` (`ShareBulkDeletedEvent`, "delete all" and retention purges with `reason` `delete_all` / `retention`). Payloads are ids, creator, acting user, dates, read counters and payload kind only: never the ciphertext, a key, a password or decrypted content, and never the `SecureShare` entity. A test asserts this (`ShareLifecycleEventsZeroKnowledgeTest`).
- **Scoped / vetoable "delete all".** `YopassEvents::SHARE_DELETE_ALL` (`ShareDeleteAllEvent`) is dispatched before `removeAllByCreator()`: listeners may perform a tenant / team scoped removal themselves (`markHandled($count)`), narrow it to another user (`setSubject()`) or `veto()` it (nothing removed, error flash `yopass.error.delete_all_denied`). A `veto()` after `markHandled()` is ignored, logged as a warning, and the bulk-deleted event still fires. New service `Service\ShareDeleter`.
- **Paging on `ShareListQueryEvent`:** `getPage()`, `getPageSize()`, `getOffset()`, `isPaginated()` so an `overrideList()` listener can query one page only. The new constructor arguments are optional (`$pageSize = 0`, `$page = 1`).
- Translation key `yopass.error.delete_all_denied` (7 locales).

### Changed

- **`ShareAccessLog` is no longer mapped when the access log is off.** With `access_log.enabled: false` (or the MongoDB / custom non-relational backends, which force it off) the new `AccessLogMappingPass` excludes the entity from the Doctrine ORM attribute drivers, so schema tools no longer create an empty `<table_prefix>_share_access_logs` table. See [UPGRADING](UPGRADING.md#to-1110) for existing tables.
- **Translations.** Spanish no longer says "share(s)": "secreto(s)", "compartir un secreto", "Mis secretos compartidos", "enlace directo (un clic)", "contenido cifrado". The same untranslated words were replaced in German ("Freigabe(n)", "Direktlink"), Italian / Portuguese / Dutch ("segreto(i)", "segredo(s)", "geheim(en)", "link diretto", "ligação direta", "directe link") and French ("lien direct", "contenu chiffré").
- `ShareListQueryEvent` clamps the page to `MAX_PAGE` (1,000,000) and to the largest page whose offset fits in an integer: `?page=9223372036854775807` no longer ends in a `TypeError` / 500 (pre-existing).
- `ShareCreator`, `ShareRetentionPurger` and `ShareManageController` accept an optional event dispatcher / `ShareDeleter` (autowired; constructor arguments appended with defaults).

### Security

- **Creator-query subjects are type-checked (pre-release audit EL-L1).** The creator queries compare `creator = :subject` and Doctrine binds any managed entity by its id, so a tenant / team entity set as subject would select or delete the shares of the **user** with the same id. `ShareDeleter::deleteAll()` and `ShareLister::list()` now accept only the viewer or a `nowo_yopass.user_class` instance (`Support\CreatorSubjectGuard`); any other subject throws a `LogicException` before anything is queried or removed, unless the listener handled the result itself (`markHandled()` / `overrideList()`). The pre-existing `ShareListQueryEvent::setListSubject()` coercion is covered too (see UPGRADING). Regression test on a real SQLite ORM repository reproducing the audit PoC (tenant id 7 vs user id 7).
- **No secret material in logs or flashes** is now tested: create, delete and delete-all run with a capturing PSR logger, a redirected PHP `error_log` and the session flash bag, and none may contain the ciphertext placeholder; a source scan (`SecretMaterialSourceScanTest`) forbids `error_log` / `var_dump` / `print_r`-style dumps in `src/`, limits logger calls to reviewed files and restricts `getCiphertext()` to the entity / document, the Mongo repository mapping, `ShareRetriever` and the public reveal controller.
- UPGRADING warns that the `DROP TABLE` proposed for a disabled access log deletes the access history recorded while it was enabled, and shows the `schema_filter` to keep it.

[1.11.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.11.0

## [1.10.1] - 2026-10-30

Security: Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-1101).

### Security

- **Yopass routes registered without the route loader (REQ-SF-004 rule 7):** on Symfony >= 7.3 `#[AsController]` and the `#[Route]` attributes autoconfigured `ShareManageController` and `PublicShareController` with the `routing.controller` tag, so a host whose `config/routes.yaml` has the default `controllers: { resource: routing.controllers }` got the default `/tools/yopass/...` routes and the anonymous `/share/{id}` reveal / consume endpoints even without importing the `nowo_yopass` loader, bypassing `nowo_yopass.routes`, `route_prefix` and `web_ui.enabled: false`. The controllers are registered explicitly (`autoconfigure: false`, `controller.service_arguments` + `container.service_subscriber`, no `#[AsController]`); the routes exist only where the host imports `type: nowo_yopass`. Regression test with a kernel that only loads `routing.controllers`.

[1.10.1]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.10.1

## [1.10.0] - 2026-10-30

Yopass: UiKit page contract (guest reveal page, confirm dialogs, no CDN), no-store/no-referrer on secret pages, preview wiped on close See [UPGRADING](UPGRADING.md#to-1100).

Yopass on the UiKit page contract (REQ-UI-003): list / show scaffolds fed by view models, public reveal page on the UiKit guest layout, canonical `web_ui.*` / `security.roles.*` keys with deprecated aliases. See [UPGRADING](UPGRADING.md#to-1110).

### Template contract

- **Manage page** `manage/index.html.twig` extends `@NowoUiKit/page/list.html.twig` (`View\ListPage` from `Ui\YopassPageFactory::managePage()`); data block `nowo_ui_list_row`; `nowo_ui_content` adds the create panel (`manage/_create_panel.html.twig`) and the preview dialog (`manage/_preview_dialog.html.twig`, UiKit `dialog` partial, native `<dialog>`). Header, back link, Delete all, pagination, empty state, row actions (View, Extend, Revoke, Delete) and confirm dialogs come from UiKit.
- **Share created page** `manage/created.html.twig` extends `@NowoUiKit/page/show.html.twig` (`View\ShowPage`); data block `nowo_ui_show_sections`.
- **Public reveal page** `public/reveal.html.twig` is a unique page on the UiKit guest layout (`nowo_ui_layout.guest` or `web_ui.public_layout_template`); it no longer prints its own `<html>` document nor loads Tabler from a CDN.
- **Removed:** `layout.html.twig` (default shell loading Tabler / Bootstrap / icon fonts from CDNs; the 1.x value of `web_ui.layout_template` maps to the UiKit layout) and `_action_icons.html.twig` (row actions are UiKit `Action`s with canonical kinds `view`, `restore`, `cancel`, `delete`). The 1.x Bootstrap `div.modal` preview and the `nowo-yopass-confirm` controller (`window.confirm()`) are gone (UiKit dialog / confirm contracts).
- No framework utility classes in bundle markup, form types or scripts: theme slots (`nowo_ui_class()`), `nowo-ui-*` and `nowo-yopass-*` hooks only; toggled elements use the `hidden` attribute instead of `d-none`. Form fields carry `nowo-ui-input` (FormKit profile `yopass` without framework classes); the host form theme styles them.
- New `docs/TEMPLATES.md` (pages → scaffolds, view models, data blocks, unique pages) and the shared contract test `tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict missing-translation rule).

### Added

- `web_ui.path_prefix`, `web_ui.public_layout_template`, `web_ui.templates.{manage,created,public}` and `security.roles.{admin,create,list,revoke}` (REQ-UI-003 vocabulary).
- `Ui\YopassPages` (layouts, per-page templates, deprecated 1.x layout bridge `_legacy_layout.html.twig`) and `Ui\YopassPageFactory` (UiKit view models).
- Translation key `yopass.list.actions` (7 locales).
- Container compile error when the built-in role checker is used and every role list is empty (nobody could open the manage UI).

### Changed

- Requires `nowo-tech/ui-kit-bundle` `^2.15.4` (was `^1.4 || ^2.2`).
- `web_ui.layout_template` defaults to `null` (UiKit `nowo_ui_kit.layouts.admin`); `web_ui.css_framework` / `icon_set` default to `null` and, when set, are only copied to `nowo_ui_kit` (the CSS stack is inherited from UiKit).
- The FormKit profile `yopass` seeded by the bundle no longer adds Bootstrap classes (`form-control`, `form-select`, `mb-2`, …); it sets `nowo-ui-input` and `convention_requires_translation: true`.
- `ShareManageController` / `PublicShareController` take `Ui\YopassPageFactory` instead of the `$templates` array.

### Security

- **Reveal page on a host layout (REQ-SEC-004, delta re-audit 2026-10-30, Low).** The public reveal page now extends the host-overridable UiKit guest layout (`web_ui.public_layout_template`, else `nowo_ui_kit.layouts.guest`), so a third-party script loaded there (analytics, tag manager, session replay, chat widget) could read the `#decrypt_key=` fragment, the key / password input and the decrypted plaintext. USAGE, TEMPLATES, CONFIGURATION and SECURITY now state that the guest / public layout must not load third-party scripts (dedicated minimal layout + strict CSP).
- The public reveal page (`GET /share/{id}`) and the "share created" page send `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer` (shared `PublicShareController::hardenSecretResponse()`).
- The manage preview dialog wipes the decrypted text, the key / password inputs, the public link and the decrypted file download link (blob URL revoked) on **every** close — close button, Escape and backdrop (native `close` event) — instead of only resetting its internal state, so no plaintext stays in the hidden DOM. `src/Resources/public/js/yopass.js` rebuilt.

### Deprecated

- `route_prefix` (use `web_ui.path_prefix`), `templates.layout` / `templates.{manage,created,public}` (use `web_ui.layout_template` / `web_ui.templates.<page>`), `security.{admin,create,list,revoke}_roles` (use `security.roles.<action>`), `web_ui.css_framework` / `web_ui.icon_set` (use `nowo_ui_kit`), the Twig globals `nowo_yopass_layout_template` / `nowo_yopass_css_framework` / `nowo_yopass_icon_set`, `layout_integrate_host.html.twig` and 1.x layouts without the UiKit canonical blocks (bridged). All removed in 2.0.

[1.10.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.10.0

## [1.9.0] - 2026-10-29

Yopass: decryption key kept in the URL fragment (never sent to the server), recipe APP_SECRET removed, password mode works (libsodium sumo) See [UPGRADING](UPGRADING.md#to-190).

Yopass: one-click links carry the key in the URL fragment and honour the embed options, no `APP_SECRET` in the Flex recipe, working password mode. See [UPGRADING](UPGRADING.md#to-1110).

### Security

- **REQ-SEC-004 M1 — the `sharing.allow_embed_in_url` / `sharing.default_embed_in_url` options had no effect up to 1.8.0.** The "share created" page always offered a one-click `/share/{id}?decrypt_key=…` link (and the manage preview embedded the stored key in its public link), so the key or password was sent to the server in the query string, where web-server / reverse-proxy access logs, the Symfony router log and APM tools could record it. Now:
  - One-click links put the key in the **URL fragment** (`/share/{id}#decrypt_key=…`), which browsers never send to the server.
  - The one-click link is rendered only when `sharing.allow_embed_in_url` is `true` (default `true`); otherwise the page shows the short link and the key separately. `sharing.default_embed_in_url: true` lists it first; with the default `false` it comes after the short link and the key. The manage preview embeds the stored key in its public link only when embedding is allowed.
  - New `ShareKeyQueryRedirectListener` (`kernel.request`, priority 64, before the router): a `GET|HEAD` of the public share page carrying `decrypt_key` / `key` / `password` in the query string is answered `303 See Other` to the same path with the key moved into the fragment (`Cache-Control: no-store`, `Referrer-Policy: no-referrer`). Old links keep working, the router never logs the key, and it no longer stays in the address bar or the history. The first request of such an old link still reaches the web server / proxy, which may have logged it.
- **REQ-SEC-004 M2 — the Flex recipes (1.0.0 and 1.1.0) appended `APP_SECRET=ChangeMeInProduction` to the host `.env`.** Flex appends the recipe block unconditionally, and Dotenv keeps the last definition, so this public value could override the host secret (`kernel.secret` signs fragment URIs, remember-me cookies, login links, `UriSigner` URLs). The `env` block is removed from both recipe manifests; the bundle needs no secret. Hosts that installed the recipe must remove the line and rotate `APP_SECRET` (see UPGRADING).

### Fixed

- **REQ-SEC-004 L1 — password mode did not work in the browser.** The shipped `yopass.js` was built with the standard `libsodium-wrappers`, which has no `crypto_pwhash` (Argon2id); creating a password-protected share failed (closed: nothing was posted) and password envelopes could not be decrypted. The bundle now depends on `libsodium-wrappers-sumo` `^0.8.4` (same API and version line) and `src/Resources/public/js/yopass.js` is rebuilt. Raw library errors on create are shown as `CREATE_FAILED` instead of the library message.
- **REQ-SEC-004 L2** — with `web_ui.enabled: false` the route loader registers only the public routes (`/share/{id}`, `/share/{id}/consume`). Before, the manage routes stayed routed while the CSRF / SecurityBundle compile checks were skipped.
- Manage preview: after extending a share, the re-rendered public link kept no key because `renderMetadata()` was called without the share id (`tsc` error).

### Tests

- Vitest: password-mode encrypt / decrypt round trips (text and file) against the real libsodium build, no mock; fragment link build / read / strip; created page and preview with embedding allowed / not allowed; reveal from a `#decrypt_key=` fragment.
- PHPUnit: `ShareKeyQueryRedirectListenerTest`, route loader with the Web UI disabled, `ShareCreatedLinksType` `allow_embed_in_url` option.

### CI

- TypeScript job runs `pnpm run typecheck` (`tsc --noEmit`) before Vitest; new `make typecheck` (part of `make release-check`).

[1.9.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.9.0

## [1.8.0] - 2026-10-29

Yopass: injected clock in share creation and public rate limiter, TS coverage over every source, Twig override docs See [UPGRADING](UPGRADING.md#to-180).

### Changed

- **REQ-DI-001** — `ShareCreator` computes the expiration date of new shares from an injectable `Psr\Clock\ClockInterface` (new optional third constructor argument; autowired to the framework `clock`), and `PublicEndpointRateLimiter` uses the clock instead of `time()` for its fixed window (new optional fifth argument, wired to `Psr\Clock\ClockInterface` when the service exists). `ShareCreateData::resolveExpiresAt()` accepts an optional `$now` reference time. Without a clock service both fall back to the system clock, so behaviour is unchanged. See [UPGRADING](UPGRADING.md#to-1110).

### Documentation

- **REQ-TWIG-001** — USAGE “Twig overrides”: loader order, upgrade-safe customisation table, freeze rule, override procedure, full `<subpath>` table and recovery after a freeze.
- **REQ-SEC-002** — SECURITY release checklist (12.4.1) now covers every item (SECURITY.md, `.env` ignore, secrets, config/recipe, input/output, dependencies, logs, cryptography, permissions/exposure, limits/DoS, AI audit).
- **REQ-DI-001** — USAGE “Clock” section lists the clock-aware services and the documented entity exception.
- **REQ-CS-001** — PHPDoc description on every public class and public method in `src/`.
- **REQ-TEST-007** — README “Tests and Coverage” shows the measured PHP and TypeScript percentages.
- DEMO-FRANKENPHP: “Database (MySQL)” section and corrected demo command list.

### Tests

- **REQ-TEST-003** — Vitest coverage now measures every TypeScript source under `src/Resources/assets/src/` (previously only `yopass-crypto.ts`) and fails below 90% lines / statements / functions / branches. New happy-dom tests for the Stimulus controllers and helpers (`happy-dom` dev dependency).
- Clock-driven tests for `ShareCreator`, `ShareCreateData` and `PublicEndpointRateLimiter`.

### Demo

- **REQ-DEMO-006 / REQ-DEMO-011** — removed the unused Flex PostgreSQL `database` service, its volume and the tracked `compose.override.yaml` (published port 5432); MySQL is the only demo database.
- **REQ-MAKE-003** — demo Makefile adds `restart`, `setup`, `test-coverage` and `verify` (HTTP 200 on `/tools/yopass`).

[1.8.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.8.0

## [1.7.0] - 2026-10-28

Security: the admin UI refuses to compile without form CSRF protection (CsrfProtectionPass, fail closed). See [UPGRADING](UPGRADING.md#to-170).

### Security

- CSRF fails closed: new `CsrfProtectionPass` makes the container refuse to compile when the manage Web UI (`web_ui.enabled: true`) is on and form CSRF protection is unavailable (`security.csrf.token_manager` or `form.type_extension.csrf` missing, or `framework.form.csrf_protection: false`). Before, share revoke / delete / delete all (`CsrfActionType` declares its own CSRF options, checked by `isCsrfActionValid()`) and the create form accepted tokenless cross-site POSTs in that configuration. `symfony/security-csrf` is now a hard requirement. See [UPGRADING](UPGRADING.md#to-1110) and [SECURITY](SECURITY.md#threats-and-mitigations).

[1.7.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.7.0

## [1.6.0] - 2026-10-26

Forms in the bundle views are Symfony Form Types rendered with form_start / child loop / form_end, no raw form controls (REQ-TWIG-005). See [UPGRADING](UPGRADING.md#to-160).

### Changed

- Bundle views render every control through Symfony Form Types (REQ-TWIG-005): the revoke / delete / delete-all actions use `CsrfActionType` (same flat `_token` and token ids; the controller validates them through the Form instead of `isCsrfTokenValid()`), the browser-only secret / file / encryption mode / custom password controls are unmapped children of `ShareCreateType` (fixed ids and Stimulus targets, empty `name`, so the posted payload is unchanged), and the preview / extend modal, the created page and the public reveal page use `SharePreviewType`, `ShareCreatedLinksType` and `ShareRevealType`. `ShareCreateType` gains the `allow_custom_password` and `default_encryption` options. Template overrides must render the new form variables (see UPGRADING).

### Added

- `make check-no-raw-html-form` (REQ-TWIG-005) fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle and demo Twig; it runs in `release-check`.

[1.6.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.6.0

## [1.5.5] - 2026-10-25

Reproducible JS bundle: the build stamp is a hash of the sources instead of the build time. See [UPGRADING](UPGRADING.md#to-155).

### Changed

- **Assets:** reproducible bundle build. `__YOPASS_BUILD_TIME__` now holds a build id (a hash of the TypeScript/CSS sources and `pnpm-lock.yaml`) instead of the build time, so rebuilding unchanged sources (`make assets`, `make release-check`) no longer rewrites the committed JS; the console line reads `script loaded, build: <id>`.
- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `check-twig-extra`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `validate-translations`, `coverage-check`, demo release-check, `test-ts`). `check-no-raw-html-form` (REQ-TWIG-005) is wired once the remaining raw form markup is migrated.

[1.5.5]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.5

## [1.5.4] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-154).

### Changed

- Views: `manage/index.html.twig` keeps the Stimulus-targeted hidden `ciphertext` / `payloadKind` widgets (rule 3)
  and renders the remaining create-form children (expiry, max reads, CSRF token) through the child loop
  (`{% for child in form %}` + `not child.rendered` + `form_row(child)`), closing with `form_end(create_form)`, per
  REQ-TWIG-003. The `mb-3` row and `form-label` label classes of `expiresIn` / `maxReads` moved into
  `ShareCreateType` (`row_attr` / `label_attr`).

[1.5.4]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.4

## [1.5.3] - 2026-10-20

Dependency refresh (bundle and demos), php-cs-fixer ParallelConfig(2). See [UPGRADING](UPGRADING.md#to-153).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19); shipped assets rebuilt.
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README standard badges (PHP 8.2+, Symfony 7.4 | 8.0 | 8.1+, stars image, Coverage) and documentation list order, CONTRIBUTING Cursor Agent settings, canonical bug-report template, demo `.env.test` comment, SPEC-DRIVEN-DEVELOPMENT functional scope / non-goals / see also, doc TOCs, Spec Kit inventory refresh (77/77).

[1.5.3]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.3

## [1.5.2] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-152).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock and the demo lock use 2.2.0. Templates are unchanged (compatibility release).

[1.5.2]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.2

## [1.5.1] - 2026-10-13

### Changed

- **Dev:** Vitest 4 (`vitest` / `@vitest/coverage-v8` `^4.1.11`).
- Docs: `docs/SECURITY.md` threat model lists the `payloadKind` validation added in 1.5.0.

[1.5.1]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-10-13

### Fixed

- Share links on the "created" page and in the manage preview (`public_path` / `publicPath`) are now generated by the router from `routes.public_show.name`, so `route_prefix`, extra route placeholders (e.g. `{_locale}`) and an app base path are honoured instead of copying the raw configured path.

### Security

- `ShareCreateType` validates the hidden `payloadKind` field with `Choice(['text', 'file'])`; any other value is rejected (`yopass.error.create`) instead of being stored.

### Changed

- CI: dropped the Symfony 7.0 matrix cell (and the 7.0 coverage install), which could not resolve against the `^7.4 || ^8.0` constraints; coverage now runs on Symfony 7.4.

[1.5.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.5.0

## [1.4.9] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `vite` 8.3.2 → 8.3.4 (frontend tooling; #66).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #67).
- `nowo-tech/form-kit-bundle` 2.6.1 → 2.7.1 (development lock; #68).
- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #69).

[1.4.9]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.9

## [1.4.8] - 2026-10-09

### Security

- **Public share rate limiting actually enabled:** `YopassExtension` checked `has('cache.app')` / `has('logger')` inside `load()`, where the isolated extension container never sees framework services, so `PublicEndpointRateLimiter` always received a `null` cache pool (rate limiting on `/share/*` silently skipped) and a user warning fired on every container compile. The limiter now gets `cache.app` and `logger` as optional references resolved on the merged container; the "cache.app missing" warning moved to the new `PublicRateLimitCachePass`.

### Changed

- PHPStan: use the published `ruleset-worker.neon` only (drop the local no-kernel-reset ignores).
- Dependencies (Dependabot + lock refresh): `doctrine/orm` 3.7.4, FormKitBundle 2.6.1, UiKitBundle 1.9.2; dev Vite 8.3.2, PHPStan 2.3.1 (+ phpunit/symfony extensions 2.1), PHPUnit 10.5.66, PHP-CS-Fixer 3.95.27, Rector 2.7.0, `igor-php/igor-php` 0.10.1, `nowo-tech/phpstan-frankenphp` 1.2.3, `mongodb/mongodb` 2.5.0.
- Demo (Symfony 8): `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, FormKitBundle 2.6.1, UiKitBundle 1.9.2, Twig 3.30.0, HotReloadBundle 1.5.5, TwigInspectorBundle 1.1.7; regenerated `config/reference.php`.

[1.4.8]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.8

## [1.4.7] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.4.7]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.7

## [1.4.6] - 2026-09-25

### Fixed

- **FrankenPHP worker (`FRANKENPHP_RESET_KERNEL` unset/false):** ORM repositories resolve the EntityManager from `ManagerRegistry` per call and reset it when a previous flush closed it, so one failed write no longer breaks later requests in the same worker (W-01).
- **FrankenPHP worker:** MongoDB share repository likewise resolves/reopens the DocumentManager via `doctrine_mongodb` registry when closed.
- **FrankenPHP worker:** `ShareRepositoryInterface::find()` always reads from the database (`Query::HINT_REFRESH` for ORM, `DocumentManager::refresh()` for MongoDB), so a share revoked, extended, consumed or deleted by another worker is never served from a stale identity map (W-01).
- **Doctrine ORM:** `DoctrineOrmShareRepository::consumeReadIfAvailable()` no longer calls `EntityManager::clear()` (which detached host application entities); the consumed share is re-read with a refresh instead (W-03).

### Added

- **`docs/FRANKENPHP-WORKER-AUDIT.md`** — full worker audit for kernel reuse without reset (scenario B / `FRANKENPHP_RESET_KERNEL` unset/false).
- **PHPStan** — enable `ruleset-worker-no-kernel-reset.neon` (missing `ResetInterface` detection for shared mutable services).

## [1.4.5] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.4.5]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.5

## [1.4.4] - 2026-08-20

### Security

- **Flex recipe:** ship `security_nowo_yopass.yaml` (`/share` public, `/tools/yopass` `ROLE_ADMIN`); `when@prod` keeps `default_embed_in_url: false`. REQ-SEC-004 **Pass (good)**. Prefer **`^1.4.4`**.

## [1.4.3] - 2026-08-19

### Changed

- Code style only (PHP CS Fixer). **No application upgrade steps.**

[1.4.3]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.3

## [1.4.2] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.4.2]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.2

## [1.4.1] - 2026-08-07

### Fixed

- **CI / FormKit:** `ControllerContainerBuilder` injects `FormOptionsMerger` via `FormKitTestSupport::withMerger()` for `ShareCreateType` (fixes uninitialized `$formOptionsMerger` in controller unit tests).
- **PHPStan:** simplify `prependUiKitDefaults` (remove always-true `is_array` / empty-defaults guards).

### Tests

- Cover FormKit / UiKit prepend seed and host-override paths in `YopassExtensionTest`.

[1.4.1]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.1

## [1.4.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `yopass` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Manage/public Twig imports `@NowoUiKitBundle/macros/ui.html.twig`; removed `_ui_macros.html.twig`. Domain icons live in `_action_icons.html.twig`. Requires `nowo-tech/ui-kit-bundle` `^1.4`. Extension seeds `nowo_ui_kit` from `web_ui` when the host has not configured UiKit.

[1.4.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.4.0

## [1.3.2] - 2026-07-29

### Added

- **REQ-SEC-002** — 12.4.1 release checklist row for REQ-SEC-004 Pass (conditional).
- **REQ-DOCS-018** — GitHub About homepage Packagist + topic `frankenphp`; composer `homepage` / keywords (`php`, `frankenphp`).

### Changed

- **REQ-CS-006** — removed the PHPStan baseline, set `ignoreErrors: []`, and fixed the remaining level-8 findings in controllers, DI, Doctrine metadata remapping, form typing, and the Mongo repository count path.
- **Coverage gate** — `make coverage-check` is now wired into `release-check` and enforces a 99% PHP statements threshold through `scripts/check-coverage.php`; README coverage wording/badge were aligned. The optional MongoDB ODM repository is excluded from the PHPUnit source gate because final ODM query internals are impractical to isolate with the current unit-only test setup.
- **Tests** — expanded unit coverage for Doctrine ORM repositories, access-log repositories, route loading, compiler passes, events, form pre-submit handling, retention purging, metadata listeners, and share retrieve/extend flows.

## [1.3.1] - 2026-07-27

### Added

- **REQ-DI-001** — `Psr\Clock\ClockInterface` (via `symfony/clock`) on share retrieve/extend/purge services and Doctrine share repositories; optional constructor arg defaults to `Symfony\Component\Clock\Clock` for manual wiring in tests.
- **REQ-REL-003 / REQ-MAKE-002** — `make check-open-prs` (`.scripts/check-open-prs.sh`) wired into `release-check`.
- **REQ-SF-005** — `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in `phpunit.xml.dist`.
- **REQ-DOCS-018** — GitHub About: plain-text description, website, and topics (`php`, `symfony`, `symfony-bundle`, …).

### Changed

- **REQ-SEC-004** — security docs reflect public rate limits and observability posture for re-audit (see [SECURITY.md](SECURITY.md)).

## [1.3.0] - 2026-07-27

### Added

- **`web_ui` configuration (REQ-UI-001)** — `layout_template`, `css_framework`, `icon_set`, Twig globals via `YopassTwigExtension` (`nowo_yopass_layout_template`, `nowo_yopass_css_framework`, `nowo_yopass_icon_set`), `_ui_macros.html.twig`, and `layout_integrate_host.html.twig` bridge example.
- **`security.allow_unauthenticated` (REQ-UI-002)** — compile-time SecurityBundle guard for the manage UI (`ManageWebUiSecurityPass`).
- **REQ-CS-005** — `nowo-tech/phpstan-frankenphp` (require-dev) with classic + worker rulesets; FrankenPHP Friendly banner in README (REQ-DOCS-017).
- **REQ-MAKE-007** — `make down-dev` (non-destructive alias of `down`).
- **REQ-MAKE-008** — demo `update-deps` / `update-deps-all` targets aligned with shared monorepo scripts.
- **REQ-TEST-005** — `make test-with-db` / `test-coverage-with-db` aliases.
- **REQ-TEST-011** — `make -C demo demo-smoke` alias for the demo HTTP 200 healthcheck.
- **REQ-OBS-001** — structured rate-limit warnings via `LoggerInterface` (hashed client IP; never secrets/ciphertext).
- **REQ-SF-004** — `#[AsController]` / `#[Route]` on manage and public controllers; `YopassRouteLoader` imports those attributes and applies configurable path/name overrides (compatible with Symfony Routing 7.4 getters and 8.1 public properties; FrankenPHP-worker safe).
- **Stimulus `nowo-yopass-confirm`** — confirm destructive manage actions without inline `onclick` (REQ-UX-001).
- **Demo FrankenPHP boot** — entrypoint waits for Composer `vendor/` before starting worker mode so `make up` can install deps after `compose up`.

### Changed

- **Security defaults (REQ-UI-002)** — `security.access_roles`, `create_roles`, `list_roles`, and `revoke_roles` default to `[ROLE_ADMIN]` instead of `[ROLE_USER]`. Override in config and align `security.access_control` in your app. See [UPGRADING.md](UPGRADING.md).
- **Manage Twig layout** — pages extend `nowo_yopass_layout_template`; `stylesheets` / `javascripts` blocks with `{{ parent() }}` for host asset stacking; `templates.layout` is a BC alias for `web_ui.layout_template`.
- **REQ-UI-001** — manage/public templates route buttons and action icons through `_ui_macros.html.twig` (`btn`, `btn_icon`, `action_icon`).
- **REQ-DEMO-010** — Symfony 8 demo FrankenPHP image bumped to PHP **8.5**.
- **REQ-UX-001** — Stimulus create controller decodes HTML entities without `innerHTML`.
- **REQ-TEST-010** — PHPUnit `#[DataProvider]` replaces docblock `@dataProvider`.

## [1.2.8] - 2026-07-22

### Changed

- **Code style** — apply PHP-CS-Fixer `fully_qualified_strict_types` (`import_symbols`) across bundle sources and tests.
- **Dependencies** — `doctrine/dbal` 4.4.4, `doctrine/doctrine-bundle` 2.18.4 (`composer.lock`); demo lock bumps (`doctrine-bundle` 3.3.0, `nowo-tech/twig-inspector-bundle` 1.0.37).

## [1.2.7] - 2026-07-22

### Added

- **Demo `FRANKENPHP_MODE`** — switch FrankenPHP `classic` vs `worker` via `.env` / Compose (default `worker`); documented in [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md).
- **`docs/GITHUB_CI.md`** — expanded REQ-GIT-001 operator manual (adoption checklist, pitfalls, multi-bundle tools, acceptance criteria).

### Changed

- **REQ-GIT-001 scripts** — `check-no-cursor-coauthor` uses `--no-replace-objects`; strip script requires a clean working tree before rewrite.
- **CI** — `actions/setup-node` 6 → 7.
- **Frontend** — Vite 8.1.4 → 8.1.5.
- **PHP-CS-Fixer** — `fully_qualified_strict_types` with `import_symbols: true`.

### Fixed

- **Demo** — `reference.php` / path lock sync for local path repository.

## [1.2.6] - 2026-07-16

### Added

- **`docs/GITHUB_CI.md`** — GitHub Actions CI requirements for REQ-GIT-001 (verify, rewrite history, job example).
- **`make strip-cursor-coauthor-from-history`** — local history rewrite via `.scripts/strip-cursor-coauthor-from-history.sh` when trailers were already pushed.

### Changed

- **`check-no-cursor-coauthor`** — clearer errors (bundle-local `.git` guard, offending commit list).
- **CONTRIBUTING / README** — link to GITHUB_CI and history-rewrite guidance.
- **Dev dependencies** — `friendsofphp/php-cs-fixer` 3.95.15 (`composer.lock` only).

### Fixed

- **Demo** — `demo/symfony8/config/reference.php` sync (strict types / CS Fixer).

## [1.2.5] - 2026-07-15

### Added

- **REQ-GIT-001 hardening** — `make setup-hooks` (installs `commit-msg` hook; runs on `make up`); CI job `git-hygiene` on every push/PR; post-commit audit documented in CONTRIBUTING and RELEASE.

### Changed

- **Git hooks / audit** — `commit-msg` and `check-no-cursor-coauthor` also strip/detect same-line Cursor co-author trailers.
- **Dev dependencies** — `friendsofphp/php-cs-fixer` 3.95.14, `rector/rector` 2.5.7 (`composer.lock` only).

## [1.2.4] - 2026-07-15

### Added

- **Code of Conduct** — Contributor Covenant (`CODE_OF_CONDUCT.md`); linked from README and CONTRIBUTING.
- **Git hygiene** — `commit-msg` hook and `check-no-cursor-coauthor` script to block Cursor agent `Co-authored-by` trailers; integrated into `make release-check`. Cursor rule `.cursor/rules/01-git-commits.mdc`.

### Changed

- **Frontend dev dependencies** — TypeScript 7.0.2, Vite 8.1.4 (`package.json` / lockfile).
- **Code style** — PHP CS Fixer pass on bundle sources.

## [1.2.3] - 2026-07-13

### Changed

- **CI** — bump `actions/setup-node` from 4 to 6.
- **Dev dependencies** — `doctrine/mongodb-odm` 2.16.3, `friendsofphp/php-cs-fixer` 3.95.13, `rector/rector` 2.5.6 (`composer.lock` only; no runtime bundle changes).

### Fixed

- **`.gitignore`** — ignore machine-specific `.cursor/sandbox.json`.

## [1.2.2] - 2026-07-08

### Added

- **GitHub Spec Kit** — baseline under `specs/001-baseline/` (100% `src/` inventory), `.specify/` scaffolding, Cursor Agent skills (`.cursor/skills/speckit-*`), and operator manual [`docs/SPEC-KIT.md`](SPEC-KIT.md).
- **Spec-driven development** — expanded [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md) with contributor workflow and Spec Kit cross-links.

### Changed

- **README** — link to Spec Kit documentation.

## [1.2.1] - 2026-07-07

### Fixed

- **`PublicEndpointRateLimiter`** — exclude from autowire scan so Symfony apps build the container correctly (explicit wiring via `YopassExtension`).
- **Demo local repository** — `LocalOffloadingShareRepository` implements `consumeReadIfAvailable()` (delegate + hydrate file references).
- **S3 scaffold** — generated `S3OffloadingShareRepository` includes `consumeReadIfAvailable()`.

## [1.2.0] - 2026-07-05

### Added

- **Public rate limiting** — `PublicEndpointRateLimiter` on anonymous `/share/*` show and consume (config: `public_rate_limit`; requires `cache.app`).
- **Atomic consume** — `ShareRepositoryInterface::consumeReadIfAvailable()` prevents concurrent read over-consumption (ORM DQL update + MongoDB find-and-update).
- **Translations** — German, French, Italian, Dutch, and Portuguese (`NowoYopassBundle` domain).
- **Tests** — `PublicEndpointRateLimiterTest`, `ShareRetrieverTest`, `ShareConsumeFlowIntegrationTest`; fixed `PublicShareControllerTest` for rate limiter.
- **CI** — PHPStan, Vitest, and `composer audit` jobs.
- **Flex recipe `1.1.0`** — full route set and security post-install notes.

### Fixed

- **`ShareExtendException`** — expose `errorCode` property used by the extend JSON endpoint.
- **SECURITY.md** — document `?decrypt_key=` query-string risks vs short links and URL fragments; public rate limiting and atomic consume.
- **CONTRIBUTING.md** — align quality-check commands with Makefile/composer scripts.
- **Reveal template** — `crossorigin` and `referrerpolicy` on Tabler CDN stylesheet.

### Changed

- **Code style** — Rector constructor promotion across bundle sources; PHPStan baseline for remaining level-8 findings.

## [1.1.0] - 2026-07-04

### Added

- **Share events** — `ShareListQueryEvent`, `ShareListResultEvent`, and `ShareAccessCheckEvent` to customize manage list queries and per-share access without built-in teams/ACL.
- **`ShareLister`** and **`ShareAccessGuard`** services wired into `ShareManageController` (creator remains the default list subject and access grant).
- **`ShareAccessAction`** enum (`View`, `Preview`, `Extend`, `Revoke`, `Delete`) for per-route access checks.
- **Examples** — `examples/access-control/` (teams, individual grants, role-based access) and [docs/examples/AccessControl.md](examples/AccessControl.md).

### Fixed

- **`composer.lock`** — sync content-hash with `composer.json` (`composer validate --strict` in CI).
- **Local dev** — `COMPOSER_IGNORE_PLATFORM_REQ=ext-mongodb` in `docker-compose.yml` (MongoDB is require-dev only).

## [1.0.1] - 2026-07-04

### Fixed

- **CI** — Symfony 8 matrix jobs use `doctrine/doctrine-bundle ^3.0` (2.x does not support Symfony 8).
- **CI** — ignore optional `ext-mongodb` platform requirement for dev dependencies in GitHub Actions.

### Changed

- **`composer.json`** — declare `symfony/form` and `symfony/validator` for Symfony 8 (`^7.4 || ^8.0`).
- **GitHub Actions** — bump `actions/checkout`, `actions/cache`, and `codecov-action` (Dependabot).

## [1.0.0] - 2026-07-04

First stable release of **Yopass Bundle**.

### Added

- **E2E encrypted sharing** — browser-side libsodium secretbox; server stores ciphertext only.
- **Manage UI** — authenticated list, create (Symfony form POST + redirect), preview, extend, revoke, delete, and delete-all.
- **Public routes** — anonymous reveal page and consume endpoint (JSON ciphertext).
- **Link formats**
  - Short link: `/share/{id}` (recipient pastes decryption key in the UI).
  - One-click link: `/share/{id}?decrypt_key=…` (auto-reveal on load).
  - Legacy support when reading: `#fragment`, `?key=`, and `?password=`.
- **Share options** — configurable expiration ids, max-read limits, and list pagination (`shares.list_page_size`).
- **Retention** — automatic purge of shares older than `shares.retention.max_age` (UI + `nowo:yopass:purge-old-shares` command).
- **Access log** — optional audit trail of public link opens (Doctrine ORM).
- **File shares** — optional `ShareFileHandlerInterface` (text-only when not configured).
- **Encryption modes** — auto-generated key or custom password (`sharing` config).
- **Configuration** — `user_class`, `table_prefix`, routes, templates, firewall hints, and pluggable `YopassAccessCheckerInterface`.
- **Persistence** — Doctrine ORM (PostgreSQL, MySQL, MariaDB, SQLite, SQL Server, Oracle), MongoDB ODM, or custom repository.
- **TypeScript / Stimulus** — Vite + pnpm bundle (`yopass.js`, asset package `nowo_yopass`).
- **Translations** — `NowoYopassBundle` domain (EN/ES).
- **Demo** — Symfony 8.1 + FrankenPHP + MySQL (`demo/symfony8/`).
- **Tooling** — PHPUnit (100% PHP line coverage target), Vitest (crypto), PHP-CS-Fixer, Rector, PHPStan, GitHub Actions CI, Symfony Flex recipe.

### Requirements

- PHP >= 8.2, < 8.6 with `ext-sodium`
- Symfony ^7.4 || ^8.0
- Doctrine ORM ^2.15 || ^3.0 (or MongoDB ODM / custom repository)

[Unreleased]: https://github.com/nowo-tech/YopassBundle/compare/v1.14.1...HEAD
[1.3.0]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.8...v1.3.0
[1.2.8]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.7...v1.2.8
[1.2.7]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.6...v1.2.7
[1.2.6]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.5...v1.2.6
[1.2.5]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.4...v1.2.5
[1.2.4]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.3...v1.2.4
[1.2.3]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/nowo-tech/YopassBundle/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/nowo-tech/YopassBundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/YopassBundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/nowo-tech/YopassBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/YopassBundle/releases/tag/v1.0.0
