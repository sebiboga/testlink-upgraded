# Bug fix — Issue #1629: `tlReqMgrSystem::getInterfaceObject()` — the legacy Requirements screens died with a blank HTTP 500

## Symptom

Every **legacy** Requirements screen of a test project that has the *Requirement
Management System integration* enabled answered **HTTP 500 with a 0-byte body** — a
completely blank page — and wrote **nothing** to the Event Viewer.

| Route | Pre-fix | Post-fix |
|---|---|---|
| `lib/requirements/reqSpecEdit.php?doAction=create` | **`500`, 0 bytes**, blank page | `200`, **15 645 bytes**, full editor |
| `lib/requirements/reqSpecEdit.php?doAction=init` | **`500`, 0 bytes** | `200`, 28 bytes — see §"Two things this is NOT" |
| `lib/requirements/reqSpecSearch.php` | **`500`, 0 bytes** | advances to its own separate defect, #1735 |
| `lib/requirements/reqSpecViewRevision.php?…` | **`500`, 0 bytes** | `200` |

Entry point: `http://localhost:8082/lib/requirements/reqSpecEdit.php?doAction=create`
(the same URL the 1.9.20 Requirement Specification editor answered on).

## Environment and fixture

- TestLink 2.0.1, PHP **8.3.35** built-in server (`php -S`, docroot = repo root), MariaDB
  `testlink` on `127.0.0.1:3306`, login `admin`/`admin` through
  `POST /api/auth/login` (the BFF needs `Origin` + `Referer`, otherwise every later request
  returns `200` with a *login-redirect* body and silently invalidates every measurement).
- Pre-fix baseline commit `1ffd36bf6`; fix commits `44ee48bfe` (the fix) and
  `8a469862d` (code-review follow-up), branch `fix/issue-1629`.
- **Why the original report was filed unverified:** the CI database is freshly imported on
  every run and ships with **zero** test projects
  (`SELECT COUNT(*) FROM testprojects` → `0`), so no project was linked to a ReqMgr system
  and the code path could not be entered. The fixture is:

  ```sql
  -- the test project: create it through the BFF so the schema is right
  --   POST /api/projects/index.php {"name":"TLU1629","prefix":"P1629"}   -> id 1
  INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('TLU1629 Contour',1,'{}');
  INSERT INTO testproject_reqmgrsystem (testproject_id,reqmgrsystem_id) VALUES (1,<rid>);
  UPDATE testprojects SET reqmgr_integration_enabled=1 WHERE id=1;
  ```

  `type=1` is the **only** entry of `tlReqMgrSystem::$systems`
  (`lib/functions/tlReqMgrSystem.class.php:31`,
  `array( 1 => array('type' => 'contour', 'api' => 'soap', …))`), so **100 % of the rows
  the shipped UI can create** are affected.

- **Gotcha that cost the first reproduction:** the legacy screens read the test project from
  the **session**, not from the query string — `lib/requirements/reqSpecEdit.php:79`,
  `$args->tproject_id = $_SESSION['testprojectID']`. A `?tproject_id=1` query parameter is
  ignored; the request dies one line earlier at `testproject->get_by_id(0)` and the symptom
  is masked. Load `index.php?tproject_id=1` first.

## Measured evidence (before)

HTTP:

```
reqSpecEdit.php?doAction=create          http=500 bytes=0
reqSpecEdit.php?doAction=init            http=500 bytes=0
reqSpecSearch.php                         http=500 bytes=0
reqSpecViewRevision.php?rev_id=1          http=500 bytes=0
```

PHP level, from `tmp/php_server.log`:

```
PHP Fatal error:  Uncaught Error: Class "contoursoapInterface" not found
  in …/lib/functions/tlReqMgrSystem.class.php:657
Stack trace:
#0 …/lib/requirements/reqSpecCommands.class.php(44): tlReqMgrSystem->getInterfaceObject()
#1 …/lib/requirements/reqSpecEdit.php(23): reqSpecCommands->__construct()
#2 {main}
```

**The Event Viewer stays empty** — the process dies before `shutdownLogger()` runs. A 500
that leaves no trace in the log table is why this class of defect survives so long.

## Root cause

| hop | file:line | code |
|---|---|---|
| 1 | `lib/requirements/reqSpecEdit.php:23` | `$commandMgr = new reqSpecCommands($db,$args->tproject_id);` (siblings: `reqSpecSearch.php:34`, `reqSpecViewRevision.php:59`) |
| 2 | `lib/requirements/reqSpecCommands.class.php:39-41` | `if($info['reqmgr_integration_enabled'])` |
| 3 | `lib/requirements/reqSpecCommands.class.php:44` | `$rms = $sysmgr->getInterfaceObject($tproject_id);` — **`$rms` is never read**; line 45 immediately overwrites it with `getLinkedTo()`. The call is dead weight, so any failure inside it can only cost the caller. |
| 4 | `lib/functions/tlReqMgrSystem.class.php:648` | `$system = $this->getLinkedTo($tprojectID);` — inner JOIN, link found |
| 5 | `lib/functions/tlReqMgrSystem.class.php:655-656` | `$iname = $itd['implementation'];` — may name a class that does not exist, or be `NULL` |
| 6 | **`lib/functions/tlReqMgrSystem.class.php:657`** (pre-patch) | `$its = new $iname($itd['implementation'],$itd['cfg']);` → **the fatal** |
| 7 | `lib/functions/tlReqMgrSystem.class.php:661` (pre-patch) | `catch (Exception $e)` → **never entered**, an `Error` is not an `Exception` |
| 8 | PHP 8 runtime | aborts **before any byte is flushed** → `500`, 0 bytes |

Two flavours, both measured on that one line:

1. `type=1` → `getImplementationForType()` (`:111`) returns
   `contoursoapInterface`. That class is **not shipped in this repository and never was**
   (`git log --all -- '*contour*'` is empty; only the abstract
   `reqMgrSystemInterface` base exists in `lib/reqmgrsystemintegration/`) →
   `Error: Class "contoursoapInterface" not found`.
2. A `type` that is not a key of `$systems` → `getByAttr()` (`:346-348`) stores
   `implementation = NULL` → `Error: Class name must be a valid object or a string`.

### Why it breaks now

It is **not** a 2.0.1 regression — the unguarded `new` is original 1.9.20 code. What changed
is the runtime: 1.9.20 shipped on PHP 5.x, where a missing class was *also* fatal but the
surrounding `display_errors`/`watchPHPErrors()` settings usually produced a partially
rendered page. On PHP 8.3 an uncaught `Error` **inside a constructor** aborts the request
outright, and hop 3 happens before any output, so the user gets a blank page.

### Blast radius

- **1** definition and **1** call site of `tlReqMgrSystem::getInterfaceObject()` in the whole
  tree (the ~20 other hits of that *method name* belong to `tlIssueTracker` /
  `tlCodeTracker` — different classes, different defects).
- **3** reachable callers, all in `lib/requirements/`.
- **Not** affected: the modernized `api/reqspec/index.php:1319-1335` (calls `getLinkedTo()`
  only, never instantiates the interface), `api/reqmgrsystems/`,
  `api/reqmgrsystemedit/` and `lib/reqmgrsystems/reqMgrSystemView.php`.
- The report was filed as the "third unguarded `new`" left over after **#1625** hardened
  `checkConnection()` and the `getAll()` `checkEnv` block, and
  `lib/functions/common.php:140` already carried a comment naming it as the outstanding one.

## The fix

`lib/functions/tlReqMgrSystem.class.php`, `getInterfaceObject()`:

```php
$itd = $this->getByID($system['reqmgrsystem_id']);
$iname = isset($itd['implementation']) ? $itd['implementation'] : null;

if( is_null($iname) || !is_string($iname) || !@class_exists($iname) )
{
  return null;
}
$its = new $iname($iname,$itd['cfg']);
…
catch (Throwable $e)          // was: catch (Exception $e)
```

Four deliberate decisions:

1. **Degrade to `null`, not to an error object.** `null` is what this method *already*
   returns for "no system linked to this project", and what
   `tlIssueTracker::getInterfaceObject()` returns for the same case — so no caller has to
   learn a new state. It is provably safe here because the only caller
   (`reqSpecCommands.class.php:44`) **discards** the return value.
2. **`class_exists()` before the `new`, `@`-silenced.** `@` is kept for symmetry with the
   sibling guard at `:735`; after the **#1593** autoloader fix
   (`lib/functions/common.php:131-142` resolves the class file *before* `include_once`)
   the autoloader is already silent here, so the `@` is belt-and-braces rather than
   load-bearing. Without it, older builds would write the two "Failed opening …class.php"
   `E_WARNING` rows per request that #1593 tracks.
3. **`!is_string()` besides the null test** — a hand-edited `type`/`cfg` could store an
   array in `implementation`, and `new <array>` is its own `Error`.
4. **`catch (Throwable)` instead of `catch (Exception)`.** This is the actual point of the
   bug: an `Error` is not an `Exception`. It also covers a throwable raised by a *shipped*
   implementation class. No BFF path calls this method, so the handler's `echo()` (the
   original 1.9.20 behaviour, kept verbatim) cannot leak into a JSON response.

`$iname` is now also the first constructor argument — it was previously read a second time
from `$itd['implementation']`, so the name handed to `new` and the one validated by
`class_exists()` can no longer drift apart.

No i18n change (no user-facing string added or removed), no template change, no BFF change.

## Two things this fix is NOT

1. **`doAction=init` does not render the screen.** `init` is not a case label in
   `renderGui()`'s switch (`reqSpecEdit.php:135-232`), so the request ends in
   `default: echo 'Can not process RENDERING!!!'` (`:248-250`) — a 28-byte body. That is a
   pre-existing defect of the screen's own action handling, **#1736**, and it is why the
   verification below uses `doAction=create`, a real render path
   (`500`/0 bytes → `200`/15 645 bytes). A "body is non-empty" assertion with
   `doAction=init` would pass vacuously.
2. **`reqSpecSearch.php` still answers 500.** Removing the constructor fatal uncovers
   `count($itemSet)` on a variable only conditionally initialised
   (`reqSpecSearch.php:116`) → `Uncaught TypeError`. Filed as **#1735**, deliberately not
   fixed here.

## Verification

`bash tmp/verify_1629.sh` — self-cleaning, exits non-zero on any failure.

| run | result |
|---|---|
| post-fix | **37 PASS / 0 FAIL**, exit 0 |
| same harness, the fix reverted (`git show HEAD~2:…`) | **27 PASS / 10 FAIL**, exit 1 |

The 10 pre-fix failures are the two trigger flavours, the 0-byte body, the missing template
marker, 4 server-log fatals, the NULL-`cfg` `TypeError` path and the two static gates — so
the harness is **discriminating**, not vacuously green.

What it asserts:

- both fatal flavours → `200` + a real page (>`5000` bytes, template marker present, and
  explicitly **not** the `Can not process RENDERING!!!` stub), 0 new `events` rows, no new
  fatal in `tmp/php_server.log`
- the dangling-link and integration-off paths are **unchanged** (`getLinkedTo()` is an inner
  JOIN, so a dangling link returns `null` and the guard is never reached)
- **no over-guard**: a shipped stub class named exactly what
  `getImplementationForType()` computes for `type 1` is *really instantiated* (a direct
  `doDBConnect` + `getInterfaceObject()` probe returns the object)
- the widened handler for real: `reqmgrsystems.cfg` is a nullable `text` column, so with a
  `NULL` `cfg` and a stub declaring `string $cfg` the constructor raises a `TypeError` —
  fatal pre-fix, caught post-fix
- the #1625 siblings (`reqMgrSystemView.php`, `reqMgrSystemEdit.php?doAction=checkConnection`)
  and the modernized `api/reqmgrsystems` / `api/reqspec` BFFs unchanged
- static gates: `php -l` on the patched file and its three callers, `catch (Throwable $e)`
  present, `class_exists()` still `@`-silenced
- **the Event Viewer** (rule 12): 0 new Error/Warning rows attributable to the patched method

Cross-checked against the sibling harness that shares the same file, `tmp/verify_1625.sh`:
`25 PASS / 6 FAIL` **both** with and without the patch — identical, so no regression in the
area #1625 owns. Those 6 pre-existing failures are not this fix's:
`reqMgrSystemEdit.php` now legitimately **302s** into the modernized
`gui/templates/reqmgrsystems/reqMgrSystemEdit.html`, and `/lib/issuetrackers/issueTrackerView.php`
/ `/lib/codetrackers/codeTrackerView.php` answer **404** because those legacy renderers no
longer exist in 2.0.1.

### Test-suite gotcha worth knowing

The dev server runs **opcache with `revalidate_freq=2`**. After writing a PHP file you must
wait more than two seconds before probing, or you measure the *previous* compilation and can
wrongly conclude "already fixed". Both directions of this mistake were made during the run and
are recorded in the issue trail.

## Files changed

| file | purpose |
|---|---|
| `lib/functions/tlReqMgrSystem.class.php` | the guard + `catch (Throwable)` (+ the explanatory comment) |
| `CHANGELOG` | `### Key bugfix — Req. Management System interface instantiation …` under `## 2.0.1 (in-progress)` |
| `tmp/verify_1629.sh` | regression harness (gitignored — local test record) |
| `tmp/TLU_Test_Cases.md` | `## Regression — Issue #1629 …`, 10 numbered cases (gitignored) |

## Screenshots

| | |
|---|---|
| before — blank page (HTTP 500, 0 bytes) | `issue-1629-reqSpecEdit-before.png` |
| after — `reqSpecEdit.php?doAction=create` renders (HTTP 200, 15 645 bytes) | `issue-1629-reqSpecEdit-after.png` |
