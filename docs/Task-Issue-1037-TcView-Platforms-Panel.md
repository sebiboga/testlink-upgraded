# Task 1037 — Platforms panel in the Test Case Viewer (gap vs legacy)

**Issue:** [#1037](https://github.com/sebiboga/testlink-upgraded/issues/1037)
**Status:** IMPLEMENTED (2026-09-30)
**Screen:** ASIDE → Test Specification → *Test Case Viewer*
(`gui/templates/testcases/tcView.html?tcase_id=<id>[&tcversion_id=<id>]`)
**BFF:** `api/testcases/index.php`

## The gap

1.9.20 renders the platforms of every test case version through
`gui/templates/dashio/testcases/include/platforms.inc.tpl`, included once per
version by `tcView_viewer.tpl:496-511` (`tcView.tpl:164` for the current version,
`tcView.tpl:296` for the others). That include has **three** parts:

1. the `Platforms:` label is a **link to Platform Management**
   (`$gsmarty_href_platformsView` → `lib/platforms/platformsView.php?tproject_id=%s%`,
   `lib/functions/tlsmarty.inc.php:291`);
2. a per-platform **unassign** icon that opens the `remove_plat_msgbox`
   confirmation (`%i` = platform name) and then GETs
   `tcEdit.php?doAction=removePlatform&tcase_id=&tcplat_link_id=`;
3. the `free_platforms[]` multi-select plus an **Add** button
   (`doAction.value='addPlatform'`).

The modern viewer rendered **only the assigned names**, as read-only chips: no
management link, no unassign, no assign — the `add_platform` / `remove_platform`
BFF actions existed (added by #915) but nothing called them, and the `view`
action never returned the free-platform list the select needs.

Note: the issue body, written when the gap was first observed, also claimed the
`view` payload exposed no platforms at all. That had been fixed in the meantime by
commit `27eade417` (Refs #915); the remaining gap is the three legs above.

## What was implemented

**BFF — `api/testcases/index.php`, `action=view`**

* `projectPlatforms()` is resolved once into `$projectPlatformsMap` before the
  version loop and reused for the per-version free list and the top-level
  `platformsProject` key (previously a second identical query).
* Every version now carries **`platformsFree`** — the project's platforms with
  `enable_on_design = 1` minus the ones already linked to that
  `(testcase_id, tcversion_id)`, i.e. `testcase::getFreePlatforms()`
  (`lib/functions/testcase.class.php:9813`).
* **`grants.platform_management` + `grants.platform_view`** added — legacy
  `checkRights()` accepts either right, and `platforms.inc.tpl` rendered the link
  unconditionally.
* **`platformsMgmtUrl`** added — `/gui/templates/platforms/platformsView.html?tproject_id=N`.

**Screen — `gui/templates/testcases/tcView.html`**

* `platformMgmtLabelHtml()` — the label becomes a link (with
  `fa-external-link`) when `grants.platform_management` or `grants.platform_view` is set.
* `confirmRemovePlatform()` / `doRemovePlatform()` — per-platform ✕ button and a
  Dashio confirm modal carrying the legacy wording, then
  `POST ?action=remove_platform`.
* `openPlatformAdd()` / `doAddPlatforms()` — **Add** button and a Dashio modal
  with the free platforms, then `POST ?action=add_platform`.
* `platRefreshMain()` re-reads the view payload after a write (same pattern as
  the requirements modal's `arqRefreshMain()`).

Gating follows the legacy `platRW` rule (`tcView_viewer.tpl:476-505`, mirrored by
the existing BFF helper `canAssignPlatforms()`): `mgt_modify_tc`, version not
frozen, and — for executed versions — `testproject_edit_executed_testcases` or the
`canEditExecuted` config. Two deliberate deviations from 1.9.20:

* this rule is **more permissive** than legacy, which set `$platRW = 1` only when
  the version was executed *and* `can_edit_executed == 1` (so 1.9.20 showed the
  ✕/Add only on executed versions). The BFF rule is the intended behaviour and is
  the one the platform-assignment actions themselves enforce;
* the free list is computed **per version**, whereas legacy fed the *current*
  version's `currentVersionFreePlatforms` into every version's panel, and hid the
  control only when that list was `null` (in PHP `null != []` is `true`, so an
  empty list still rendered an empty select). Here the Add button disappears once
  the version's own free list is empty.

**i18n** — 13 new `tcview.platform*` keys in **all 10** locale bundles
(`en, de, es, fr, it, ja, pt, ro, ru, zh`). The texts are ported from the legacy
locale files (`locale/<L>/strings.txt`:
`$TLS_remove_plat_msgbox_title`, `$TLS_remove_plat_msgbox_msg`,
`$TLS_img_title_remove_platform`, `$TLS_select_platforms_header`).

## Defect found and fixed while testing

The first `platApiPost()` posted to `/api/testcases/index.php` **without**
`?action=`. The BFF is query-routed, not path-routed, so the request fell through
the POST router to the catch-all `api/testcases/index.php:2874` and answered
`400 {"status":"error","message":"Bad request"}` — assign always failed. Fixed by
passing `?action=<name>` as every other modern screen does
(`gui/templates/testcases/tcEdit.html:921`). The same commit stopped embedding the
platform name in the inline `onclick` (it is now resolved from the payload by id).

## Code-review remediation round

A review pass over the first implementation found one MAJOR and several minor
issues; all of them are fixed and covered by cases 14-24 of the suite.

| # | Finding | Fix |
|---|---|---|
| MAJOR | `platRefreshMain()` re-fetched the payload **without `tcversion_id`**, so after an assign/unassign on a non-latest version `render()` fell back to `versions[0]` and the whole toolbar (Edit Version / Print / Export / Add to Test Plan) silently retargeted to the LATEST version | `platRefreshMain()` now carries `data.requestedTcversionId`; `render()` compares the version ids through `parseInt` so a string id can never break the match |
| MINOR | Management link gated on `platform_management` only | legacy `checkRights()` (`lib/platforms/platformsView.php:49`) accepts `platform_management` **or** `platform_view`; the BFF exposes both and the label OR-s them |
| MINOR | Unassign removed by `platform_id` with a `tcplat_link_id` fallback, and the name was resolved by platform id | legacy removes by **LINK** id (`testcaseCommands::removePlatform` → `testcase::deletePlatformsByLink`); the ✕ now sends `(tcversion_id, tcplat_link_id)` only, and the confirmation name is looked up by link id |
| MINOR | `String.replace('%i', name)` treated `$&`-like sequences inside a platform name as substitution patterns | replaced with a replacement **function** |
| MINOR | Server-side error messages were discarded, so a failed write always showed a generic toast | both `doAddPlatforms()` and `doRemovePlatform()` surface `d.message`; the modal now closes **only on success** and stays open for a retry |
| MINOR | `remove_platform` accepted a link id belonging to another test case / version and answered `ok` after a silent no-op (which also raised an E_WARNING in `deletePlatformsByLink`) | the BFF verifies link ownership first and answers `404` with an explanatory message |
| MINOR | `platformMgmtLabelHtml()` / `findPlatformVersion()` dereferenced `data.grants` / `data.versions` unguarded; the link lacked `rel="noopener"`; the ✕/Add buttons had no `type="button"` | null-guards added, `rel="noopener"` added, `type="button"` added |
| MINOR | The remove-modal title was hardcoded English | rendered through `data-i18n="tcview.platformRemoveTitle"` |
| EXTRA | **Pre-existing BFF bug:** `has_been_executed` (and therefore the executed branch of `canAssignPlatforms`) was permanently `false`, because `testcase::get_by_id(..., access_key => 'tcversion_id')` returns a 0-indexed array whose rows carry no `tcversion_id` — the query ran `tcversion_id IN (0,1)` | ids are now read from the rows themselves (same shape as the sibling view at the bottom of the file) |

## Verification

Suite **#1037** in `tmp/TLU_Test_Cases.md` — **24 PASS / 0 FAIL**.

Cases 1-15 cover the feature itself: management link, per-version chips,
free-list correctness (`enable_on_design = 0` never offered, already-linked never
offered), assign and unassign with DB-row checks, the frozen-version gate, German
wording, all 10 bundles valid.

Cases 14-24 cover the remediation round: the version-retarget regression (with the
measured pre-fix value `currentVersionTcversionId=900005` vs the fixed `900004`),
the confirmation name resolved through `tcplat_link_id`, a stale link surfacing the
server message with the modal still open, cross-version link rejection (404),
write refusal for a read-only user (403), the read-only view hiding ✕/Add/link, the
`has_been_executed` fix, the executed-without-exec-edit-right gate (403), the frozen
gate, and the Event Viewer / PHP log / console check (no new Error/Warning).

Screenshots: `issue-1037-tcview-platforms-before.png`,
`issue-1037-tcview-platforms-after.png`,
`issue-1037-tcview-platforms-remove-confirm.png`.


## Files

| File | Role |
|---|---|
| `api/testcases/index.php` | `view` payload: `platformsFree`, `platformsProject` (resolved once), `grants.platform_management`, `grants.platform_view`, `platformsMgmtUrl` |
| `gui/templates/testcases/tcView.html` | platforms panel + management link, unassign confirm modal, add modal, `platApiPost`, `platRefreshMain` |
| `gui/templates/i18n/*.json` | 13 `tcview.platform*` keys × 10 bundles |
| `tmp/add_i18n_1037.py` | one-shot script that inserted the keys (re-runnable, idempotent) |
| `tmp/fixtures_1037.sql` | idempotent fixture: project + suite + test case + 2 versions + 3 platforms + 2 links + 1 execution + a read-only user (role 3, only `mgt_view_tc`) |
| `tmp/TLU_Test_Cases.md` | suite #1037 |
