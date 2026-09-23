# Issue 1315 — tcEdit: "Create New Version" clones the EDITED version and freezes the source

`api/testcasesedit/index.php action=create_version` now mirrors the legacy
`lib/testcases/tcEdit.php createNewVersion()` / `do_create_new_version`.

## Symptom (before the fix)

In the modern editor (`gui/templates/testcases/tcEdit.html`), clicking
**Create New Version** while editing **v1** of a test case that also had a **v2**
(only v2 owned a keyword) produced:

- a new version cloned from the **latest** v2 (keyword copied), not from the edited v1;
- the edited **source version was never frozen** (`is_open` stayed 1).

Legacy behaviour (1.9.20): the new version is cloned from the **version being
edited** and, when `testcase_cfg.freezeTCVersionOnNewTCVersion` is TRUE
(default, `config.inc.php:1352`), the source version is closed
(`setIsOpen(source, false)`).

## Root cause

| | legacy | modern (before) |
|---|---|---|
| source of the clone | `createNewVersion()` → `create_new_version($tcase_id, $user_id, $sourceTCVID)` with `$sourceTCVID = $args->tcversion_id` (the edited version) — `lib/testcases/tcEdit.php:277-278,838-843` | `api/testcasesedit/index.php:577` called `create_new_version($tcaseId, $userId)` **without** the submitted `$tcverId` → `testcase::create_new_version()` falls back to `$from = $last_version_info['id']` (testcase.class.php:2445-2448) |
| freeze of the source | `tcEdit.php:850-852` `$isOpen = !$tcCfg->freezeTCVersionOnNewTCVersion; setIsOpen($tcase_id, $sourceTCVID, $isOpen)` | none |

The front-end always POSTs the version being edited:
`tcEdit.html doNewVersion():512-517` sends `{action:'create_version', tcase_id,
tcversion_id: ctx.tcversionId, tproject_id}`.

## Fix

`api/testcasesedit/index.php`, `case 'create_version'`:

1. Pass the edited version as clone source:
   `$tcaseMgr->create_new_version($tcaseId, intval($user->dbID ?? $userId), $tcverId)`.
   `$tcverId` is resolved by `resolveContext()` from the `tcversion_id` POST
   param; when absent it defaults to the latest active version — the same
   behaviour as the legacy separate `do_create_new_version_from_latest` path
   (`tcEdit.php:280-282` passes `getLatestVersionID()`).
2. Legacy freeze mirror after a successful creation:
   ```php
   $tcCfg = config_get('testcase_cfg');
   $freezeSrc = intval($tcCfg->freezeTCVersionOnNewTCVersion ?? 0) > 0;
   $tcaseMgr->setIsOpen($tcaseId, $tcverId, $freezeSrc ? 0 : 1);
   ```
   unchanged default (TRUE) → source frozen; runtime-configurable exactly like
   the legacy controller.

No front-end and no i18n changes were required (no new user-facing strings).

## Verification

Fixture `tmp/fixtures_1315.php` creates project `TCEDDemo` with test case `tcA`
v1 (no keywords) + v2 (keyword `smoke`).

- Before fix: creating a version from v1 produced v3 = "V2 SUMMARY" (cloned from
  v2) with the `smoke` keyword copied and v1 `is_open` stayed 1.
- After fix (default config): v3 summary "V1 SUMMARY", no keyword copied, v1
  `is_open` becomes 0.
- With `freezeTCVersionOnNewTCVersion=FALSE`: clone source still correct
  ("V1 SUMMARY") but the source stays open.
- No `tcversion_id` fallback path clones the latest active version and freezes it
  (legacy `do_create_new_version_from_latest`).

Rights check unchanged: `mgt_modify_tc` on the owning project is enforced before
version creation (`403` otherwise).

## Test cases

See **Suite 1315** in `tmp/TLU_Test_Cases.md` — 6/6 PASS.

## CHANGELOG

- Test Case Editor (`tcEdit.html` / `api/testcasesedit`): Create New Version now
  clones the **edited** version (not the latest) and freezes the source
  version when `testcase_cfg.freezeTCVersionOnNewTCVersion` is on — legacy
  parity `Refs #1315`.