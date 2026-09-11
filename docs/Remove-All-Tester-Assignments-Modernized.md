# Remove All Tester Assignments (tcUnassignAll) — Modernized Screen

Modernization of **Remove all tester assignments from a Build** — legacy
`lib/plan/tc_exec_unassign_all.php` (with its `doUnassignAll()`
`confirmed`-round-trip and `baseActions->doUnassignAll` JS hook in the legacy
Dashio filter panel). Tracked in
[issue #1434](https://github.com/sebiboga/testlink-upgraded/issues/1434).

The legacy controller, which the ASIDE `execute` area always routed through,
counted the execution assignments of a Build (`assignment_mgr
::get_count_of_assignments_for_build_id()`), showed either an
"assignments exist" warning or an "empty" info, and (on an explicit
`confirmed=yes` round trip) deleted all rows of the Build
(`assignment_mgr::delete_by_build_id()`). Our modernization keeps all of that:
the new Dashio standalone page is the ASIDE `execute → Remove all tester
assignments` entry point and is also reachable per-Build from the modernized
**Assign Test Case Execution** toolbar.

**Path:** Execute → Assign Test Cases to Execution (Build) → [Unassign all testers] button
**URL:** `gui/templates/execute/tcUnassignAll.html?tproject_id=<id>&tplan_id=<id>&build_id=<id>`
**BFF API:** `api/tcunassignall/index.php`
- `?action=info&build_id=<id>&tproject_id=<id>` (GET) → JSON `{status, build_id, build_name, tproject_id, tproject_name, count, can_remove}`
- `?action=unassign&build_id=<id>&tproject_id=<id>` (POST) → JSON `{status, removed_count, ...}`

**Rights:** `testplan_planning` on the owning test project (same gate the
legacy page used via its `rightsAnd` pageAccessCheck). The BFF enforces it on
every route server-side (401 unauthenticated / 403 without the right).

![Screen — 0 assignments](tcUnassignAll-screen.png)
![Modal — remove all](tcUnassignAll-modal.png)
![Screen — N assignments warning](tcUnassignAll-warn.png)

## What it does
- Resolves the Build by `build_id` and its owning test project (the build
  lookup is project-scoped, honoring the legacy `build->get_by_id()` signature).
- `info` returns the assignment count for the Build and whether removal is
  possible (`can_remove` = count > 0). Zero-count shows the legacy
  "There are no testers assigned to Test Cases in the Build X." state and
  disables the red button; positive count shows the
  "There are N execution assignments for Build X" warning and enables it.
- `unassign` (POST, same-origin-guarded) re-checks rights and calls
  `assignment_mgr::delete_by_build_id()` → success toast with removed count.
- Browser confirm modal: legacy-style warning (`$TLS_unassign_all_tcs_warning_msg`)
  with Yes/No, wired to the BFF on Yes.

## i18n
Keys `tua.*` (15) + `footers.tcUnassignAll` added to **all 10** locale bundles
(`gui/templates/i18n/en|ro|de|es|fr|it|ja|pt|ru|zh.json`), validated with
`python3 -m json.tool`.

## Security & legacy parity
- Same-origin guard (`api/_guard.php`) on every route; 401/403/404 JSON.
- Rights enforced server-side on every route (`testplan_planning`) — the
  modern screen never relies on client-side authorisation for the destructive
  action (same guarantee as the legacy controller's `hasRight` check).
- Legacy `lib/plan/tc_exec_unassign_all.php` untouched and still served for
  backward compatibility with bookmarks/older Permissions.

## Link switch (Refs #1434)
`$actions->tcUnassignAll = "/gui/templates/execute/tcUnassignAll.html?{$ctx}";`
added in `lib/functions/common.php` (`initUserEnv()`, guarded by
`testplan_planning` — same as the legacy launcher). The modernized
`tcExecAssignment.html` toolbar gained an **Unassign all testers from selected
Build** button that deep-links per selected build.

## Regression
Suite `1434.1`–`1434.16` appended to `tmp/TLU_Test_Cases.md` (16/16 PASS).
