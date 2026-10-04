# Issue #1811 — `checkForBTSConnection()` was dead code: the legacy "Bug Tracking System has failed" security note could never fire

| | |
|---|---|
| **Issue** | [#1811](https://github.com/sebiboga/testlink-upgraded/issues/1811) |
| **Type** | bug (legacy config check) |
| **Fix commit** | `c5206fd1e` — `lib/functions/configCheck.php` (1 call site) |
| **Severity** | minor — a security note that silently never fired; no data loss, no crash |
| **Status** | **FIXED**, verified error-free |

---

## 1. Symptom

`checkForBTSConnection()` is the legacy helper behind the
`$TLS_bts_connection_problems` note ("Connection to your Bug Tracking System has
failed: … Be careful this problem will degrade TestLink performance.",
`locale/en_US/strings.txt:2582`). It could never return `false`, so the note was
never added to the security notes on any legacy page, and — with the shipped
default `config_check_warning_mode = 'FILE'` (`config.inc.php:361`) — never
reached `logs/config_check.txt` either. A user whose Bug Tracking System was
down was given no warning at all.

## 2. How it was found

While writing the reproduction for #1811 the reported root cause turned out to be
**incomplete**: the `$g_bugInterface` global really is gone from the tree, but a
DB-driven fallback had already been added to the function by commit
`c21cbedd9` ("leftover changes from bug-fix run") and had landed on the default
branch without documentation and without closing the issue. The dead code had
simply moved one level up — the fallback works, but **no caller ever passes a DB
handle**, so it is unreachable.

Measured on `96bfc55c4` (pre-fix), with a tracker linked to a test project whose
`uribase` points at a closed local port (`http://127.0.0.1:1`, so `connect()`
fails in ~2 ms with `ECONNREFUSED`):

```
checkForBTSConnection($db) = false  (2 ms)     <- DB fallback works
checkForBTSConnection()     = true   (0 ms)     <- this is what getSecurityNotes() passed
getSecurityNotes($db)       = array (6 notes, none of them the BTS one)
grep -ci "Bug Tracking System has failed" logs/config_check.txt -> 0
```

## 3. Root cause chain

| # | hop | location |
|---|---|---|
| 1 | `getSecurityNotes(&$db)` decides whether the note belongs in the list | `lib/functions/configCheck.php:251` |
| 2 | **it calls the checker with no argument** | `lib/functions/configCheck.php:273` (pre-fix) |
| 3 | the checker needs a DB handle for its fallback and returns early without one | `lib/functions/configCheck.php:340,351-353` |
| 4 | so `$status_ok` can never become `false` and `$securityNotes[] = lang_get("bts_connection_problems")` never runs | `lib/functions/configCheck.php:274` |
| 5 | both consumers therefore never receive the note | `login.php:230`, `lib/functions/common.php:1858` (`initUserEnv()`) |

Blast radius: `checkForBTSConnection()` has exactly **one** call site
(`configCheck.php:273`) and its output feeds `initUserEnv()`, which is used by
`lib/execute/execDashboard.php`, `lib/results/*`, `lib/plan/planEdit.php`,
`lib/usermanagement/userInfo.php`, `lib/testcases/tcImport.php`, … — and, in the
default `FILE` mode, `logs/config_check.txt`.

## 4. The fix

```diff
--- a/lib/functions/configCheck.php
+++ b/lib/functions/configCheck.php
@@ getSecurityNotes(&$db)
-  if (!checkForBTSConnection()) {
+  if (!checkForBTSConnection($db) {
     $securityNotes[] = lang_get("bts_connection_problems");
   }
```

**Why this method and not the alternatives**

* *Re-introduce `$g_bugInterface`* — rejected: no caller has the project-scoped
  context (`login.php` has no project at all), and that is exactly the deletion
  that created the bug.
* *Delete the fallback and the note* — rejected: it would silently drop a check
  1.9.20 performed, with its strings still shipped in every locale bundle.
* *Reuse `install_check_bts_connection()`* (`api/install/index.php:191-257`,
  issue #1282) — rejected: that is a request script that emits JSON and calls
  `tLog()`; the legacy include chain must not depend on it. The two checks run in
  different contexts (the installer has no project session), so they stay separate.

`getSecurityNotes(&$db)` already holds the handle (by reference) and the checker
takes it by value, so no call-site semantics change.

## 5. Verification

`tmp/fixtures_1811.php` (fixture: project + test plan + type-15 tracker on a closed
port, linked) and `tmp/verify_1811.php` (8-case matrix; any `E_WARNING` /
`E_NOTICE` / `E_USER_*` inside the check fails the run).

| case | expected | observed | verdict |
|---|---|---|---|
| A — no tracker linked | `true`, no note | `true`, no note | PASS |
| B — linked tracker, `connect()` fails | `false`, note present, fast, no new `events` row | `false`, note present, **3 ms**, **0** new rows | PASS |
| C — linked tracker, unknown `type` | degrades to "failed", no diagnostic | `false`, **0** new rows | PASS |
| D — shipped `FILE` mode | note in `logs/config_check.txt` | written, `grep -c` → 1 | PASS |
| E — `GET /login.php` anonymous | 200, no Error/Warning | `200` in **0.073 s**, only INFO audit row | PASS |
| F — legacy controller as `admin` | `200`, note in `config_check.txt`, no new events | `200` / 3 981 bytes, note present, **0** events matching `%configCheck%`/`%bts%` | PASS |
| G — default `ONCE_FOR_SESSION` | computed once per session | not recomputed on the 2nd request, as in 1.9.20 | PASS |
| H — Event Viewer after the run | nothing new from this change | only pre-existing `execDashboard.php:205-206` warnings → **#1813** | PASS |

Post-fix `logs/config_check.txt` (shipped `FILE` mode, produced by a real HTTP
request to `lib/execute/execDashboard.php`):

```
Install directory should be removed!
You should change the default password for the 'admin' account!
Connection to your Bug Tracking System has failed:<br />
                                Please check your configuration.<br />
                                Be careful this problem will degrade TestLink performance.
Check following parameters of email feature:
...
```

Cost: the check runs at most once per session
(`config_check_warning_frequence = 'ONCE_FOR_SESSION'`, `config.inc.php:367`) and
only over trackers **linked to a project** — the same objects
`tlIssueTracker::getInterfaceObject()` already connects on every BTS-enabled
screen. With no BTS configured it is not executed at all (case A).

## 6. Known limitation (not part of this fix)

The notes are computed again but **no screen in 2.0.1 renders them**:
`gui/templates/tl-classic/mainPage.tpl:83` is the only legacy renderer and
`mainPage.php` was replaced by `gui/templates/mainpage/mainPage.html`
(`lib/general/mainPage.php:16`, `index.php:88-94`); the modern Home screen has no
config-check banner; only the Install screen has its own implementation
(`api/install/index.php`). That display gap is filed as **#1814** (enhancement) —
deliberately not smuggled into a one-line bug fix.

## 7. Also found while testing

* **#1813** (bug) — `lib/execute/execDashboard.php:205-206` raises 2 × `E_WARNING
  "Trying to access array offset on false"` per load when the plan has no builds
  (`$buildMgr->get_by_id()` returns `false`; the guard used 3 lines below at
  `:209` is missing on the two reads above it).
* **#1815** (bug) — PHP 8.2 `E_DEPRECATED: Creation of dynamic property …` for
  `redminerestInterface::$canSetReporter` (`:39`), `$issueAttr` (`:112`) and
  `tlUser::$loginRegExp` (`lib/functions/tlUser.class.php:158`, raised on every
  `getSecurityNotes()` call via `checkForAdminDefaultPwd()`). Not logged into
  `events`, but fatal on PHP 9.

## 8. Files changed

* `lib/functions/configCheck.php` — one call site (`checkForBTSConnection($db)`)
  plus a comment explaining why the handle is mandatory.
* `CHANGELOG` — one line under the 2.0.1 `[KEY BUGFIX]` section.

Fixtures/harnesses (`tmp/fixtures_1811.php`, `tmp/verify_1811.php`,
`tmp/TLU_Test_Cases.md`) live under the git-ignored `tmp/`; they are recreated on
every run because the database is re-imported.

## 9. How to re-test

```bash
php tmp/fixtures_1811.php     # creates project 7 / plan 8 / tracker 9003 (closed port)
php tmp/verify_1811.php       # 8-case matrix -> "ALL CHECKS PASSED"
cat logs/config_check.txt     # must contain "Connection to your Bug Tracking System has failed"
php tmp/fixtures_1811.php --reset
```

Browser: log in `admin/admin` → open a test plan's Execution Dashboard
(`lib/execute/execDashboard.php?tplan_id=8&tproject_id=7`) with
`config_check_warning_frequence = 'ALWAYS'` and `config_check_warning_mode =
'FILE'` in `custom_config.inc.php`, then check `logs/config_check.txt`.