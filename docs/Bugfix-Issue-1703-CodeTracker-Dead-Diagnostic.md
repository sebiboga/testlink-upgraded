# Bugfix — Issue #1703: `codeTrackerInterface::connect()` built `$connection_args` with two-level interpolation, so a failed code-tracker connection logged **nothing**

**Branch:** `fix/issue-1703`
**Files changed:** `lib/codetrackerintegration/codeTrackerInterface.class.php` (1 statement, 2 lines + comment)
**Severity:** minor (diagnosability) — no wrong verdict, no data loss, but an operator had
**zero** usable information when a `db`-API code tracker's database was unreachable.

This is the **code-tracker twin** of #1701
(`docs/Bugfix-Issue-1701-Check-Connection-Dead-Diagnostic.md`). Same defect shape, same fix
shape, different file. #1701's run was explicitly time-boxed to a single bug with "no drive-by
changes in unrelated files", so the codetracker side was left behind — and it is now the *only*
remaining unfixed instance of the pattern in the repository.

---

## Symptom

A code-tracker connection failure produced **no Event Viewer record at all**. Not a degraded
one, not a wrong one — none. The line that records host / database / user / ADODB error code is
dead code:

```php
// lib/codetrackerintegration/codeTrackerInterface.class.php
if (!$result['status'])
{
  $this->dbConnection = null;
  $connection_args = "(interface: - Host:$this->cfg->dbhost - " .        // <- dies here
                     "DBName: $this->cfg->dbname - User: $this->cfg->dbuser) ";
  $msg = sprintf(lang_get('CTS_connect_to_database_fails'),$connection_args);
  tLog($msg  . $result['dbms_msg'], 'ERROR');                            // <- never reached
}
```

Measured end-to-end (`php` driver that boots the app, loads the real class and reads the real
`events` table):

```
$ php /tmp/opencode/r1703/drive.php
FATAL: Error: Object of class stdClass could not be converted to string
  at .../lib/codetrackerintegration/codeTrackerInterface.class.php:188

MAX(events.id) before = 10
MAX(events.id) after  = 10
--- rows written by the repro: 0 ---
```

## The subtlety: why `stdClass`, and why it matters

`$this->cfg` is **not** a `SimpleXMLElement` at line 188. `setCfg()` builds one at `:105` and
then converts it:

```php
// lib/codetrackerintegration/codeTrackerInterface.class.php:147
$this->cfg = json_decode(json_encode($this->cfg));
```

`json_decode()` without a second argument returns a **`stdClass`**. PHP resolves only **one**
property level in a non-curly interpolated string, so `"$this->cfg->dbhost"` interpolates
`$this->cfg` — the `stdClass` — and leaves `->dbhost` as literal text. Stringifying a
`stdClass` raises a fatal `Error`, which aborts the statement one line before `tLog()`.

Both candidate readings of the same construct were measured side by side, because they fail
*differently* and the distinction decides the fix:

| construct | `$cfg` type | result |
|---|---|---|
| `"$cfg->dbhost"` (local var, one level) | `SimpleXMLElement` | `Host:127.0.0.1` — works |
| `"$this->cfg->dbhost"` (two levels) | `SimpleXMLElement` | `Host:->dbhost` — garbled, harmless |
| `"$this->cfg->dbhost"` (two levels) | `stdClass` | **fatal `Error`** — the real case |

Had line 147 not converted `cfg`, this would have been a cosmetic string-mangling bug. Because
it did, it is a hard crash that eats the diagnostic. That is why "just a stdClass, probably
harmless" would have been the wrong conclusion.

## Why it survived — a latent fatal, correctly `minor`

`codeTrackerInterface::connect()` is the **base-class** implementation, and both concrete
subclasses override it:

```
$ grep -rn "function connect" lib/codetrackerintegration/*.php
lib/codetrackerintegration/githubrestCodeTrackerInterface.class.php:207
lib/codetrackerintegration/stashrestInterface.class.php:98

$ sed -n '28,29p' lib/functions/tlCodeTracker.class.php
var $systems = array( 1 => array('type' => 'stash', ...), 200 => array('type' => 'github', ...));
```

There is no `db` key in `tlCodeTracker::$systems`, so **no UI route reaches line 188 today**.
The base method is not dead by design, though: `getMyInterface()` (`:156`) exists solely to return
a class named by `$cfg->interfacePHP`, i.e. the class exists to be extended by a DB-backed
tracker. So this is an **armed fatal** for the next `db`-API tracker, not a currently-firing
crash — which is also why priority `minor` is right.

The second reason it mattered: after #1701 the codebase was **internally inconsistent**. A reader
comparing `issueTrackerInterface.class.php:241` (braces + explanatory comment) against
`codeTrackerInterface.class.php:188` (no braces, no comment) would reasonably conclude the
codetracker one was fine.

---

## Fix

Wrap the three property reads in `{}` — the identical shape already merged for #1701:

```diff
+      // Issue #1703: PHP resolves only ONE property level in a non-curly
+      // interpolated string, so "$this->cfg->dbhost" interpolated $this->cfg
+      // (a stdClass, see setCfg() line 147) and left "->dbhost" as literal
+      // text. Stringifying a stdClass raised a fatal Error here, one line
+      // before the tLog() below, so a failed code-tracker connection
+      // produced no Event-Viewer record at all.
-      $connection_args = "(interface: - Host:$this->cfg->dbhost - " .
-                         "DBName: $this->cfg->dbname - User: $this->cfg->dbuser) ";
+      $connection_args = "(interface: - Host:{$this->cfg->dbhost} - " .
+                         "DBName: {$this->cfg->dbname} - User: {$this->cfg->dbuser}) ";
```

### Why this method

* **Converges, not diverges.** Matches the #1701 twin exactly, so the two base classes now share
  one shape. The fix also removes the misleading signal that `:188` needs no braces.
* **Minimal.** One statement; no signature, behaviour, API or i18n change (the message string is
  unchanged and already resolves).
* **Rejected: stringify first** (`$host = (string)$this->cfg->dbhost;` then `"Host:$host"`). Same
  output, 3 extra statements, a second place for the value to be mangled.
* **Rejected: drop the diagnostic / wrap it in try-catch.** Hides the problem and leaves the
  Event Viewer empty — i.e. preserves the original symptom.
* **Rejected: rewrite `:147`** to keep `SimpleXMLElement`. That reintroduces the
  "Serialization of 'SimpleXMLElement' is not allowed" session failure documented right above it
  (`:129-146`), which is the whole reason the `json_decode` round-trip exists.

### Blast radius

The dangerous shape is a **double-quoted** string interpolating `$this->X->Y` (two levels).

```console
$ grep -rnE '"[^"]*\$this->[A-Za-z_]+->[A-Za-z_]+' --include=*.php lib/
lib/codetrackerintegration/codeTrackerInterface.class.php:188      <-- THE DEFECT
lib/codetrackerintegration/codeTrackerInterface.class.php:189      <-- THE DEFECT
lib/issuetrackerintegration/issueTrackerInterface.class.php:241     already fixed (#1701)
lib/issuetrackerintegration/issueTrackerInterface.class.php:242     already fixed (#1701)
... all remaining hits are either already curly-braced or function calls
```

**`:188-189` were the only two unfixed sites in the repository.** Both lines are the same
statement, so they cannot diverge. `codeTrackerInterface.class.php:203-204` is flagged by the
same grep but uses `.` concatenation, not interpolation — safe.

---

## Evidence — Event Viewer, post-fix

The diagnostic now surfaces in the Event Viewer with host, database, user **and** the
ADODB message. Pre-fix this screen gained **no row at all** (the fatal aborted the
statement one line before `tLog()`).

Screenshot (wiki + repo): `docs/screenshots/issue-1703-codetracker-diagnostic-postfix.png`

## Verification

Same driver, same cfg, same database as the pre-fix run:

```
# ---------- POST-FIX ----------
$ php /tmp/opencode/r1703/drive.php
ctor returned normally; isConnected() = 0

$ mysql -B testlink -e "SELECT id,log_level,LEFT(description,200) FROM events ORDER BY id DESC LIMIT 1"
id  log_level  d
12  1          Connect to Code Management database fails: (interface: - Host:127.0.0.1 -
               DBName: nodb - User: nodb) 1045 - Access denied for user 'nodb'@'172.18.0.1'
               (using password: YES)
```

`Host:`, `DBName:`, `User:` **and** the ADODB code now reach the Event Viewer.

| # | case | expectation | result |
|---|---|---|---|
| R1 | inherited `connect()`, unreachable DB (**the reported bug**) | no fatal; exactly 1 `events` row, level ERROR, with host/DB/user **and** ADODB code | **PASS** |
| R2 | same, pre-fix baseline | fatal at `:188`, 0 rows | **FAIL as expected** (this is the reproduction) |
| R3 | inherited `connect()`, **reachable** DB | `isConnected() = 1`, no new `events` row | **PASS** |
| R4 | `dbhost` absent → early `return false` at `:168-171` | `isConnected() = 0`, `$connection_args` never reached, no warning | **PASS** |
| R5 | `getMyInterface()` (`:156`) `interfacePHP` round-trip | returns the cfg value verbatim | **PASS** |
| R6 | registered REST types unaffected | both subclasses still declare their own `connect()`; base stays `abstract` | **PASS** |
| R7 | `php -l` on the edited file | clean | **PASS** |
| R8 | no new Error/Warning rows from R3–R6 | `COUNT(*) = 0` | **PASS** |

Root-cause guards: **I1** `lang_get('CTS_connect_to_database_fails')` resolves to
`"Connect to Code Management database fails: %s"` (checked on purpose — `:190` had never
executed, so the fix activates new code and any latent warning there would become a new
Event-Viewer row; none appeared). **I2** one-level vs two-level interpolation asserted
separately. **I3** repo-wide sweep finds no other unbraced two-level interpolation.
**I4** a *successful* connection logs nothing, proving the fix is not merely swallowing the
exception.

---

## Two traps that will bite the next person measuring a `tLog()` path

Both were hit during this fix and are recorded so a future run does not read them as a failed
fix:

1. **`tLog()` silently no-ops without an open logger transaction.**
   `logging.inc.php:87-96` returns `tl::ERROR` immediately when
   `$g_tlLogger->getTransaction()` is falsy, and `logger.class.php` `writeEvent()` only reaches
   the DB when the logger has a handler bound. The GUI gets both from `doAuthorize.php`. A CLI
   driver must do it explicitly, otherwise a *working* fix reports "0 rows":

   ```php
   global $g_tlLogger;
   $tlDb = new database(DB_TYPE); doDBConnect($tlDb);
   $g_tlLogger->setDB($tlDb);
   $g_tlLogger->startTransaction("REPRO1703", "cli-drive.php", 1);
   ```

2. **Count rows with `COUNT(*) WHERE id > $B`, never by subtracting `MAX(id)`.** The
   auto-increment gap made a single written row look like two (`10 → 12`).

## Re-run in one command

```bash
cd /home/runner/work/testlink-upgraded/testlink-upgraded
php -l lib/codetrackerintegration/codeTrackerInterface.class.php \
 && php /tmp/opencode/r1703/drive.php \
 && php /tmp/opencode/r1703/matrix.php \
 && mysql -h 127.0.0.1 -utestlink -ptestlink -B testlink \
      -e "SELECT id,log_level,LEFT(description,200) FROM events ORDER BY id DESC LIMIT 3"
```

Full suite: `tmp/TLU_Test_Cases.md` → `Regression — Issue #1703`.