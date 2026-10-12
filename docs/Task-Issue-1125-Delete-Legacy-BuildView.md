# Task #1125 — delete the legacy Builds & Releases cluster

**Issue:** [#1125 Cleanup: delete legacy buildView (Refs #1122 #1123 #1124)](https://github.com/sebiboga/testlink-upgraded/issues/1125)
**Area:** Test Plan → Builds / Releases
**Type:** cleanup / legacy retirement (no functional change)

## Why the cleanup was blocked, and why it is now safe

The issue is explicit: *"After buildsView gaps #1122 (custom fields), #1123 (event
history) and #1124 (closed-on-date display) are implemented, remove the retired
legacy Builds & Releases screens."* All three blockers are **CLOSED**, so the
retirement is unblocked.

## What was actually left (state at HEAD before this change)

When the issue was written (2026-09-06) the four listed files were the live legacy
renderer + its Smarty template. Since then they were already reduced to redirect
shims and dead templates:

| File | State before #1125 | Size |
|---|---|---|
| `lib/plan/buildView.php` | redirect-only shim (#1791, `dbaaf6451`) | 81 L |
| `lib/plan/buildEdit.php` | redirect-only shim (#1787, `883e38b29`) | 66 L |
| `gui/templates/dashio/plan/buildView.tpl` | legacy Smarty template — **dead** | 158 L |
| `gui/templates/dashio/plan/buildEdit.tpl` | legacy Smarty template — **dead** | 175 L |

The two controllers no longer call Smarty (they `echo` a
`window.location.replace(...)` onto `plans/buildsView.html` /
`plans/buildEdit.html`), so the dashio templates are loaded by nothing and the
shims are reachable only from a stale bookmark. This matches the follow-up note
`docs/Bugfix-Issue-1791-SuiteView-Build-View-Custom-Fields-Fatal.md` — *"the legacy
chain keeps growing shims … Follow-up cleanup, cf. #1125"*.

## Change

Removed 4 files / **480 lines**:

- `lib/plan/buildView.php` (81)
- `lib/plan/buildEdit.php` (66)
- `gui/templates/dashio/plan/buildView.tpl` (158)
- `gui/templates/dashio/plan/buildEdit.tpl` (175)

The modern stack is unchanged: `gui/templates/plans/buildsView.html` +
`gui/templates/plans/buildEdit.html` + `api/builds/index.php`.
`lib/functions/common.php` already pointed `$actions->buildView` /
`$actions->buildEdit` at those; `lib/general/frmWorkArea.php` had no remaining
reference (the `buildEdit.php?do_action=create` hint named by the issue had
already been re-pointed). `testplan::getViewActions()` names only
`lib/plan/planView.php`, which is the separate task **#1120**.

## Deliberately left in place

- `gui/templates/tl-classic/plan/buildView.tpl` + `buildEdit.tpl` and
  `gui/templates/tl-classic/mainPageRight.tpl:21` — **dead code**: `TLSmarty`
  pins `template_dir` to `gui/templates/dashio/`, so this tree is never loaded
  (same decision as #957; the whole `tl-classic` tree is a known follow-up).
- Provenance/parity comments in `api/builds/index.php`, `plans/buildsView.html`,
  `plans/buildEdit.html` — kept as the audit trail, per the #947/#966 policy.
- `config.inc.php` `$tlCfg->gui->buildView->{pagination,itemQtyForTopButton}` and
  the `[buildEdit]`/`[buildView]` sections in
  `gui/templates/conf/input_dimensions.conf` are now orphaned — left as
  documented follow-ups (same conservative policy as #966/#976).
- The `builds` table schema is untouched (already project-scoped per #503).

## Verification

- `php -l lib/functions/common.php` → *No syntax errors detected*.
- Repo-wide grep for `plan/buildView|plan/buildEdit` leaves only comments and
  the dead `tl-classic` tree — no live `include`/`require`, no nav link.
- HTTP: `lib/plan/buildView.php` and `lib/plan/buildEdit.php` (with/without
  `tplan_id`) now answer **404**; `plans/buildsView.html` /
  `plans/buildEdit.html` still answer **200**.
- Browser (fresh fixture `tmp/fixtures_1124.php`, project 1 / plan 2 / builds 1-2):
  list renders the 2 builds; the inline **Create Build** modal opens and a new
  build (**Cleanup Verify Build**) persists; the **Create Build (full screen)**
  screen loads. 0 console errors.
- Event Viewer / `events`: only INFO (log_level 16) AUDIT rows; no Error/Warning.

Screenshot: `docs/screenshots/issue-1125-buildsview-after-cleanup.png`.
