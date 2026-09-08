# Bugfix — Issue #1248: Legacy resultsGeneral (resultsGeneral/displayMgr) E_WARNING undefined 'basehref'/'currentUser'

## Problem

Every HTML render of the legacy report
`lib/results/resultsGeneral.php?tplan_id=…&tproject_id=…` could log up to 3
PHP 8 `E_WARNING` rows into the `events` table (`activity=PHP`,
`log_level=2`):

```
E_WARNING Undefined array key "basehref"     - lib/results/resultsGeneral.php - Line 270
E_WARNING Undefined array key "basehref"      - lib/results/displayMgr.php      - Line 103
E_WARNING Undefined array key "currentUser"   - lib/results/displayMgr.php      - Line 102
```

plus the expected `Parameter 'format' is not defined — defaulting to
FORMAT_HTML` tlog warning on the anonymous apikey path.

**Reproduce at HEAD (measured):** with a fresh session (no cookies) call

```
curl ".../lib/results/resultsGeneral.php?apikey=INVALIDKEY…64hex…&tplan_id=2&tproject_id=1"
```

→ HTTP 200, report still renders, and the three E_WARNING rows above appear in
`events`. The GUI authenticated path and the valid-key anonymous path no longer
emit them (populated by `testlinkInitPage()`/`setPaths()`/`checkSessionValid()`
and by the Refs #1021 anonymous fix respectively); the reachable trigger at
HEAD is the anonymous path whose apikey matches no testproject/testplan entity.

## Root Cause

`setUpEnvForAnonymousAccess()` (lib/functions/common.php:1356-1380) builds
`$_SESSION['currentUser']` and `$_SESSION['basehref']` **only inside** the
`if (!is_null($item))` block that runs when the 64-char apikey resolves to a
real testproject/testplan. When it matches nothing, the function returns
`false` leaving both session keys unset — but the anonymous caller
(`displayMgr::initArgsForReports()` and `resultsGeneral::initializeGui()`)
ignores that return value and reads the keys unconditionally:

- `displayMgr.php:102` `$args->user = $_SESSION['currentUser'];`
- `displayMgr.php:103` `$args->basehref = $_SESSION['basehref'];`
- `resultsGeneral.php:270` `$gui->basehref = $_SESSION['basehref'];`

On PHP 8.x each bare read of a missing key raises `E_WARNING`, and
`watchPHPErrors()` (lib/functions/logger.class.php:1407) persists it into the
`events` table.

## Fix

Committed on branch `fix/issue-1248`, commit `9f3d6fa9e` (fix):

Defensive `isset()` reads with fallbacks at exactly the three flagged sites —
the repo's own established pattern (see `asideMenu.php:49`,
`tlsmarty.inc.php:93`, `archiveData.php:105`, `projectView.php:52`):

```php
// displayMgr.php:102-103 (initArgsForReports)
$args->user = isset($_SESSION['currentUser']) ? $_SESSION['currentUser'] : null;
$args->basehref = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : TL_BASE_HREF;

// resultsGeneral.php:270 (initializeGui)
$gui->basehref = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : TL_BASE_HREF;
```

**Why this approach:** it is the smallest change that guarantees the three
reported read sites can never emit an "undefined array key" E_WARNING
regardless of how a session reached them (missing entity anonymous, future
session-state changes, other reports routed through the shared
`initArgsForReports()`). Behavior for authenticated and valid-key anonymous
sessions is byte-identical (keys are always present → same values). The
`TL_BASE_HREF` fallback (config.inc.php:2172) is the very URL
`setPaths()` would compute, so the send-mail/spreadsheet action URLs built from
`$gui->basehref` stay valid. Rejected alternatives: fixing only
`setUpEnvForAnonymousAccess()` (leaves the GUI/other sessions unprotected and
changes where the keys get written instead of protecting the reads), and
blocking the report when the apikey resolves to nothing (a behavior change
beyond the scope of a log-noise bug).

## Files Changed

- `lib/results/displayMgr.php` — line 102 (`currentUser` isset-guard) and 103
  (`basehref` isset-guard, `TL_BASE_HREF` fallback)
- `lib/results/resultsGeneral.php` — line 270 (`basehref` isset-guard,
  `TL_BASE_HREF` fallback)

## Verification

All checks on branch `fix/issue-1248`, PHP 8.3.33, MySQL `testlink` 2.0.0
schema, fixtures project#1/plan#2 (testplan api_key `b4b259b…2efb11`):

- Invalid-apikey anonymous render (pre-fix: 3 E_WARNINGs) → HTTP 200, page
  renders, **0** `activity='PHP' log_level=2` rows.
- Valid-apikey anonymous render → HTTP 200, renders, only the `format` tlog
  warning (unchanged).
- GUI authenticated render (admin session) → page renders, **0** E_WARNING
  rows.
- `php -l` clean on both files.
- Shared-helper regression: `resultsByStatus.php` anonymous path behavior
  unchanged (it is not anonymous-reachable — redirects to login as before);
  no new PHP-warning rows.
- Event Viewer: `SELECT COUNT(*) FROM events WHERE activity='PHP' AND
  log_level=2` → **0** after the full suite (only log_level=16 audit rows and
  the benign `format` tlog rows).

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1248", 5/5 PASS.

Screenshot: `docs/screenshots/issue-1248-resultsGeneral-rendered.png` (report
renders cleanly post-fix).