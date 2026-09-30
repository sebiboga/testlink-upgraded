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
* **`grants.platform_management`** added — the right that gated the legacy
  Platform Management target.
* **`platformsMgmtUrl`** added — `/gui/templates/platforms/platformsView.html?tproject_id=N`.

**Screen — `gui/templates/testcases/tcView.html`**

* `platformMgmtLabelHtml()` — the label becomes a link (with
  `fa-external-link`) when `grants.platform_management` is set.
* `confirmRemovePlatform()` / `doRemovePlatform()` — per-platform ✕ button and a
  Dashio confirm modal carrying the legacy wording, then
  `POST ?action=remove_platform`.
* `openPlatformAdd()` / `doAddPlatforms()` — **Add** button and a Dashio modal
  with the free platforms, then `POST ?action=add_platform`.
* `platRefreshMain()` re-reads the view payload after a write (same pattern as
  the requirements modal's `arqRefreshMain()`).

Gating reproduces the legacy `platRW` exactly (`tcView_viewer.tpl:476-505`,
mirrored by the existing BFF helper `canAssignPlatforms()`): `mgt_modify_tc`,
version not frozen, and — for executed versions —
`testproject_edit_executed_testcases` or the `canEditExecuted` config. The Add
button additionally disappears once the free list is empty, matching
`{if $addEnabled && null != $gui->currentVersionFreePlatforms}`.

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

## Verification

Suite **#1037** in `tmp/TLU_Test_Cases.md` — **15 PASS / 0 FAIL**, covering:
management link, per-version chips, free-list correctness
(`enable_on_design = 0` never offered, already-linked never offered), assign with
DB-row check, unassign with DB-row check, the frozen-version `platRW=0` gate,
German wording, all 10 bundles valid, plus regressions for the browser console,
the `events` table (0 new Error/Warning rows) and `tmp/php_server.log`.

Screenshots: `issue-1037-tcview-platforms-before.png`,
`issue-1037-tcview-platforms-after.png`,
`issue-1037-tcview-platforms-remove-confirm.png`.


## Files

| File | Role |
|---|---|
| `api/testcases/index.php` | `view` payload: `platformsFree`, `platformsProject` (resolved once), `grants.platform_management`, `platformsMgmtUrl` |
| `gui/templates/testcases/tcView.html` | platforms panel + management link, unassign confirm modal, add modal, `platApiPost`, `platRefreshMain` |
| `gui/templates/i18n/*.json` | 13 `tcview.platform*` keys × 10 bundles |
| `tmp/add_i18n_1037.py` | one-shot script that inserted the keys (re-runnable, idempotent) |
| `tmp/fixtures_1037.sql` | fixture: project + suite + test case + 2 versions + 3 platforms + link |
| `tmp/TLU_Test_Cases.md` | suite #1037 |
