# Issue 1704 — BFF `catch` blocks logged `tLog(__METHOD__ …)` at file top level → nameless Event Viewer rows

**Issue:** [#1704](https://github.com/sebiboga/testlink-upgraded/issues/1704)
**Branch:** `fix/issue-1704`
**Status:** VERIFIED-FIXED (2026-10-05)
**Commits:** `b04ef7a84` (the two file-scope sites), `1ef05f817` (the 7 function-scope sites),
`d07394fe9` (regression suite)

## Symptom

When a BFF catch block fires, the Event Viewer recorded an ERROR row whose description was
`" <message>"` — a bare leading space, **no file, no route**. Two different endpoints wrote
byte-identical rows, so during CI triage (AGENTS.md rule 12) there was nothing to attribute
the failure to.

Measured on the two file-scope sites (`api/codetracker/index.php`), pre-fix:

```
id: 3  log_level: 1  len: 18  HEX(LEFT(description,12)) = 20666F726365642D31373034
    description = [ forced-1704-repro]
id: 2  log_level: 1  len: 18  HEX(LEFT(description,12)) = 20666F726365642D31373034
    description = [ forced-1704-repro]        <-- byte-identical to id 3
```

`LENGTH` = 18 = 1 (the ASCII space, HEX `20`) + 17 (the message).

## Repro steps

1. Create the fixture tracker (`type` is the **numeric** code `200` = github, and `cfg` must be
   the XML wrapper or `tlCodeTracker::checkXMLCfg` rejects the request):
   ```bash
   curl -s -b c.jar -X POST -H "Origin: http://localhost:8082" -H "Content-Type: application/json" \
     -d '{"name":"repro-1704","type":200,"cfg":"<codetracker><repository>https://github.com/sebiboga/testlink-upgraded</repository><branch>main</branch><token>ghp_dummy</token><apibase>https://api.github.com/</apibase></codetracker>"}' \
     http://localhost:8082/api/codetracker
   ```
2. **Probe** (revert afterwards — it is not part of the fix): `isConnected()` only returns a
   property, so it can never throw. Force it with
   `throw new RuntimeException('forced-1704-repro');` in
   `lib/codetrackerintegration/githubrestCodeTrackerInterface.class.php` (inside
   `isConnected()`), then `php -l` the file.
3. `curl -s -b c.jar -X POST -H "Origin: http://localhost:8082" http://localhost:8082/api/codetracker/1/test_connection`
4. `curl -s -b c.jar -X POST -H "Origin: http://localhost:8082" -H "Content-Type: application/json" \
    -d '{"repository":"https://github.com/sebiboga/testlink-upgraded","token":"ghp_dummy","branch":"main"}' \
    http://localhost:8082/api/codetracker/test_github`
5. `mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT id,log_level,LENGTH(description),HEX(LEFT(description,12)),description FROM events ORDER BY id DESC LIMIT 2\G"`

## Expected

Each catch block logs a prefix naming the **file** and the **route**, as
`api/issuetracker/index.php` already does since #1701:

```php
tLog('api/codetracker/index.php::POST /test_github :: ' . $e->getMessage(), 'ERROR');
```

## Root cause

BFF entry points are **flat request scripts**: routing is a chain of inline
`if ($method === … && $segments[0] === …)` / `switch ($action)` blocks with no class and no
controller method. A `try` inside such a block is at **file scope**, and PHP defines
`__METHOD__` there as the **empty string**:

```
$ php -r 'echo "top-level __METHOD__=[" . __METHOD__ . "] len=" . strlen(__METHOD__) . "\n";'
top-level __METHOD__=[] len=0
$ php -r 'function f(){ echo "in-fn __METHOD__=[" . __METHOD__ . "]\n"; } f();'
in-fn __METHOD__=[f]
```

So `tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR')` at file scope writes `" <message>"`.
`tLog` stores `description` verbatim, so the row lands in `events` exactly as written — the
defect is at the call site, not in the logger.

### Scope correction (measured)

The issue body listed 5 sites and asserted all of them were at file top level. Measured with
a column-0 brace scan over **every** `api/*/index.php`, the truth is **2** top-level sites and
7 inside functions:

| site | actual scope | old row |
|---|---|---|
| `api/codetracker/index.php:472` | **file top level** (`POST /test_github`) | `""` — nameless |
| `api/codetracker/index.php:552` | **file top level** (`POST /{id}/test_connection`) | `""` — nameless |
| `api/codetracker/index.php:428` | `githubInterfaceFor()` | `::githubInterfaceFor` |
| `api/scriptedit/index.php:123` | `linkedTracker()` | `::linkedTracker` |
| `api/tcscripts/index.php:119` | `linkedTracker()` | `::linkedTracker` |
| `api/testcases/index.php:114` | `tcViewCodeTracker()` | `::tcViewCodeTracker` |
| `api/testcases/index.php:142` | `tcViewScripts()` | `::tcViewScripts` |
| `api/testcases/index.php:298` | `tcVersionRelations()` | `::tcVersionRelations` |
| `api/testcases/index.php:417` | `tcViewCtsUrl()` | `::tcViewCtsUrl` |

The 4 `api/testcases/index.php` sites were **not** listed in the issue body — same defect,
same missing file attribution. The 58 `tLog(__METHOD__ …)` hits under `lib/**` are inside class
methods of `Interface.class.php` / `*.class.php` files, where `__METHOD__` is correct and
non-empty — **out of scope**.

## The fix

One line per site, adopting the #1701 reference format
`api/<file>::<route-or-function> :: <message>`, with an inline comment at the two file-scope
sites naming the footgun so it is not reintroduced:

* `api/codetracker/index.php:475` — `'api/codetracker/index.php::POST /test_github :: '`
* `api/codetracker/index.php:556` — `'api/codetracker/index.php::POST /{id}/test_connection :: '`
* `api/codetracker/index.php:428` — `'api/codetracker/index.php::githubInterfaceFor :: '`
* `api/scriptedit/index.php:123`, `api/tcscripts/index.php:119` — `'…::linkedTracker :: '`
* `api/testcases/index.php:114,142,298,417` — `'api/testcases/index.php::<fn> :: '`
  (the pre-existing `tcversion <id>` context on the `tcVersionRelations` site is preserved)

Rejected alternatives:

* **Keep `__METHOD__` and prepend the file** — still leaves the two file-scope sites at `""`
  (a double space) unless the literal is replaced too; replacing the whole prefix is simpler.
* **A shared `bffLogError($e)` helper** — a new abstraction for 9 one-line calls, with a
  larger blast radius (every future BFF entry point would have to adopt it). Out of scope for
  a `minor` diagnosability fix; noted as the follow-up option if the pattern spreads further.

## Verification (measured 2026-10-05)

| # | Case | Result |
|---|---|---|
| 1 | pre-fix `POST /{id}/test_connection` | `len=18`, `HEX … 20…`, `[ forced-1704-repro]` |
| 2 | pre-fix `POST /test_github` | byte-identical to #1 — sites indistinguishable |
| 3 | post-fix `POST /{id}/test_connection` | `[api/codetracker/index.php::POST /{id}/test_connection :: forced-1704-repro]`, HTTP 502 (unchanged) |
| 4 | post-fix `POST /test_github` | `[api/codetracker/index.php::POST /test_github :: forced-1704-repro]`, HTTP 502 (unchanged) |
| 5 | function-scope site (probe in `__construct`) | `[api/codetracker/index.php::githubInterfaceFor :: forced-1704-ctor]`, HTTP 400 (unchanged) |
| 6 | happy path, no probe, real public repo | `{"status":"ok","connected":true,"branchCount":100,…}`, **no** new `events` row |
| 7 | `GET /api/codetracker/1/branches` with probe | existing 502 `Unable to fetch branches…`, no new row |
| 8 | `grep -rn 'tLog(__METHOD__' --include=*.php api` | 0 hits (was 9) |
| 9 | Event Viewer + Code Tracker screens in Chrome | ERROR rows render with file+route; grid loads; 0 console errors/warnings |
| 10 | `php -l` on the 4 touched files | *No syntax errors detected* ×4 |
| 11 | Event Viewer after the whole pass | only the forced ERROR rows + expected login AUDIT rows — **no** new Warning/Notice |

Regression suite: `Regression — Issue #1704` (11 cases) in `tmp/TLU_Test_Cases.md`, gated with
`TLU_REQUIRE_SUITE="Issue #1704" bash ai/verify_test_suites.sh` → **7 PASS / 0 FAIL**.

## Reusable checkers

```bash
# which tLog(__METHOD__) sites are really at FILE TOP LEVEL (the bug) vs inside a function
php /tmp/opencode/toplevel.php $(ls -d api/*/index.php)
# the mechanism itself
php -r 'echo strlen(__METHOD__), "\n";'   # -> 0 at file scope, 13+ inside a function
```
