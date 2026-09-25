# Task 970 — Implement `codetracker_management` write + UI-action gating in the Code Trackers screen (gap vs legacy)

**Issue:** [#970](https://github.com/sebiboga/testlink-upgraded/issues/970)
**Status:** IMPLEMENTED & VERIFIED (2026-09-25) — branch `task/issue-970-codetracker-management-gate`

## The gap

Legacy delimited **read** from **write** capability on Code Trackers:

- `lib/codetrackers/codeTrackerView.php:24` → `$gui->canManage = hasRight('codetracker_management')`.
- `gui/templates/dashio/codetrackers/codeTrackerView.tpl`: the Create button
  (`:91-96`), the wrench "check connection" link + connection icons (`:51-61`),
  the edit-link around the tracker name (`:64-70`) and the delete icon
  (`:76`, additionally only when `link_count == 0`) are ALL rendered only
  `{if $gui->canManage != ""}`. A view-only user (`codetracker_view`) sees a
  bare table with plain-text names.
- The write controller `lib/codetrackers/codeTrackerEdit.php:181-184` is
  reachable only via `checkRights()` = `codetracker_management`.

The modern BFF `api/codetracker/index.php` (after the #969 read gate) allowed any
**viewer** to write: `POST /` (create), `PUT /{id}` (update), `DELETE /{id}` and
`POST /test_github` all executed for a user holding only `codetracker_view` — a
privilege escalation (legacy denies even the edit *screen*, `codeTrackerEdit.php`).

## Measured evidence gathered during INVESTIGATION (before the fix)

Fixtures created via SQL: user `ctviewonly` (role 99, carrying ONLY the
`codetracker_view` right id 52; password admin).

- **API (ctviewonly, same-origin page `fetch`):** `POST /` → **200**, a tracker
  was INSERTed (codetrackers.id reached 1); `PUT /1` rename → **200**;
  `DELETE /1` → **200**; `POST /test_github` → **200**.
- **GET list:** `{status:ok, items:[], total:0}` — `canManage` key **ABSENT**.
- **UI (ctviewonly, browser snapshot):** toolbar `+ Create Code Tracker` button
  rendered — identical to admin.

## Server-side enforcement

`api/codetracker/index.php` now mirrors legacy `canManage` exactly. After the
existing #969 read gate, a second boolean is computed:

```php
$canManage = ($user->hasRight($db, 'codetracker_management') == 'yes');

function denyWrite($user, $userId, $action) {
    logAuditEvent(TLS('audit_security_user_right_missing', $user->login,
                      'api/codetracker/index.php', $action),
                  'WRITE', $userId, 'codetrackers');   // trailed BEFORE the denial
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission']);
    exit;
}
```

Every write route starts with `if (!$canManage) { denyWrite($user, $userId, '<op>'); }`:

- `POST /` (create) — action `create`
- `PUT /{id}` (update) — action `update`
- `DELETE /{id}` (delete) — action `delete`
- `POST /test_github` — action `test_github` (the create-modal "Test Connection"
  is part of the manage flow; legacy wrench is management-gated too)

`GET /` now returns `"canManage"` (mirrors legacy `$gui->canManage`) and every
item gains `"link_count"` (the BFF already fetched `add_link_count`, so the UI
can mirror the legacy `link_count == 0` delete condition):
`out(['status' => 'ok', 'items' => $items, 'total' => count($items), 'canManage' => $canManage])`.

Note: `hasRight()` returns `'yes'`/`null` (roles.inc.php:254-274), so the
`== 'yes'` normalization is required for clean JSON `true/false`.

## Client-side gating

`gui/templates/codetracker/codetrackerView.html`:

- `var canManage = false;`, set from the BFF payload: `canManage = !!r.canManage;`.
- Toolbar Create button got `id="createBtn"` and is toggled:
  `$('#createBtn').toggle(canManage);` (legacy tpl renders it only for managers).
- Row actions: the Edit icon is emitted only when `canManage`; the Delete icon
  only when `canManage && link_count === 0` (legacy `link_count == 0` gating).
- `table.column(4).visible(canManage)` collapses the Actions column for view-only
  users (mirrors legacy Delete `<th>` + icon gating) — a view-only user sees a
  bare table with plain-text names, exactly like 1.9.20.

No new i18n keys were required: every gated element reuses existing labels
(`ct.createTracker`, per-row `title="Edit"/"Delete"`), so none of the 10 locale
bundles needed changes.

## Verification

- `ctviewonly` (view-only): `POST`/`PUT`/`DELETE`/`test_github` → **HTTP 403**
  `No permission`; 4 AUDIT events (`activity=WRITE`, `object_type=codetrackers`);
  UI shows bare read-only list — no Create button, no Actions column.
- `admin` (manager): `canManage:true`, full UI; modal create → 200 (tracker id 2);
  rename (PUT) → 200; link fixture → delete icon hidden (`link_count == 1`);
  delete flow (id 3) → 200.
- Event Viewer: `log_level IN (1,2)` → 0 rows; all 13 events are AUDIT (16).
- Screenshots: `docs/screenshots/issue-970-viewonly.png`,
  `docs/screenshots/issue-970-admin-manage.png`.
- Test suite: `tmp/TLU_Test_Cases.md` → "Suite 970 — Task — Issue #970: …" 10/10 PASS.