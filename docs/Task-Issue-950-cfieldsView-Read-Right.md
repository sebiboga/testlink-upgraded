# Task 950 — Implement `cfield_view` read right in the Custom Fields screen (gap vs legacy)

**Issue:** [#950](https://github.com/sebiboga/testlink-upgraded/issues/950)
**Status:** IMPLEMENTED & VERIFIED (2026-09-22) — branch `task/issue-950-cfield-view-right`

## The gap

Legacy `lib/cfields/cfieldsView.php:30` lets any user holding **either**
`cfield_management` **or** `cfield_view` browse the custom-field list ("Readable
by any user holding EITHER cfield_management OR cfield_view; view-only users can
browse the custom-field list and even export — cfield_view gates Export").
The legacy export controller `lib/cfields/cfieldsExport.php:111` is gated on
`cfield_view` alone, so a view-only user may legitimately open the list AND
export XML. Write paths stay on `cfield_management`
(`lib/cfields/cfieldsEdit.php:494`, `lib/cfields/cfieldsImport.php`,
`lib/cfields/cfieldsTprojectAssign.php:150`).

When the issue was filed, the modern BFF `api/cfields/index.php:37` carried a
single blanket check `hasRight($db,'cfield_management')` that rejected EVERY
route — including the read-only list and the `/meta/type` + `/meta/nodes`
lookups — for view-only users. The modern page
(`gui/templates/cfields/cfieldsView.html`) fired its `$.getJSON` calls with no
`.fail` handler, so a `cfield_view`-only account (or an account with no cfield
right at all) got HTTP 403 on all AJAX calls and was left staring at a silent,
empty table with no error message.

## Measured evidence gathered during INVESTIGATION (before the fix)

Fixture: role `cf_viewer` (role_id 10) holding ONLY right id 17 (`cfield_view`);
user `cviewer`/`tester` with global role 10; user `cfnouser` with global role 3
(`<no rights>`). Two custom fields inserted (`priority_cf`, `env_cf`).

For `cviewer`:
- legacy `/lib/cfields/cfieldsView.php` renders full list + Create/Export/Import buttons;
- modern `/gui/templates/cfields/cfieldsView.html` → network panel:
  `GET /api/cfields/index.php` **403**, `GET /api/cfields/index.php/meta/types` **403**,
  `GET /api/cfields/index.php/meta/nodes` **403**; page = empty table, no console errors.

## Server-side enforcement (Refs #950)

`api/cfields/index.php` now splits the permission model per route, matching the
legacy controller-by-controller gates:

```php
$canView  = $user->hasRight($db, 'cfield_view') || $user->hasRight($db, 'cfield_management');
$canManage = $user->hasRight($db, 'cfield_management');
```

- global gate (`:50`) rejects only when `!$canView` — HTTP 403 `{"status":"error","message":"No permission"}`;
- READ routes (`GET /` list, `GET /{id}`, `GET /meta/types`, `GET /meta/nodes`)
  require `$canView`;
- WRITE routes (`POST /`, `PUT /{id}`, `DELETE /{id}`) require `$canManage` (`deny()` helper);
- all `/assignment` endpoints (`GET /assignment`, `POST /assignment/link`,
  `POST /assignment/unlink`, `PUT /assignment`) require `$canManage` — parity with
  legacy `cfieldsTprojectAssign.php:150`;
- `GET /` list payload now carries `can_manage` (0/1) so the client can render
  read-only state without a second round-trip.

## Client-side read-only rendering

`gui/templates/cfields/cfieldsView.html`:

- `.fail()` handlers added on `loadMeta()` (both meta lookups) and `loadCfields()`;
  on 403 `showDenied(xhr)` hides `.toolbar` + `.table-wrap` and renders the
  localized message `cf.msg.noPermissionLog` in the footer (no more silent blank).
- When `r.can_manage === 0`: the **Create Custom Field** and **Upload File**
  buttons are hidden, `editEnabled=false` removes every row's Edit/Delete icon
  and hard-guards `showCreateModal/editCf/saveCf/deleteCf`; the footer info line
  shows the `cf.msg.readOnly` hint. The **Export** button stays — a
  `cfield_view` user may export (legacy gate `cfieldsExport.php:111`).

No client-side data flow changes: the same list/type/node payloads are used.

## Stored-XSS hardening (discovered + fixed during testing, bug #1561)

While browser-testing, a custom field named `"> <img src=x onerror=alert(73)>`
fired `alert(73)` on screen load: DataTables writes row cells via `html()`, so
the unescaped `cf.label`/`cf.name` values executed as markup, and the previous
inline `onclick="deleteCf(id,'name')"` only escaped single quotes (a `"` in the
name broke out of the attribute). Fixed in the same pass:
- `escAttr()` helper (HTML-escapes `& < > " '`);
- name/label/type cell values passed through `escAttr()` before DataTables;
- the row trash icon now carries `data-delete-id`/`data-delete-name` (escaped)
  and a delegated `$(document).on('click', '.action-btn.danger[data-delete-id]', …)`
  handler calls `deleteCf(id, name)` with the decoded value — no inline JS string.

## i18n

Two keys added to **all 10** locale bundles
(`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`):
- `cf.msg.noPermissionLog` — full-page denial message (server 403 equivalent);
- `cf.msg.readOnly` — view-only toolbar hint.

All bundles re-validated with `python3 -m json.tool`.

## Verification evidence

- **cviewer** (right `cfield_view` only): `GET /`, `GET /1`, `GET /meta/types` →
  **HTTP 200**; `POST /`, `PUT /1`, `DELETE /3`, `GET /assignment?tproject_id=1`
  → **HTTP 403**. Page shows both fields, Export button, NO Create/Upload, no
  row Edit/Delete icons, "Read-only: …" hint.
- **cfnouser** (no cfield right): list call → **HTTP 403**, page renders only the
  localized "You do not have permission to view custom fields…" message; toolbar
  and table hidden.
- **admin**: full UI (Create/Export/Upload + Edit/Delete per row), create via
  modal → POST 200 → row appears (3 custom fields), list reloads; regression clean.
- Event Viewer/`events`: only log_level 16 audit INFO rows — **0 Error/Warning**.
- Browser console: no JS exceptions. Inline JS `node --check` clean; all 10 bundles valid.

Screenshots: `docs/screenshots/issue-950-cviewer-readonly.png`,
`docs/screenshots/issue-950-norights-denied.png`,
`docs/screenshots/issue-950-admin-full.png` (mirrored to wiki).

Fixture: role `cf_viewer` (10) + `cviewer` user + `cfnouser` user created via SQL
during the run (DB is freshly imported each CI run).

Refs #950.