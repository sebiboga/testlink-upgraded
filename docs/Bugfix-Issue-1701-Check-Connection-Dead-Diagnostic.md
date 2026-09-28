# Bugfix — Issue #1701: `check-connection` recorded no cause, and the 502 log row named no source

**Branch:** `fix/issue-1701-issuetracker-check-connection-log`
**Files changed:** `lib/issuetrackerintegration/issueTrackerInterface.class.php`,
`api/issuetracker/index.php`
**Severity:** minor (diagnosability) — no data loss, no wrong verdict, but an
operator had **zero** usable information when an issue-tracker's connection
failed.

---

## Symptom

`GET /api/issuetracker/<id>/check-connection` answered `502` and wrote an
Event-Viewer row whose description was a bare PHP `Error` with **no source
prefix** — a single leading space and nothing else:

```
id: 2   log_level: 1 (ERROR)   source: GUI
description (58 bytes, exact):
 Object of class stdClass could not be converted to string
```

Every other row in `events` is attributable; this one named nothing. The
connection check itself still returned a usable verdict
(`connected:false` + `Connection check failed`), so the feature "worked" — which
is exactly why the defect survived: it was invisible on the screen and
undiagnosable in the log.

## Why this is worse than "cosmetic"

While chasing the missing prefix it turned out the *useful* log statement was
dead code. The Event Viewer was not logging a bad message — it was logging a
**wrong** message. The line that records host / database / user / ADODB error
code never executed at all. A failed connection check produced no information
about the failure whatsoever, which is precisely the moment an operator needs it.

---

## Root cause — two independent defects on one code path

### A. PHP simple string interpolation resolves one property level

`lib/issuetrackerintegration/issueTrackerInterface.class.php:222-223` (inside
`connect()`, the failure branch):

```php
$connection_args = "(interface: - Host:$this->cfg->dbhost - " .
                   "DBName: $this->cfg->dbname - User: $this->cfg->dbuser) ";
$msg = sprintf(lang_get('BTS_connect_to_database_fails'), $connection_args);
tLog($msg  . $result['dbms_msg'], 'ERROR');          // <- never reached
```

In a **non-curly** interpolated string PHP resolves exactly **one** property
level. `"$this->cfg->dbhost"` therefore interpolates `$this->cfg` and leaves
`->dbhost` as *literal text*. `$this->cfg` is a `stdClass` — `setCfg()` line 165
does `$this->cfg = json_decode(json_encode($this->cfg));` deliberately, so the
config can be stored in `$_SESSION` (a `SimpleXMLElement` cannot be serialized).
A `stdClass` has no `__toString`, so PHP raises
`Error: Object of class stdClass could not be converted to string`, which
aborts the statement *before* the `tLog()` on the next line. (The class is
`Error`, **not** `TypeError` — measured: `get_class($e) === "Error"` and
`$e instanceof TypeError === false`, because the offending value is `$this->cfg`,
whose type is unconstrained; `TypeError` is what the *message* reads like.)
A `catch (\Throwable)` catches it either way.

Isolated in nine lines, no TestLink code at all:

```php
class A { public $cfg; }
$a = new A(); $a->cfg = json_decode('{"dbhost":"h","dbname":"n","dbuser":"u"}');
$s = "X:$a->cfg->dbhost - " . "Y:$a->cfg->dbname - Z:$a->cfg->dbuser) ";
// Error: Object of class stdClass could not be converted to string
```

Two levels require curly braces: `"{$a->cfg->dbhost}"`.

The line only executes on the **failure** branch of `connect()`, so it is
invisible for every healthy tracker and fires only when a `db`-API tracker's
database is unreachable. It is not a regression from a recent commit — `$this->cfg`
has been a non-stringable object since the `json_decode` — it has simply been
unreachable-and-therefore-unnoticed all along.

### B. `__METHOD__` is the empty string at file top level

`api/issuetracker/index.php:217` (`GET /{id}/check-connection`) and `:260`
(`POST /test-connection`):

```php
} catch (\Throwable $e) {
    tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR');
```

`__METHOD__` is `__FUNCTION__ :: __CLASS__`. At the **top level of a request
script** there is no function and no class, so both halves are empty and it
expands to `""` in PHP 8. The row was therefore literally `" <message>"`.
Measured: `LENGTH = 58 = 1 (space) + 57`, and `HEX(LEFT(description,20))` =
`204F626A656374206F6620636C61737320737464` (40 hex chars = 20 bytes) — starting
with hex `20`, one ASCII space.

---

## The fix

### 1. Restore the diagnostic — curly-brace interpolation

```php
// Issue #1701: PHP's SIMPLE (non-curly) string interpolation resolves only
// ONE property level, so "$this->cfg->dbhost" interpolated $this->cfg (a
// stdClass since setCfg() :165 json_decode()s it) and left "->dbhost" as
// literal text -> "Error: Object of class stdClass could not be converted
// to string" (an Error, NOT a TypeError). The Error aborted THIS
// statement, so the
// genuinely useful tLog() on the next line never ran and the connection
// failure was recorded nowhere. Curly braces are mandatory for 2 levels.
$connection_args = "(interface: - Host:{$this->cfg->dbhost} - " .
                   "DBName: {$this->cfg->dbname} - User: {$this->cfg->dbuser}) ";
```

### 2. Make the 502 log attributable — explicit literal, not `__METHOD__`

```php
tLog('api/issuetracker/index.php::GET /{id}/check-connection :: ' . $e->getMessage(), 'ERROR');
tLog('api/issuetracker/index.php::POST /test-connection :: ' . $e->getMessage(), 'ERROR');
```

### Methods considered and rejected

| alternative | why rejected |
|---|---|
| `catch (\Throwable)` around the interpolation | keeps the operator blind — restores nothing |
| cast `$this->cfg` to a string | that *is* the operation that throws |
| `__FILE__` / `basename(__FILE__)` in the log prefix | machine-accurate but unstable across the `api/<area>/index.php` layout, and says nothing about *which route* failed |
| wrap the handler bodies in closures so `__METHOD__` becomes non-empty | a large, risky restructuring of a 679-line router for a log string |
| `debug_backtrace()` | expensive, and still unstable |
| suppress the 502 / treat a dead host as `200` in the API | a dead host is not an internal error — but the `502` here was a *symptom* of the swallowed exception, so once the exception is gone the check naturally returns the ordinary `200 {connected:false}` verdict, which is the correct outcome and required no API change |

---

## Before / after

| | before | after |
|---|---|---|
| `GET /{id}/check-connection`, dead host | `502` + `{"status":"error",…,"message":"Connection check failed"}` | `200` + `{"status":"ok","connected":false,"message":"Connection failed (check type and configuration)"}` |
| Event Viewer row | `LENGTH=58` — ` Object of class stdClass could not be converted to string` | `LENGTH=168` — `Connect to Bug Tracker database fails: (interface: - Host:127.0.0.1 - DBName: nodb - User: nodb) 1045 - Access denied for user 'nodb'@'172.18.0.1' (using password: YES)` |
| `502` row (bad ADODB driver) | `LENGTH=107` — ` Call to a member function SetFetchMode() on false` | `api/issuetracker/index.php::GET /{id}/check-connection :: Call to a member function SetFetchMode() on false` |
| grid wrench on a dead host | generic failure icon, indistinguishable from a broken check | red `fa-skull-crossbones` with tooltip *"Connection failed (check type and configuration)"* |

A side effect worth stating explicitly: a tracker whose database is merely
unreachable is **not** an internal error, so it now takes the same
`200 {connected:false}` path as every other "refused"/"wrong credentials" case
instead of being reported as a gateway failure. The `502` path is now reserved
for a genuine fault in the check itself. This is the behaviour the legacy
`tlIssueTracker::checkConnection()` (`lib/functions/tlIssueTracker.class.php:783-809`)
always had, so the BFF now matches it.

---

## Verification

`bash tmp/verify_1701.sh` — **7/7 PASS, exit 0**. Fixtures: `IT-1701-DEADHOST`
(the reported repro), `IT-1701-BADDRIVER` (forces the `catch`), `IT-1701-REACHABLE`
(positive control).

| # | case | result |
|---|---|---|
| R1 | `GET /{DEADHOST}/check-connection` | `200`, `connected:false`, 1 row naming the real cause, no `stdClass` text — **PASS** |
| R2 | `GET /{BADDRIVER}/check-connection` (forces the `catch`) | `502`, row prefixed `api/issuetracker/index.php::GET /{id}/check-connection ::` — **PASS** |
| R3 | `POST /test-connection` (forces the `catch`) | `502`, row prefixed `api/issuetracker/index.php::POST /test-connection ::` — **PASS** |
| R4 | `GET /999999/check-connection` (bogus id) | `404` + `not found`, 0 new rows — **PASS** |
| R5 | `GET /api/issuetracker/?tproject_id=1` (list) | `200`, `total` == real row count, 0 new rows — **PASS** |
| R6 | `GET /{REACHABLE}/check-connection` (positive control) | `200`, `connected:true`, 0 new rows — **PASS** |
| R7 | Event Viewer sweep | `log_level=2` (`E_WARNING`) count = **0** — **PASS** |

**Negative control.** The same harness run against `HEAD~1` (pre-fix) gives
**4 PASS / 3 FAIL (exit 1)**: R1/R2/R3 fail, R4–R7 keep passing. The fix changed
exactly the three affected behaviours and regressed no control.

**Browser (headless Chrome).** `gui/templates/issuetracker/issuetrackerView.html?tproject_id=1`
→ *"Showing 1 to 3 of 3 entries"*; clicking the wrench on the dead-host row fires
`GET /api/issuetracker/index.php/8/check-connection` → `[200]` and paints the red
`fa-skull-crossbones` into `#conn-8` with tooltip *"Connection failed (check type
and configuration)"*. **Console: 0 errors, 0 warnings.**

Regression suite: `tmp/TLU_Test_Cases.md` → *"Regression — Issue #1701"*.

---

## Out of scope (filed as separate bugs)

The same two shapes exist elsewhere and were deliberately **not** touched here
("no drive-by changes in unrelated files"):

- `lib/codetrackerintegration/codeTrackerInterface.class.php:188-189` — the
  identical multi-level-interpolation defect, reachable through the Code-Tracker
  connection check.
- `api/scriptedit/index.php:123`, `api/codetracker/index.php:428,472,552`,
  `api/tcscripts/index.php:119` — the identical empty-`__METHOD__` trap.

`issueTrackerInterface.class.php:244-245` looks similar but is `.` concatenation,
not interpolation, and is safe.
