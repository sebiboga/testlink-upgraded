# Test Case Bulk Update — Modernized Screen

Modernization of the legacy bulk-update screen (`lib/testcases/tcBulkOp.php`,
`gui/templates/dashio/testcases/tcBulkOp.tpl`) — GitHub issue
[#1074](https://github.com/sebiboga/testlink-upgraded/issues/1074).

The legacy Smarty screen (reached from the TC Viewer's "Bulk update" link) is
replaced by a standalone Dashio page
(`gui/templates/testcases/tcBulkOp.html`) backed by a plain-PHP REST BFF API
(`api/tcbulkop/index.php`). For a single test case, the screen applies a chosen
Status / Importance / Execution type to **ALL versions** of that test case,
optionally forcing frozen (closed) versions, exactly like the legacy
`testcase::setIntAttrForAllVersions($id,$attr,$value,$forceFrozen)` contract.

**Path:** Test Specification → Test Case Viewer → toolbar **Bulk Update** (shown
when the session holds `mgt_modify_tc` on the owning test project).
**URL:** `gui/templates/testcases/tcBulkOp.html?tcase_id=<id>&tproject_id=<id>`
**BFF API:** `GET /api/tcbulkop/?action=init` + `POST /api/tcbulkop/?action=apply`
**Rights:** read open to any authenticated session; **write gated on
`mgt_modify_tc`** on the owning test project (403 verified for a `<no rights>`
user)
**Tracking issue:** [#1074](https://github.com/sebiboga/testlink-upgraded/issues/1074)

---

## Screen layout

| Element | Description |
|---------|-------------|
| **Heading** | Teal banner "Bulk Update Test Case" + context label `- <external_id> <name>`, locale switcher |
| **Context bar** | Test project name |
| **Context box** | Test case name, external id, version count with active/frozen counts (`2 (1 active) (1 frozen)`), version chips each tagged `open` / `frozen`, and the scope hint "applied to ALL versions" |
| **Status select** | Server-localized status domain (`draft`..`final`, 1-7) + empty "(no change)" sentinel |
| **Importance select** | `High`/`Medium`/`Low` (code values 3/2/1) + "(no change)" sentinel |
| **Execution type select** | `Manual`/`Automated` (1/2, already localized by `get_execution_types()`) + "(no change)" sentinel |
| **Force frozen versions** | Checkbox + hint; when unchecked, frozen (closed) versions keep their values |
| **Actions** | Apply (teal, disabled without `mgt_modify_tc`) + Cancel (dark → history back / close); Apply shows a confirm dialog summarizing the chosen changes |
| **Feedback** | Success/error toast; loading overlay during init/apply; fatal warnbox for 401 (Not authenticated) and 403 (no modify right) |

## Data flow and legacy parity

| Legacy behavior | Modernized behavior |
|-----------------|---------------------|
| Smarty form posts `status`, `importance`, `execution_type`, `forceFrozenTestcasesVersions`, `tcase_id` to `tcBulkOp.php` | Popup calls BFF `GET ?action=init`, then `POST ?action=apply` (JSON, `X-Requested-With: XMLHttpRequest`) |
| Value `-1`/`0` sentinel = "no change" per attribute | Form maps empty select → `-1`; the BFF applies only attrs with value > 0 |
| `setIntAttrForAllVersions($tcase_id,$attr,$value,$forceFrozen)` updates `tcversions` children of the test-case node (only `is_open=1` unless the force-frozen flag is set) | BFF calls the exact same legacy method with admin-selected values and the force-frozen flag |
| Domain maps read from configuration (status `draft`..`final`, importance `code_label` `high`/`medium`/`low`, execution type from `get_execution_types()`) | BFF builds the same maps; labels served localized (status/importance via `getConfigAndLabels` + `lang_get`, execution type already localized) |
| No explicit right check in `tcBulkOp.php` (viewer link only shown with edit rights) | BFF gates the **write** on `mgt_modify_tc` on the owning project (403); read open to authenticated sessions — parity with the viewer toolbar gating |
| Screen refreshes after apply | Apply reloads context (counts + versions) and clears the selects |

## BFF API reference

### GET `?action=init`

**Parameters:** `tcase_id` (required), `tproject_id` (optional hint).

**Response (200):**
```json
{
  "status": "ok",
  "context": {
    "tcase_id": 3200, "tproject_id": 1000, "tproject_name": "ModernizeTest",
    "external_id": "MZT-1", "name": "BulkTC",
    "version_count": 2, "active_count": 1, "frozen_count": 1,
    "versions": [ {"tcversion_id": 3210, "version": 1, "is_open": true},
                  {"tcversion_id": 3211, "version": 2, "is_open": false} ]
  },
  "domains": {
    "status": { "1": "Draft", "...": "...", "7": "Final" },
    "importance": { "3": "High", "2": "Medium", "1": "Low" },
    "execution_type": { "1": "Manual", "2": "Automated" }
  },
  "grants": { "can_modify": true }
}
```

**Errors:** missing `tcase_id` → 400 `Missing test case id`; unknown test case →
404 `Test case not found`; unauthenticated → 401 `Not authenticated`.

### POST `?action=apply`

**Body (JSON):** `tcase_id`, `tproject_id`, `status`, `importance`,
`execution_type` (each `> 0` = set, `<= 0` / `-1` = no change),
`forceFrozenTestcasesVersions` (0/1).

**Response (200):**
```json
{ "status": "ok", "message": "Bulk operation applied",
  "tcase_id": 3200, "tproject_id": 1000,
  "applied": { "status": 7, "importance": 3, "execution_type": 2 },
  "force_frozen": true }
```

**Errors:** unauthenticated → 401; no `mgt_modify_tc` on the owning project →
403 `No permission`; GET on apply → 405 `Method not allowed`; unknown action →
404 `Unknown action`; unknown test case → 404.

## Permission path

The BFF resolves the owning test project by walking the test-case node up
`nodes_hierarchy` (falling back to the supplied `tproject_id` hint). `init` is
open to any authenticated session so the context is visible; `apply` requires
`mgt_modify_tc` on the owning project — verified 403 with a `<no rights>` user,
and the modern screen hides the Apply action / disables it accordingly. The
`mgt_modify_tc` gate is evaluated via `hasRight()` whose legacy `'yes'` string
return is normalized to a real boolean before JSON encoding.

## i18n

All labels use client-side `TLi18n` keys under the `tcbk.*` namespace (28 keys:
title, header, context labels, sentinel, checkbox + hint, apply/cancel, footer,
toasts, validation + permission messages, confirm template, version count
formats) plus `tcview.bulkUpdate` for the TC Viewer toolbar button — added to
all 10 locale bundles (`en`, `de`, `es`, `fr`, `it`, `ja`, `pt`, `ro`, `ru`,
`zh`). Domain option labels (Draft/Final, High/Low, Manual/Automated) are served
server-side at the session locale; the "(no change)" sentinel is client-localized.

## Files

| File | Purpose |
|------|---------|
| `gui/templates/testcases/tcBulkOp.html` | Standalone HTML+JS+CSS popup screen |
| `api/tcbulkop/index.php` | BFF API — `init` + `apply` actions |
| `gui/templates/testcases/tcView.html` | Toolbar **Bulk Update** button + `openTcBulk()` (gated on `mgt_modify_tc`) |
| `gui/templates/i18n/*.json` | i18n locale bundles (`tcbk.*`, `tcview.bulkUpdate`) |
| `lib/testcases/tcBulkOp.php` | Legacy controller (never called; kept for reference) |

Regression suite **TC-1074** (15/15 PASS) recorded in `tmp/TLU_Test_Cases.md`.

---

_TestLink 2.0.1 · Test Case Bulk Update · Refs #1074_