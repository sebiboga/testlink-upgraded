# Task 960 — Implement `issuetracker_management` write gating in the Issue Trackers screen (gap vs legacy)

**Issue:** [#960](https://github.com/sebiboga/testlink-upgraded/issues/960)
**Status:** IMPLEMENTED & VERIFIED (2026-09-23) — branch `task/issue-960`

## The gap

Legacy delimited **read** from **write** capability on Issue Trackers:

- `lib/issuetrackers/issueTrackerView.php:24` → `$gui->canManage = hasRight('issuetracker_management')`.
- `gui/templates/dashio/issuetrackers/issueTrackerView.tpl`: Delete column/th
  (`:45`), wrench + connection icons and the link around the tracker name
  (`:54-73`), the delete icon (`:78-84`) and the **Create** button (`:95-100`)
  are ALL rendered only `{if $gui->canManage != ""}`. A view-only user
  (`issuetracker_view`, no management) sees a bare table with plain-text names.
- The write controller `lib/issuetrackers/issueTrackerEdit.php` (create/edit/delete)
  is reachable only via `checkRights()` = `issuetracker_management`.

The modern BFF `api/issuetracker/index.php` (after the #959 read gate) allowed the
**GUI** to show the full management UI to `issuetracker_view`-only users and the
**API** to accept write requests from them. This was a privilege escalation:
`GET /` returned 200 + full list to `itview`, the toolbar rendered Create/GitHub
and every row its Edit/Delete icons, and `POST /` / `PUT /{id}` / `DELETE /{id}` /
`POST /oauth/create` all executed (measured: `POST` + `DELETE` both 200).

## Measured evidence gathered during INVESTIGATION (before the fix)

Fixtures created via SQL: user `itview` (role with only the `issuetracker_view`
right, right id 32) and user `norights` (role with zero rights).

- **UI (itview, browser snapshot):** toolbar `+ Create Issue Tracker` + `GitHub`
  buttons and the `Actions` column header all present — identical to admin.
- **API (itview, same-origin page `fetch`):** `POST /` → **200**, tracker
  `itview-pwned-tracker` inserted (row id 1 confirmed via
  `SELECT * FROM issuetrackers`); `DELETE /1` → **200** `{"status":"ok"}`.
- **Console:** no JS errors during the flow.

## Server-side enforcement

`api/issuetracker/index.php` now mirrors legacy `canManage` exactly. After the
existing #959 read gate, a second boolean is computed for every request:

```php
$canManage = (bool)$user->hasRight($db, 'issuetracker_management');
$writeDenied = function () use ($user, $userId) {
    logAuditEvent(TLS('audit_security_user_right_missing', $user->login,
                      basename($_SERVER['PHP_SELF']), 'manage'),
                  'EDIT', $userId, 'issuetrackers');   // trailed BEFORE the denial
    http_response_code(403);
    out(['status' => 'error', 'message' => 'No permission']);
};
```

Every write route starts with `if (!$canManage) { $writeDenied(); }`:

- `POST /` (create) — `:121`
- `PUT /{id}` (update) — `:148`
- `DELETE /{id}` (delete) — `:170`
- `POST /oauth/create` (GitHub tracker create + project link) — `:~430`

`GET /` now also returns `"canManage"` so the front-end can gate the write UI:
`out(['status' => 'ok', 'canManage' => (bool)$canManage, 'items' => ..., 'total' => ...])`.

Note: `hasRight()` returns `null` (not `false`) when the right is absent, so the
cast to `bool` is required for clean JSON and identical `!$canManage` semantics.

## Client-side gating

`gui/templates/issuetracker/issuetrackerView.html` applies the same
`reqMgrSystemView.html` / `platformsView.html` pattern (already used by the
modernized screens):

- `var canManage = false;`, set from the BFF payload: `canManage = !!r.canManage;`.
- Toolbar buttons got `id="btnCreate"` / `id="btnGithub"` and are toggled:
  `$('#btnCreate').toggle(canManage); $('#btnGithub').toggle(canManage);`
  (legacy tpl renders Create + GitHub only for managers).
- Row actions: Edit/Delete icons are emitted only when `canManage`.
- `#thActions` header + last cell are removed when `!canManage` (mirrors legacy
  Delete `<th>` + delete icon gating) — a view-only user sees a bare table with
  plain-text names, exactly like 1.9.20.
- Defense-in-depth early returns in `showCreateModal/editTracker/deleteTracker/
  githubOAuthStart/createGithubTracker`; `checkOAuthConnected()` never auto-starts
  the GitHub OAuth flow or opens the repo picker for a non-manager.

No new i18n keys were required: every label reuses existing keys
(`it.createTracker`, `it.github`, `common.actions`, `common.edit`, `common.delete`,
`it.msg.noPermission`) already present in all 10 locale bundles.

## Verification

- `itview` (view-only): `POST`/`PUT`/`DELETE`/`oauth/create` → **HTTP 403**
  `No permission`; 4 AUDIT events (ids 6–9, `activity=EDIT`,
  `object_type=issuetrackers`); UI shows bare table — no Create/GitHub/Actions.
- `admin` (manager): `canManage:true`, full UI, `POST` → 200 (tracker id 2
  created), `DELETE` → 200 (cleanup).
- Event Viewer: `log_level IN (1,2)` → 0 rows; only intended AUDIT entries.
- Screenshots: `docs/screenshots/issue-960-itview-readonly-after-fix.png`,
  `docs/screenshots/issue-960-admin-manage-after-fix.png`,
  `docs/screenshots/issue-960-itview-viewonly-row-after-fix.png`.
- Test suite: `tmp/TLU_Test_Cases.md` → "Task — Issue #960: …" (see file).