# Bugfix — Issue #1513: reqedit create-new-version — 2x E_WARNING `Undefined property stdClass::$freezeLinkOnNewReqVersion` in `requirement_mgr::copy_version`

## Problem

Every `requirement_mgr::copy_version()` invocation (any `create_new_version()`
path — legacy req edit controllers, the modern `api/reqimport` BFF
`actionOnHit=create_new_version`, bulk `createFromXML/createFromMap`) logs a PHP 8
**`E_WARNING "Undefined property: stdClass::$freezeLinkOnNewReqVersion"`** into the
`events` table (Event Viewer, `log_level=2`):

```
E_WARNING
Undefined property: stdClass::$freezeLinkOnNewReqVersion - in .../lib/functions/requirement_mgr.class.php - Line 2477
```

second, previously **masked** warning (`requirement_mgr::$debugMsg`, line 2498)
fires as soon as the freeze option is honoured.

Reproduced 2026-09-15 on PHP 8 / MariaDB (fresh fixture: project 1000/PROJ
option_reqs=1, req_spec 1001, requirement 1002/REQ-001 + versions) via a CLI
harness booting the app like a BFF and calling
`requirement_mgr::create_new_version(1002)` — `events` row id=4 as above.

The modern `reqEdit.html` **Create New Version** button itself goes through
`api/reqedit/index.php POST ?action=version` → `requirement_mgr::create_version()`
(line 2281, bare INSERT, does NOT call `copy_version()`) — verified browser-click
creates a version with **no** warning. The bug surface is the `copy_version()`
code path itself.

## Root Cause

Chain (each hop backed by `file:line`):

1. `config.inc.php:1415` defines the config option as
   `$tlCfg->reqTCLinks->freezeLinkOnNewREQVersion = TRUE;` (`REQ` **uppercase**).
2. `lib/functions/requirement_mgr.class.php:2477-2478` in `copy_version()` read
   `$reqTCLinksCfg->freezeLinkOnNewReqVersion` — wrong casing — from the
   `stdClass` created at `config.inc.php:66`. PHP 8 property access on an
   undefined `stdClass` member → `E_WARNING`.
3. `lib/functions/logger.class.php:1483` registers
   `set_error_handler("watchPHPErrors")`; `watchPHPErrors` → `logWarningEvent`
   (line 1445) writes the `E_WARNING` row into `events`.
4. `$freezeLinkOnNewReqVersion` evaluated to `null`, so line 2487
   `if($freezeLinkOnNewReqVersion)` never ran → `updateTCVLinkStatus(
   ...,LINK_TC_REQ_CLOSED_BY_NEW_REQVERSION)` (link-freeze on new REQ version)
   was **silently disabled** on PHP 5 (null) and additionally warned on PHP 8.
5. Removing the mask exposed a second latent `$this->debugMsg` typo in
   `closeOpenTCVersionOnOpenLinks()` (line 2498, unchanged since legacy commit
   `cb365cd5e`, 1.9.18): `{$this->debugMsg}` on an unset property;
   `$freezeLinkedTCases` null-guard (`freezeLinkOnNewReqVersion & freezeBothEndsOnNewREQVersion`)
   had made that branch unreachable for ~8 releases.

## Fix

`lib/functions/requirement_mgr.class.php` (minimal, 2 methods, no signature change):

1. `copy_version()` lines 2477, 2478, 2487 — read and use the real config key:
   `$freezeLinkOnNewREQVersion = $reqTCLinksCfg->freezeLinkOnNewREQVersion;`
2. `closeOpenTCVersionOnOpenLinks()` line 2498 — replace `{$this->debugMsg}` with
   the class-standard local `$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' .
   __FUNCTION__;` keeping the `/* ... */` SQL-comment wrapper in the UPDATE
   (dropping the wrapper yields SQL `1064`, caught during verification).

Rationale for fixing both warnings in one issue: the issue's *Expected* state is
"**No Error/Warning event entries**" for the new-version flow. The second warning
only exists on the exact freeze path the first fix re-activates, so leaving it
would fail the acceptance criterion on the same user action.

Rejected alternatives: leaving the masked property null (keeps the bug), and
patch-only the variable name without the `closeOpenTCVersionOnOpenLinks` fix
(regression matrix items 3 must stay error-free).

## Verification

Regression suite in `tmp/TLU_Test_Cases.md` — **8/8 PASS**. Key measured results:

- Two consecutive CLI `create_new_version()` runs → `events` table gains **0** rows.
- With an OPEN `req_coverage` link on the source version, a new version flips the
  link `link_status 1 → 4 (LINK_TC_REQ_CLOSED_BY_NEW_REQVERSION)`, `is_active → 0`
  — the config option is finally honoured (was silently null).
- Browser `reqEdit.html?id=1002&tproject_id=1000` → Create New Version → version
  bump OK, `events` 0 rows, browser console clean (only the pre-existing
  "label not associated" a11y notice).
- `php -l lib/functions/requirement_mgr.class.php` clean; repo grep
  `freezeLinkOnNewReqVersion` → 0 hits.

Screenshot: `docs/screenshots/issue-1513-reqedit-create-new-version-fixed.png`
(modern Requirement Editor after fix).

## Files changed

- `lib/functions/requirement_mgr.class.php` — 5 lines changed (3 + 1 + 1).
- `tmp/TLU_Test_Cases.md` — regression suite appended.
- `docs/Bugfix-Issue-1513-RequirementCopyVersion-FreezeLink-EWarning.md` — this
  mirror, plus `docs/screenshots/issue-1513-reqedit-create-new-version-fixed.png`.
- `CHANGELOG` — one line under key bugfix.