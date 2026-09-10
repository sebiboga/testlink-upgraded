# getReturnWorkArea(): project switch lands 6 features on MODERN screens — Issue #1330

> Zero-image mirror of the GitHub Wiki page
> `Issue-1330-ReturnWorkArea-Modern-Landing.md`.

## What was wrong

`index.php getReturnWorkArea()` (Refs #780) rebuilt the main frame after a
Test-Project switch. For the project-scoped features `keywordsAssign`,
`assignReqs`, `reqSpecMgmt`, `printReqSpec`, `searchReq`, `searchReqSpec` it
returned `lib/general/frmWorkArea.php?feature=X`, whose `aa_tfp` map then
rendered a **legacy two-pane Smarty layout** (`lib/testcases/listTestCases.php`,
`lib/requirements/reqSpecListTree.php` + `project_req_spec_mgmt.php`,
`lib/results/printDocOptions.php?type=reqspec`, `reqSearchForm.php`,
`reqSpecSearchForm.php`) — while the ASIDE menu for the same features already
pointed at modernized Dashio `.html` screens. The **modern** screens were never
reached after a project switch, because `getReturnWorkArea()` never consulted
the modern screen map (mirror of `getActions()` in `lib/functions/common.php`).

## Fix (Refs #1330)

- `index.php:94-124` — `getReturnWorkArea()` now maps the 6 features to their
  modern Dashio URIs:
  - `keywordsAssign` → `gui/templates/keywords/keywordsAssign.html`
  - `assignReqs` → `gui/templates/requirements/assignReqs.html`
  - `reqSpecMgmt` → `gui/templates/requirements/reqSpecMgmt.html`
  - `printReqSpec` → `gui/templates/requirements/printReqSpec.html`
  - `searchReq` → `gui/templates/requirements/searchReq.html`
  - `searchReqSpec` → `gui/templates/requirements/searchReqSpec.html`
  - `feature=<name>` is echoed into the returned URL so navbar
    `switchTestProject()` (navBar.tpl:105) round-trips the same work area on the
    **next** test-project switch.
  - `editTc` is intentionally **kept** on `lib/general/frmWorkArea.php?feature=editTc`
    whose right pane is the modernized `projectInfoView.html` (Refs #923). The
    fallback for unknown / missing / test-plan-guarded features stays the modern
    Dashboard (`gui/templates/mainpage/mainPage.html`).
- `index.php:163-175` — `initEnv()` generalizes the previously Dashboard-only
  context append (`strpos(..., 'gui/templates/mainpage/mainPage.html')`) to a
  regex covering every modern `gui/templates/[^?]*.html` landing, appending
  `tproject_id`/`tplan_id` so the screen and its BFF get unambiguous context
  (`tproject_id`/`tplan_id` are read via `URLSearchParams`, e.g.
  `keywordsAssign.html:127-129`).

## Legacy entry points also covered by the mapping

The following legacy deep links still lead to `frmWorkArea.php?feature=X`; a
project switch from those screens now also lands on the modern screen:

- `gui/templates/dashio/keywords/keywordsView.tpl:132` → `keywordsAssign`
- `gui/templates/dashio/requirements/reqViewVersionsViewer.tpl:19` → `reqSpecMgmt`
- `gui/templates/dashio/testcases/tcView_viewer.tpl:31` → `reqSpecMgmt`
- `gui/templates/dashio/testcases/include/tcViewViewer.inc.tpl:31` → `reqSpecMgmt`

## Verification

Browser-verified (headless Chrome, admin/admin, fresh DB with Alpha/Beta
projects): all 6 features land on their modern screens after a simulated and a
real (navbar dropdown) project switch; `editTc` still renders the
`projectInfoView.html` right pane; unknown/missing features fall back to the
modern Dashboard. Regression suite **1330.1–1330.13** in `tmp/TLU_Test_Cases.md`
(13/13 PASS). Modern landings produce **0** new Event-Viewer Error/Warning
entries; the two pre-existing legacy `E_WARNING`s from the `listTestCases.php`
tcTree path were filed separately as bug **#1334**.

## i18n

No new user-facing strings — no locale bundle changes.

## Files

- `index.php` — `getReturnWorkArea()` (feature → modern screen map) and
  `initEnv()` (context append generalized to all modern Dashio landings)