# User Management Export — Modernized

Modernization of the legacy **User Management Export** screen
(`lib/usermanagement/usersExport.php`,
`gui/templates/dashio/usermanagement/usersExport.tpl`) — tracked in
[#920](https://github.com/sebiboga/testlink-upgraded/issues/920), closing the
open feature gap [#880](https://github.com/sebiboga/testlink-upgraded/issues/880)
(user export to XML missing).

The legacy Smarty screen (a form submitting to `usersExport.php`) is replaced by a
standalone Dashio page (`gui/templates/usermanagement/usersExport.html`) backed by
a plain-PHP REST BFF API (`api/usersexport/index.php`). The **User Management →
Export** toolbar button now opens the modern screen instead of the legacy
`usersExport.php` form submit — the last legacy action still reachable from the
modern UI.

**Path:** System → User Management → toolbar **Export**
**URL:** `gui/templates/usermanagement/usersExport.html?tproject_id=..&tplan_id=..`
**BFF API:** `GET ?action=info` + `POST ?action=export` on `/api/usersexport/`
**Rights:** `mgt_users` (exactly like the legacy screen — only the admin role
holds it).

## Behavior

The BFF reproduces `lib/usermanagement/usersExport.php`:

- **Content:** full user list as XML — one `<user>` per account with the exact
  legacy 10 fields: `id`, `login`, `role_id`, `email`, `first`, `last`, `locale`,
  `default_testproject_id`, `active`, `expiration_date` (CDATA), generated with
  the ADODB_XML generator (`root <users>`, row `<user>`, same as legacy).
- **Default filename:** `users.xml` (empty → sent empty, client-side validated).
- **Filename override:** the input is pre-filled with `users.xml`; any custom
  name is honored in `Content-Disposition` after being sanitized by
  `basename()` (path-traversal safe).

## Flow

1. `GET ?action=info` (requires `mgt_users`) returns the default filename, the
   number of users to export and the grants map. The screen shows it in the
   export card.
2. User clicks **Export**.
3. `POST ?action=export` streams the XML as `text/xml; charset=ISO-8859-1` with
   `Content-Disposition: attachment` (`Pragma: public`, `Cache-Control:
   must-revalidate`).
4. Browser saves the XML via blob download (`X-Requested-With:
   XMLHttpRequest`); JSON errors surface as a toast.
5. **Back to User Management** returns to `usersView.html` in the same context.

## Error handling

- No `mgt_users` right → HTTP 403 `{"status":"error","message":"No rights",
  "right":"mgt_users"}` on BOTH routes; the screen shows the lock warn-box
  "You do not have the mgt_users right required to export users." and disables
  the Export button.
- Unauthenticated → HTTP 401 `"Not authenticated"`.
- Empty file name → client-side toast "Please provide a filename.", no POST.

## Security

- **Filename:** `basename()` + CR/LF/quote strip (no path traversal, no header
  injection).
- **XSS:** all DOM values escaped via `esc()`/`.text()`/`.val()`; server
  messages inserted as text.
- **SQL injection:** ids `intval()`-cast, no raw concatenation.
- **CSRF / same-origin:** `bffSameOriginGuard()` + `X-Requested-With` header.

## Link switch

- `usersView.html` toolbar gained an **Export** button
  (`data-i18n="user.export"`) wired to `gotoExport()`, which navigates the
  mainframe to `usersExport.html` carrying the current `tproject_id`/`tplan_id`.
- `lib/usermanagement/usersExport.php` is kept for backward compatibility.

## i18n

All 16 strings use `userexport.*` keys present in all ten locale bundles
(en/de/es/fr/it/ja/pt/ro/ru/zh), plus the `user.export` toolbar key.

## Test coverage

**Suite 71** in `tmp/TLU_Test_Cases.md` — 13/13 PASS (BFF info/export parity,
filename override + sanitization, empty-filename validation, 403 no-right both
routes + browser warn state, 401 unauthenticated, i18n in all bundles, Event
Viewer clean).