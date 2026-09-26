# Bug fix — Issue #1593: the autoloader included a class file that is not shipped, logging 2 `E_WARNING`s per load

## Symptom

The Event Viewer of every TestLink request that touched a class this fork does not ship
gained **two** PHP 8 `E_WARNING` rows:

```
E_WARNING include_once(contoursoapInterface.class.php): Failed to open stream: No such file or directory
              - in .../lib/functions/common.php - Line 122
E_WARNING include_once(): Failed opening 'contoursoapInterface.class.php' for inclusion
              (include_path='.:/usr/share/php:...') - in .../lib/functions/common.php - Line 122
```

`contoursoapInterface` is the `implementation` of `reqmgrsystems.type=1`
(`lib/functions/tlReqMgrSystem.class.php:33`, the **only** entry of
`tlReqMgrSystem::$systems`), so this is every Contour/Jira requirement-manager system.

The defect was never visible on the screen the original report used: by the time it was
filed, #1625 had already silenced the two `checkConnection()` call sites with
`@class_exists(...)`. The **live** path is the *unguarded* instantiation in
`tlReqMgrSystem::getInterfaceObject()`:

```
lib/requirements/reqSpecSearch.php:34          new reqSpecCommands($db, $args->tprojectID)
lib/requirements/reqSpecCommands.class.php:40  if ($info['reqmgr_integration_enabled'])
lib/requirements/reqSpecCommands.class.php:44    $sysmgr->getInterfaceObject($tproject_id)
lib/functions/tlReqMgrSystem.class.php:614     $iname = $itd['implementation'];   // contoursoapInterface
lib/functions/tlReqMgrSystem.class.php:617     $its = new $iname(...);             // unguarded -> autoloader
lib/functions/common.php:52                    spl_autoload_register('tlAutoload')
lib/functions/common.php:122                   include_once 'contoursoapInterface.class.php'  <-- 2 E_WARNINGs
```

## Root cause

`tlAutoload()` is the **only** class loader of the application, and it included the class
file **unconditionally**:

```php
// lib/functions/common.php, pre-fix
try {
    include_once $classFileName . '.class.php';
}
catch (Exception $e) {
}
```

Two independent facts make this a defect rather than a design choice:

1. **`include_once` on a missing file is not an exception.** It raises two `E_WARNING`s
   (`Failed to open stream`, then `Failed opening ... for inclusion`) and returns `false`.
   The `catch (Exception)` that BitNami added for the ThinkUp interop can therefore never
   observe the failure.
2. **TestLink logs every `E_WARNING`.** `set_error_handler("watchPHPErrors")`
   (`lib/functions/logger.class.php:1407`) writes each one into the `events` table, and
   `register_shutdown_function("shutdownLogger")` (`:1471`) commits the open logger
   transaction **even when PHP 8 aborts the request** — so the rows survived the HTTP 500
   of the same request and polluted the Event Viewer for exactly the requests that were
   already broken for another reason.

`contoursoapInterface.class.php` is genuinely absent: `find . -iname '*contour*'` returns
nothing and `git log --all -- '*contour*'` is empty — the Contour SOAP transport was never
ported into this fork. Only the abstract `reqMgrSystemInterface` base exists, in
`lib/reqmgrsystemintegration/`.

## The fix — resolve, then include

`lib/functions/common.php` (only file with a code change):

```php
$classFile = $classFileName . '.class.php';

$resolvedClassFile = stream_resolve_include_path($classFile);
if( $resolvedClassFile === false && is_file($classFile) ) {
  $resolvedClassFile = $classFile;
}
if( $resolvedClassFile === false || !is_file($resolvedClassFile) ) {
  return;   // not a real file: stay undefined, quietly
}

try {
    include_once $resolvedClassFile;
}
catch (Exception $e) {
}
```

`stream_resolve_include_path()` uses the **same `include_path` search order `include()`
itself uses**, so every class file that resolves today still resolves; the `is_file()`
fallback covers the current-working-directory step of the same order. When the target is
not a real file the loader returns without including, the class simply stays undefined,
and PHP raises its usual `Error: Class not found` **at the `new` / `class_exists()` site** —
which is where a caller decides how to degrade (`tlReqMgrSystem::checkConnection()` already
does, via the `@class_exists()` guard added by #1625).

The `Zend_*` (`:86`), `Smarty_Internal_Compile_*` (`:95`) and `*Plugin` (`:112`) early
returns above the change are untouched, as is the BitNami `catch (Exception)`.

### Alternatives considered and rejected

| Alternative | Why not |
|---|---|
| Ship a `contoursoapInterface.class.php` stub | The transport is not in this fork at all; a stub would advertise a feature that cannot work, and it would not fix any other missing class. |
| `@class_exists()` guards at each call site (the #1625 pattern) | Leaves the global defect in place; one edit per caller; re-opens with the next caller. Measured here: `class_exists()` *does* fire the autoloader, which is why #1625 needed the `@`. |
| `@include_once` | Would also swallow a real parse/fatal inside a file that *does* exist — hiding genuine breakage instead of just "the file is absent". |
| Guard the specific `getInterfaceObject()` call | Fixes one symptom, leaves the loader broken for the next missing class. |

## Verification

Fixtures: `php tmp/fixtures_1593.php` creates a test project with
`reqmgr_integration_enabled = 1` **linked** to a `reqmgrsystems` row of `type = 1`. The link
is essential — it is what makes the unguarded instantiation reachable, and it is what the
earlier report was missing (Suite 1625 filed the third unguarded `new` as #1629 with the
note "UNVERIFIED, no project linked").

Harnesses (both force-added; `tmp/` is gitignored at `.gitignore:47`):

- `bash tmp/verify_1593.sh` — 26 assertions, exits non-zero on failure
  - pre-fix baseline: **22 PASS / 4 FAIL, exit 1**
  - with the fix: **26 PASS / 0 FAIL, exit 0**
- `php tmp/verify_1593.php` — 19 loader-level assertions, exit 0

Measured results:

| Check | Pre-fix | Post-fix |
|---|---|---|
| `GET /lib/requirements/reqSpecSearch.php` → `events` rows | **2** | **0** |
| `GET /lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1` (the report's repro) | 0 | 0 (200) |
| `php tmp/verify_1593.php` → `events` rows (autoloads 2 absent class names) | **4** | **0** |
| 9-screen app-wide sweep → `events` rows each | 2 on one | **0** on all |
| `contoursoapInterface` warning text anywhere in `events` | yes | **no** |

Loader parity, so the fix changes nothing for classes that exist:

- flat `include_path` classes still autoload: `database`, `testproject`, `tlReqMgrSystem`
- **sub-directory** `include_path` entries still autoload:
  `mantisrestInterface`/`redminerestInterface` (`lib/issuetrackerintegration/`),
  `stashrestInterface`/`githubrestCodeTrackerInterface` (`lib/codetrackerintegration/`),
  `reqMgrSystemInterface` (`lib/reqmgrsystemintegration/`)
- negative control: `class_exists('exttable')` is `false` before *and* after (the file
  `exttable.class.php` declares `tlExtTable` and is pulled in by an explicit `require`)
- a missing class still yields `class_exists() === false` **and defines nothing**
- a code review over 120 class names (108 shipped + absent/edge names) measured **0**
  resolution changes and **0** new diagnostics
- `tlReqMgrSystem::checkConnection()` still degrades to `false` instead of fataling

Browser pass (chrome-devtools MCP): log in as `admin`/`admin`, **Requirements Design →
Search Requirements** renders ("Search Requirements in test project ContourDemo", document
title `ContourDemo - Search Requirements`), `/api/requirements/index.php/search-context?tproject_id=1`
answers **200**, **0** console errors, `events` **0** rows.

## Deliberately not fixed here

- **#1629** — the HTTP 500 of the very same request: `tlReqMgrSystem::getInterfaceObject()`
  (`:617`) is still an unguarded `new` whose `catch (Exception)` cannot catch a PHP 8
  `Error`. This fix removes the warning noise and must **not** mask that fatal; the
  regression suite asserts the status is still `500` so the masking would be caught.
- **#1635** — found while testing: `third_party/phpxmlrpc/lib/xmlrpc.inc:554` uses
  `$temp =& new xmlrpcval(...)`, a `ParseError` on PHP 8, so the Trac XML-RPC issue-tracker
  transport fatals identically before and after this change.

## Files changed

| File | Purpose |
|---|---|
| `lib/functions/common.php` | the fix: resolve-then-include in `tlAutoload()` |
| `lib/functions/tlReqMgrSystem.class.php` | comment only: the now-stale `common.php:122` cross-reference left by #1625 |
| `CHANGELOG` | 2.0.1 "Key bugfix" entry for #1593 |
| `docs/Bugfix-Issue-1593-Autoloader-Missing-Class-EWarning.md` | this page (wiki mirror, without the image lines) |
| `tmp/fixtures_1593.php` | fixture: contour system **linked** to a reqmgr-enabled project (force-added) |
| `tmp/verify_1593.php` | 19 loader-level assertions (force-added) |
| `tmp/verify_1593.sh` | 26-assertion end-to-end harness, discriminating (force-added) |
| `tmp/TLU_Test_Cases.md` | `Regression — Issue #1593` suite, 18 restated cases + gotchas |

## Commits

| Commit | Content |
|---|---|
| `e14f2b406` | the fix |
| `6de89f76f` | code-review corrections + regression suite and harnesses |
| (docs commit) | CHANGELOG + this page + wiki mirror |
