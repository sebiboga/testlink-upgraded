# Task 931 — Localized success/failure feedback after saving (Assign Test Project Roles) (gap vs legacy)

**Issue:** [#931](https://github.com/sebiboga/testlink-upgraded/issues/931)
**Status:** IMPLEMENTED & VERIFIED (2026-09-18) — branch `task/issue-931`

## The gap

Legacy `lib/usermanagement/usersAssign.php:80-92` sets a `user_feedback` string
after an update attempt:
- an empty submitted role map → `user_feedback = lang_get('no_users_selected')`
  (`$TLS_no_users_selected = "No users selected - nothing done"`);
- a successful `doUpdate()` → `user_feedback = $gui->roles_updated` which is
  `lang_get('test_project_user_roles_updated')` = **"User Roles updated"**
  (usersAssign.php:51);

and `gui/templates/dashio/usermanagement/inc_update.tpl:26-35` renders that
feedback as a localized `div.user_feedback` box at the top of the page
(usersAssign.tpl:132-134).

The modern screen `gui/templates/usermanagement/usersAssignProject.html`
`saveAssignments()` did the opposite: on AJAX success it silently reloaded the
list (`success: function(){ loadUsers(currentProject); }`) with **no success
message**, and it had **no `error` handler at all** — an HTTP failure was a
silent uncaught ajax failure. The empty-assignment no-op was indistinguishable
from a real save.

## Legacy source of truth

- `lib/usermanagement/usersAssign.php:82-84` — empty map → `no_users_selected`;
- `lib/usermanagement/usersAssign.php:87-89` — after `doUpdate()` →
  `test_project_user_roles_updated` ("User Roles updated");
- `lib/usermanagement/usersAssign.php:557-573` — `doUpdate()` skips role 0
  entries (revert-to-inherited) and calls `deleteUserRoles()` + `addUserRole()`;
- `gui/templates/dashio/include/inc_update.tpl:26-35` — `$user_feedback` box.

## Modern implementation

- **BFF** (`api/roles/index.php`, PUT `/tproject-roles` only — the issue covers
  the project screen; the plan route keeps its bare `ok` for now):
  - empty-map no-op and admin-strip-to-empty both now return
    `out(['status'=>'ok','feedback_key'=>'no_users_selected'])`;
  - a real update returns `out(['status'=>'ok','feedback_key'=>'assign_roles_updated'])`.
  Both keys mirror the legacy lang keys so the client resolves the same
  localized strings legacy showed.
- **Screen** (`gui/templates/usermanagement/usersAssignProject.html`):
  - added the Dashio toast CSS (byte-identical base to `rolesView.html:49-51`,
    plus a new `.toast.warn` state for the "nothing done" notice) and a
    `#toast` element;
  - `toast(msg, cls)` helper — fixed bottom-right banner, auto-hides after 4s,
    resets `.ok/.warn/.err` classes between calls;
  - `assignFeedback(feedbackKey)` maps `assign_roles_updated` →
    `assign.rolesUpdated`, `no_users_selected` → `assign.noUsersSelected`;
  - `saveAssignments()` rewritten: `success` renders the toast from the BFF
    `feedback_key` (warning for `no_users_selected`, success otherwise) and
    THEN reloads the list; `error` resolves the localized message in order
    BFF `messageKey` → `code === 'demo_mode'` → `role.demoUpdateDisabled` →
    HTTP 403 → `common.forbidden` → generic `assign.updateFailed`. All toast
    text flows through jQuery `.text()`, so the raw-message fallback cannot
    inject HTML.
- **i18n**: `assign.rolesUpdated` ("User Roles updated"), `assign.noUsersSelected`
  ("No users selected - nothing done"), `assign.updateFailed` ("Error updating
  user roles") added to **all 10** locale bundles
  (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`), translated after
  the legacy `$TLS_*` strings where available.

## Screenshots

- `docs/screenshots/issue-931-assign-roles-success-toast.png` — green "User Roles
  updated" toast (.toast.ok) after Save.
- `docs/screenshots/issue-931-assign-roles-empty-warn.png` — amber "No users
  selected - nothing done" toast (.toast.warn) for the empty-assignment no-op.
- `docs/screenshots/issue-931-assign-roles-error.png` — red error toast
  (.toast.err) on a failed save.

## Verification evidence

- Success: change role + Save → `{"text":"User Roles updated","cls":"toast ok",
  visible}`; DB `user_testproject_roles` row updated; list reloads.
- BFF success payload: `PUT /roles/tproject-roles {tproject_id:1,assignments:{2:7}}`
  → `{status:ok, feedback_key:"assign_roles_updated"}`.
- Empty map (only global-admin row in state): `PUT ... {assignments:{}}` →
  `{status:ok, feedback_key:"no_users_selected"}`; screen shows the warn toast.
- BFF validation error (`tproject_id:0`): `400` → red toast "Missing tproject_id".
- 403 branch → "Insufficient rights"; demo-mode branch → "Demo mode enabled =>
  Update Role DISABLED"; generic 500 → "Error updating user roles" (all resolved
  through TLi18n).
- Event Viewer: only AUDIT (log_level 16) rows from Save ops; 0 rows ≥ 32.
  No browser console errors.
- Full manual pass: `tmp/TLU_Test_Cases.md` — "Task — Issue #931" — **12/12 PASS**.

Refs #931.