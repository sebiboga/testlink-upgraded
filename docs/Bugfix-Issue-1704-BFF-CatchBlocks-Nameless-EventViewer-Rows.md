# Bugfix — Issue #1704: BFF `catch` blocks logged `tLog(__METHOD__ …)` at **file top level** → nameless Event Viewer rows

**Status:** resolved — 4 files changed (+6/−4), 11/11 regression cases PASS.
**Labels:** `bug` · **Reported:** 2026-09-29T01:16:13Z · **Verified:** 2026-10-05
**Branch:** `fix/issue-1704`
**Files changed:** `api/codetracker/index.php`, `api/scriptedit/index.php`,
`api/tcscripts/index.php`, `api/testcases/index.php`
**Severity:** minor (diagnosability) — no data loss, no wrong verdict, but two
Event-Viewer rows named **neither a file nor a route**, and were byte-identical to
each other.

This is the sibling follow-up of [#1701](Bugfix-Issue-1701-Check-Connection-Dead-Diagnostic.md):
that fix replaced the empty-`__METHOD__` log in `api/issuetracker/index.php` with a
hand-written file+route prefix and filed this issue for the same idiom in the other
BFF entry points.

---

## Symptom

Whenever a BFF catch block fired, the Event Viewer recorded an ERROR row whose
description was `" <message>"` — one leading space, **no file, no route**:

```
id: 3   log_level: 1 (ERROR)   len: 18   HEX(LEFT(description,12)) = 20666F726365642D31373034
description = [ forced-1704-repro]
id: 2   log_level: 1 (ERROR)   len: 18   HEX(LEFT(description,12)) = 20666F726365642D31373034
description = [ forced-1704-repro]        <-- byte-identical to id 3
```

`LENGTH` = 18 = 1 (ASCII space, HEX `20`) + 17 (the message). Since CI inspects the
Event Viewer after every run (AGENTS.md rule 12), such rows are actively misleading:
the two failing endpoints cannot be told apart, and neither can be attributed to a
file.

## Repro steps

1. Create the fixture tracker. `type` is the **numeric** code `200` (github — read it
   from `GET /api/codetracker/meta/types`) and `cfg` must be the **XML** wrapper, or
   `tlCodeTracker::checkXMLCfg` rejects the request with
   `Failure loading XML STRING`:
   ```bash
   curl -s -b c.jar -X POST -H "Origin: http://localhost:8082" -H "Content-Type: application/json" \
     -d '{"name":"repro-1704","type":200,
          "cfg":"<codetracker><repository>https://github.com/sebiboga/testlink-upgraded</repository><branch>main</branch><token>ghp_dummy</token><apibase>https://api.github.com/</apibase></codetracker>"}' \
     http://localhost:8082/api/codetracker      # -> {"status":"ok","item":{"id":1,…}}
   ```
2. **Probe** — not part of the fix, revert afterwards: both catch blocks wrap
   `isConnected()`, which only returns a property and can never throw. Force it with
   `throw new RuntimeException('forced-1704-repro');` inside `isConnected()` in
   `lib/codetrackerintegration/githubrestCodeTrackerInterface.class.php`, then
   `php -l` the file.
3. ```bash
   curl -s -b c.jar -X POST -H "Origin: http://localhost:8082" \
        http://localhost:8082/api/codetracker/1/test_connection
   curl -s -b c.jar -X POST -H "Origin: http://localhost:8082" -H "Content-Type: application/json" \
        -d '{"repository":"https://github.com/sebiboga/testlink-upgraded","token":"ghp_dummy","branch":"main"}' \
        http://localhost:8082/api/codetracker/test_github
   ```
   Both answer HTTP **502** with the code's own envelope (`Connection test failed`).
4. ```bash
   mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
     -e "SELECT id,log_level,LENGTH(description),HEX(LEFT(description,12)),description FROM events ORDER BY id DESC LIMIT 2\G"
   ```

**Expected:** the row names the file **and** the route, as `api/issuetracker/index.php`
has since #1701 — `api/codetracker/index.php::POST /test_github :: <message>`.

## Root cause

A BFF entry point is a **flat request script**: routing is a chain of inline
`if ($method === … && $segments[0] === …)` / `switch ($action)` blocks — no class, no
controller method. A `try` inside such a block therefore sits at **file scope**, and
PHP defines `__METHOD__` (`__CLASS__ :: __FUNCTION__`) there as the **empty string**:

```
$ php -r 'echo "top-level __METHOD__=[" . __METHOD__ . "] len=" . strlen(__METHOD__) . "\n";'
top-level __METHOD__=[] len=0
$ php -r 'function f(){ echo "in-fn __METHOD__=[" . __METHOD__ . "]\n"; } f();'
in-fn __METHOD__=[f]
```

So `tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR')` writes `" <message>"` at file
scope. `tLog` stores `description` verbatim, so the row lands in `events` exactly as
written — the defect is at the call site, not in the logger.

### Scope correction (measured, and the reason the fix touches 9 lines and not 5)

The issue listed 5 sites and asserted all of them were at file top level. A column-0
brace scan over **every** `api/*/index.php` (`php /tmp/opencode/toplevel.php $(ls -d
api/*/index.php)`) shows **2** file-scope sites and 7 more inside functions:

| site | actual scope | what the old row contained |
|---|---|---|
<!-- pre-fix rows are listed WITHOUT the trailing message; `__METHOD__` in a *global* function is just the function name (no `::`), because there is no enclosing class -->
| `api/codetracker/index.php:472` | **file top level** (`POST /test_github`) | `""` — nameless |
| `api/codetracker/index.php:552` | **file top level** (`POST /{id}/test_connection`) | `""` — nameless |
| `api/codetracker/index.php:428` | `githubInterfaceFor()` | `githubInterfaceFor` |
| `api/scriptedit/index.php:123` | `linkedTracker()` | `linkedTracker` |
| `api/tcscripts/index.php:119` | `linkedTracker()` | `linkedTracker` |
| `api/testcases/index.php:114` | `tcViewCodeTracker()` | `tcViewCodeTracker` |
| `api/testcases/index.php:142` | `tcViewScripts()` | `tcViewScripts` |
| `api/testcases/index.php:298` | `tcVersionRelations()` | `tcVersionRelations` |
| `api/testcases/index.php:417` | `tcViewCtsUrl()` | `tcViewCtsUrl` |

The 4 `api/testcases/index.php` sites were **not** in the issue body (same defect, same
missing file attribution). The 58 `tLog(__METHOD__ …)` hits under `lib/**` are inside
class methods of `Interface.class.php` / `*.class.php`, where `__METHOD__` is correct
and non-empty — **out of scope**.

## The fix

One line per site, in the #1701 format `api/<file>::<route-or-function> :: <message>`,
with an inline comment at the two file-scope sites naming the footgun:

```php
-        tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR');
+        // Issue #1704: this catch is at file top level, where PHP defines
+        // __METHOD__ as the empty string -> the row landed in the Event Viewer
+        // as " <message>" with no file and no route. Name file + route.
+        tLog('api/codetracker/index.php::POST /test_github :: ' . $e->getMessage(), 'ERROR');
```

Rejected alternatives:

* **Keep `__METHOD__`, prepend the file** — the two file-scope sites would still render
  `""` (a double space) unless the literal is replaced too.
* **A shared `bffLogError($e)` helper** — a new abstraction for 9 one-line calls, with a
  larger blast radius (every future BFF entry point would have to adopt it). Noted as the
  follow-up option if the pattern keeps spreading.
* **Wrapping route bodies in closures to make `__METHOD__` non-empty** — a risky
  restructuring of a 600-line router for a log string (same argument rejected in #1701).

The pre-existing `tcversion <id>` context on the `tcVersionRelations` site is preserved
after the new prefix (`…::tcVersionRelations :: tcversion 5: <msg>`), so no information was
lost and the documented `… :: <message>` contract holds for all 9 sites.

## Verification (measured 2026-10-05)

| # | Case | Result |
|---|---|---|
| 1 | pre-fix `POST /{id}/test_connection` | `len=18`, `HEX … 20…`, `[ forced-1704-repro]` |
| 2 | pre-fix `POST /test_github` | byte-identical to #1 |
| 3 | post-fix `POST /{id}/test_connection` | `[api/codetracker/index.php::POST /{id}/test_connection :: forced-1704-repro]` (74 B), HTTP 502 unchanged |
| 4 | post-fix `POST /test_github` | `[api/codetracker/index.php::POST /test_github :: forced-1704-repro]` (65 B), HTTP 502 unchanged |
| 5 | function-scope site (probe in `__construct`) | `[api/codetracker/index.php::githubInterfaceFor :: forced-1704-ctor]`, HTTP 400 unchanged |
| 6 | happy path, no probe, real public repo | `{"status":"ok","connected":true,"branchCount":100,…}`, **no** new `events` row |
| 7 | `GET /api/codetracker/1/branches` with probe | existing 502 `Unable to fetch branches…`, no new row |
| 8 | `grep -rn 'tLog(__METHOD__' --include=*.php api` | **0 hits** (was 9) |
| 9 | `php -l` on the 4 touched files | *No syntax errors detected* ×4 |
| 10 | Event Viewer + Code Tracker screens (Chrome) | ERROR rows render with file+route; grid loads; **0 console errors/warnings** |
| 11 | Event Viewer after the whole pass | only the forced ERROR rows + expected login AUDIT rows — no new Warning/Notice |


Regression suite: `Regression — Issue #1704` (11 cases) appended to
`tmp/TLU_Test_Cases.md`, gated with
`TLU_REQUIRE_SUITE="Issue #1704" bash ai/verify_test_suites.sh` → **7 PASS / 0 FAIL**.

**RESUME** — one command reproduces the defect, none of it needs a browser:

```bash
# which sites are really at FILE TOP LEVEL (the bug) vs inside a function
php /tmp/opencode/toplevel.php $(ls -d api/*/index.php)
# the mechanism
php -r 'echo strlen(__METHOD__), "\n";'    # 0 at file scope
```
