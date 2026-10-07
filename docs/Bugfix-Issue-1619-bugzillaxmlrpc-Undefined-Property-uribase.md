# Bugfix — Issue #1619: `bugzillaxmlrpcInterface` logged `Undefined property: stdClass::$uribase` while building its own diagnostic

**Status:** resolved — fix landed and verified (`fix/issue-1619`, commit `73e8dd2ad`)
**Labels:** `bug` · **Priority:** minor
**Reported:** 2026-09-26T13:36:57Z (as a regression-matrix row of #1617) · **Fixed:** 2026-09-29
**Branch:** `fix/issue-1619` · **Files changed:** 1 (`lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php`, +28/−2)

---

## 1. Symptom

An Issue Tracker of **type 1** (bugzilla/xmlrpc) whose stored `cfg` **parses but carries no
`<uribase>` element** (e.g. `<testlink/>`) made every Issue-Tracker page load, and every
connection check, write two PHP 8.3 diagnostics into `events`:

```
E_WARNING     Undefined property: stdClass::$uribase        (bugzillaxmlrpcInterface.class.php:69)
E_DEPRECATED  trim(): Passing null to parameter #1 ($string) (bugzillaxmlrpcInterface.class.php:69)
```

The user-visible result was never wrong — the connection check still answered
`connected: true` — but the Event Viewer filled with rows on a path that is *itself* the
diagnostic, which is exactly the noise the CI "Event Viewer must be clean" checks read.

## 2. What the issue assumed, and what was actually true

The issue named the wrong line, and measuring it changed the fix. Worth recording, because
the wrong assumption is what made the defect look bigger than it is:

| Claim in the issue | Measured |
|---|---|
| the `catch` block at `:134-136` (`"$v={$this->cfg->$v} / "`) raises the warning | it is a **real, identical defect**, but it is **dead code** on this path — `createAPIClient()` only *constructs* `Zend_XmlRpc_Client` (it never connects, so it does not throw), and the PHP 8 `Error` it *can* raise is not an `Exception`, so `catch(Exception $e)` misses it (that gap is **#1629**) |
| one warning | **two** — the `E_DEPRECATED` was never reported, because the app's error handler only records the `E_WARNING` |

The **reachable** site is `completeCfg()` **line 69**, reached before `connect()` is ever
called:

```php
$base = trim($this->cfg->uribase,"/") . '/';   // reads unconditionally
```

## 3. Root cause

The hinge is one line in the shared base class:

```php
// lib/issuetrackerintegration/issueTrackerInterface.class.php:165
$this->cfg = json_decode(json_encode($this->cfg));
```

`setCfg()` re-binds `$this->cfg` from the `SimpleXMLElement` returned by
`simplexml_load_string()` to a **`stdClass`**, so that it is serializable into `$_SESSION`
(the comment above it explains the historic `Serialization of 'SimpleXMLElement' is not
allowed` fatal). That conversion changes the failure mode of a *missing* element:

| container | reading a missing element |
|---|---|
| `SimpleXMLElement` | silently `NULL` — no diagnostic at all |
| `stdClass` (after line 165) | **`E_WARNING Undefined property`** on PHP 8 |

So the cfg is not "empty XML" as far as `bugzillaxmlrpcInterface` is concerned — it is an
object with no such member, and every read must be guarded. Entry points that reach the
chain: `api/issuetracker/index.php:212` (`GET /{id}/check-connection`, the list wrench) and
`:260` (`POST /test-connection`, the edit modal's *Check Connection*); the grid itself runs
`checkEnv` for every row at `api/issuetracker/index.php:112`.

**Why it surfaces now and not on PHP 5/7:** there, the missing member was a silent `NULL`.
Note also that it only became *reachable* when the `=== false` identity fix (#432/#649,
`issueTrackerInterface.class.php:120`) made such a cfg parse successfully instead of being
rejected one function earlier — that fix is correct and was kept; it just exposes the next
unguarded read downstream.

## 4. The method chosen, and the alternatives rejected

1. **Guard the read at the call site** — *chosen.* `$this->cfg` arrives unvalidated from
   manager-supplied XML, so the consumer reads defensively. One file, two statements.
2. **Validate in `setCfg()`** (reject a cfg with no `<uribase>`, return `false`) — rejected:
   a behaviour change on the base class shared by every tracker type, and the issue asks for
   a warning-free *diagnostic*, not a rejection.
3. **Rewrite the log loop with `property_exists()`** — rejected: equivalent to `??` here, but
   noisier; `??` is the idiom the sibling fix #1617 already established in
   `tlIssueTracker.class.php:618`.
4. **Fix all 8 sibling interface classes at once** — rejected: out of scope for a one-bug run
   (rules 8/17). Filed separately (see §7).
5. **Widen `catch(Exception)` to `catch(Throwable)`** — rejected: that is **#1629**, already
   open, with its own analysis.

## 5. The fix

```php
// completeCfg()  — the reachable defect
$uri = $this->cfg->uribase ?? '';
$base = trim(is_scalar($uri) ? (string)$uri : '',"/") . '/';

// connect() catch — the latent twin, and the one the issue reported
$val = $this->cfg->$v ?? '';
$logDetails .= "$v=" . (is_scalar($val) ? $val : '') . " / ";
```

`is_scalar()` was **not** in the original plan — the regression matrix found a **third, worse
variant of the same root cause**: a *whitespace-only* element (`<uribase>  </uribase>`)
survives the SimpleXML→`json`→`stdClass` round-trip as an empty `SimpleXMLElement`, i.e. a
**nested `stdClass`**, not as a missing property. So `trim()` did not warn there — it raised
an **uncatchable** `TypeError` that killed the whole request:

```
PHP Fatal error: Uncaught TypeError: trim(): Argument #1 ($string) must be of type string, stdClass given
  in lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php - Line 69
```

A plain `(string)` cast only *relocated* that fatal one statement later, into the
`issueDefaults` loop. The same nesting breaks the catch block's log line, where interpolating
an object raises an `Error` that the logger itself cannot catch — the log line would have
killed the request it was describing. One guard handles all three variants.

## 6. Verification

Method: the **pre-fix** class was restored from `git show HEAD:…` and driven through **one**
harness alongside the fixed class, so every case is measured on both sides rather than
asserted from the source.

| # | Case | pre-fix | post-fix |
|---|------|---------|----------|
| 1 | `<testlink/>` (issue repro) | `E_WARNING` + `trim(null)` deprecation | **0 diagnostics** |
| 2 | full valid cfg (control) | 0 | 0, derived URIs **byte-identical** |
| 3 | `uribase` only, no `apikey` | 0 | 0, byte-identical |
| 4 | `<uribase>http://h///</uribase>` | 0 | 0, `http://h/xmlrpc.cgi` (de-double-slash preserved) |
| 5 | valid cfg with **no** `<uribase>` | `E_WARNING` + deprecation | **0** |
| 6 | whitespace-only `<uribase>` | **`PHP Fatal` (TypeError) — request killed** | **clean, 0** |
| 7 | `catch` log line, 4 cfg shapes | 2 warnings / 0 / 1 warning / **`PHP Fatal`** | 0 everywhere, exact expected strings |

Case 7 post-fix, i.e. the `Expected` string from the issue body:

```
empty cfg        -> [uribase= / apikey=]
full cfg         -> [uribase=http://b/ / apikey=SECRET]
uribase only     -> [uribase=http://b/ / apikey=]
nested apikey    -> [uribase=http://b/ / apikey=]
```

Live over HTTP as `admin`, with the bad row present in the DB:

```
POST /api/issuetracker/test-connection  {name:'IT_BZ1619_REPRO', type:1, cfg:'<testlink/>'}
  -> 200 {"status":"ok","connected":true,"message":"Connection OK"}
GET  /api/issuetracker/1/check-connection
  -> 200 {"status":"ok","connected":true,"message":"Connection OK"}
GET  /api/issuetracker/
  -> 200 both rows rendered, env_check_ok: true
```

`SELECT COUNT(*) FROM events` — **8 before, 8 after**; the same single request added the
warning pre-fix. Reloading the Issue Tracker grid (which runs `checkEnv` on the bad row on
every load) also added **0** rows. The `connected` / `env_check_ok` verdicts are unchanged,
so the fix removes noise only — it does not mask or alter a connection result.

Regression suite `## Regression — Issue #1619` in `tmp/TLU_Test_Cases.md`:
**14 assertions, 14 PASS** (`php tmp/verify_1619.php`, exit 0). Run against the pre-fix class
the same script reports 2 FAILs and then dies on the case-6 fatal, so the suite genuinely
guards the defect while all five control cases pass on both sides. `php -l` clean; no new
`E_WARNING`/`E_DEPRECATED` row.

## 7. Left open on purpose

* **`connect()`'s `catch(Exception)` still cannot catch** the PHP 8 `Error` `createAPIClient()`
  can raise (`:268`) — **#1629**.
* **The 7 sibling interface classes carry the identical unguarded read** and are untouched:
  `tracxmlrpc:85`+`:149`, `gitlabrest:70`/`:150`/`:178`, `redminerest:67`/`:162`/`:189`,
  `fogbugzrest:57`/`:105`, `kaitenrest:55`, `trellorest:60`, `tuleaprest:60` —
  **fixed in #1710** (2026-10-07).
* `setCfg()` itself still produces a `stdClass` (`:165`); hardening that shared base class
  would change behaviour for every tracker type, so the read is guarded at the call site.

### 7.1 Found by the mandatory code review of this fix (measured, NOT fixed here)

The review of this diff found that `is_scalar()` is needed at **two more `(string)` casts in
the same class** for the *element-valued* variant of the identical root cause — an element
with children decodes to a nested `stdClass`, not a missing property:

* `completeCfg():104` — the `issueDefaults` loop. `property_exists()` is **true** for a
  nested `stdClass`, so the guard passes and the cast throws. Affects `version`, `severity`,
  `op_sys`, `priority`, `platform`.
* `createAPIClient():294` — `new Zend_XmlRpc_Client((string)$this->cfg->urixmlrpc)`.
  `completeCfg()` only *skips* that assignment when `property_exists` is true, so a nested
  `<urixmlrpc>` reaches the cast, and the resulting `Error` escapes **both**
  `catch(Exception)` blocks (`:131`, `:146`) — so the log line this fix hardened is never
  even reached.

Measured:

```
<version><x/></version>       Error: Object of class stdClass could not be converted to string
<platform><x/></platform>     Error: Object of class stdClass could not be converted to string
<urixmlrpc><x/></urixmlrpc>   Error: Object of class stdClass could not be converted to string
<version>1.0</version>        connected=true          (control)
```

Live over HTTP as `admin`, `POST /api/issuetracker/test-connection`:

```
version-nested   -> 502 {"status":"error","connected":false,"message":"Connection check failed"}
urixmlrpc-nested -> 502 {"status":"error","connected":false,"message":"Connection check failed"}
control          -> 200 {"status":"ok","connected":true,"message":"Connection OK"}
```

The 502 rather than an empty 500 is only because `api/issuetracker/index.php:265` catches
`\Throwable`; `tlIssueTracker::checkConnection()` (`tlIssueTracker.class.php:800`)
instantiates with **no** `try`/`catch` at all, so the legacy connection-check path dies with
an empty HTTP 500. Filed as **#1711**.

A consequence worth recording: a *"0 diagnostics"* assertion **cannot** observe an
uncatchable `Error`, so the 14 PASS above must be read together with #1711's numbers — they
are not evidence that the class is clean.

## 8. Screenshots

| File | Shows |
|---|---|
| `issue-1619-issuetracker-grid-after.png` | the Issue Tracker grid with the bad-cfg row (`IT_BZ1619_BADCFG`, no Server URL) still listed and `Environment: OK` — the fix changes no verdict |
| `issue-1619-eventviewer-no-warnings.png` | Event Viewer after the full post-fix live run: 8 events, all timestamped in the pre-fix repro window, **none** from the post-fix requests |

### Fix for Issue #1710 (applied 2026-10-07)

All 7 sibling classes were updated with #1619-style guards:
- Null coalesce `$v = $this->cfg->uribase ?? ''` and `trim(is_scalar($v) ? (string)$v : '', "/")` before trim()
- Safe writes for `kaitenrestInterface` and `trellorestInterface` (read→guard→trim→assign back)
- Guarded catch-block interpolations: `$val = $this->cfg->$v ?? ''; log "v=" . (is_scalar($val)?$val:'')`

Result: missing `<uribase>` produces no `E_WARNING Undefined property`; whitespace-only `<uribase>` produces no `TypeError`. Valid configs byte-identical. All touched files pass `php -l`.
