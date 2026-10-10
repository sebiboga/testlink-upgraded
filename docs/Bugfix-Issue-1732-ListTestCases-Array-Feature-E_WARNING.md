# Issue #1732 — the `listTestCases.php` deep-link shim cast `$_REQUEST['feature']` without a shape check: `?feature[]=x` wrote an E_WARNING **and** an ERROR row into `events` on every request

**Issue:** [#1732](https://github.com/sebiboga/testlink-upgraded/issues/1732)
**Fix commit:** `6aac696c6` (`fix(opencode): leftover changes from bug-fix run`) — already on the default branch `sebiboga`
**Branch:** `fix/issue-1732-listtestcases-array-feature` (verification + docs of this run)
**Status:** VERIFIED-FIXED — regression suite `Issue #1732` 8/8 PASS, suite gate 7/7
**Related:** [#1731](Bugfix-Issue-1731-ReqMgrSystemEdit-Shim-Array-Request-E_WARNING.md) (the same
defect family in `reqMgrSystemEdit.php`), [#1660](https://github.com/sebiboga/testlink-upgraded/issues/1660)
(the retired tree the shim redirects for), [#1892](https://github.com/sebiboga/testlink-upgraded/issues/1892)
(sibling silent `intval()` coercion in the same file, filed not fixed),
[#1893](https://github.com/sebiboga/testlink-upgraded/issues/1893) (same-family live site found in
`searchMgmt.php` while re-triaging the blast radius, filed not fixed).

## Symptom

One crafted GET against the legacy deep-link redirect shim wrote **two** `events` rows, repeatably,
with no cap:

```
log_level 2 | E_WARNING
             Array to string conversion - in .../lib/testcases/listTestCases.php - Line 52
log_level 1 | listTestCases shim: unknown feature "Array" - refusing to guess a modern target (Refs #1660).
```

HTTP response pre-fix: `400 Bad Request`. 5 requests produced 10 rows (issue-body measurement);
re-measured in this run: 1 request → 2 rows.

## Investigation (measured before any code was touched)

Environment: app `http://localhost:8082` (PHP built-in server, docroot = repo root), PHP 8.3.35,
DB `testlink` freshly imported, `admin`/`admin` via curl session cookie (`POST login.php`).
Pre-fix reproduction used an isolated `git worktree` of `6aac696c6^` served on a second PHP built-in
server (127.0.0.1:8099) against the same DB, so the main working tree was never modified.

| probe | result |
|---|---|
| `events` log_level in (1,2) before the repro | 0 |
| pre-fix `GET ?feature[]=x` (worktree server) | HTTP 400 |
| `events` log_level in (1,2) after | **2 rows** (E_WARNING at Line 52 + shim ERROR with `"Array"`) |
| current HEAD `GET ?feature[]=x` (http://localhost:8082) | HTTP 302 → `/gui/templates/testcases/testSpec.html` |
| `events` full-table count around the HEAD request | +0 rows |

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | request `?feature[]=x` | PHP parses it into `$_REQUEST['feature'] = array('x')`; `isset()` returns true for arrays. |
| 2 | `lib/testcases/listTestCases.php:52` (pre-fix) | `$feature = isset($_REQUEST['feature']) ? (string)$_REQUEST['feature'] : 'edit_tc';` → `(string)` of an array raises `E_WARNING Array to string conversion` (persisted `log_level=2` by `watchPHPErrors`) and yields the literal `"Array"`. |
| 3 | `lib/testcases/listTestCases.php:79` | `isset($targets['Array'])` is false → unknown-feature branch → `tLog(..., 'ERROR')` (`log_level=1`) with the coerced text, HTTP 400. |

**Why it breaks / persistence of the defect:** pre-existing validation gap, not a recent regression —
the shim (Refs #1660) redirected legacy deep links and never validated that `feature` was scalar
before casting. Same family as the #1731 fix (bare `(string)$_REQUEST[...]` reachable from any
authenticated session; the shim has no rights check beyond `checkSessionValid()`).

## Blast radius

`grep -rEn '\(string\)\$_REQUEST\[' --include=*.php lib gui` at HEAD returns **8 hits in 6 files**
(the issue body's "4 hits in 3 files" predated the #1731/#1732 guards, which themselves added 3
guarded hits). Triaged individually:

| File:line | Guarded? / reachable? | Status |
|---|---|---|
| `lib/testcases/listTestCases.php:62` | inside `shimReqScalar()` — casts only after `is_scalar()` | not a defect (this fix) |
| `lib/testcases/listTestCases.php:110` | guarded by `is_scalar($_REQUEST['edit'])` at :108 | not a defect |
| `lib/reqmgrsystems/reqMgrSystemEdit.php:85` | inside `shimReqScalar()` — casts only after `is_scalar()` | not a defect (fixed in #1731) |
| `lib/usermanagement/usersEdit.php:65` | guarded by `is_scalar($_REQUEST[$key])` at :64 | not a defect |
| `lib/keywords/keywordsEdit.php:71,73` | reported not reachable in #1732 (302 before the cast, 0 rows) | not a defect |
| `lib/execute/execNotes.php:34` | bare cast, but **probed: 0 rows persisted** (this shim's early path does not arm the DB logger before the cast) | not an event-pollution defect |
| `lib/search/searchMgmt.php:24` | bare cast, **live: +1 `log_level=2` row per request** — measured in this run | **filed as #1893** |

The issue's stated scope (the four originally-triaged sites) is complete after this fix; the
re-triage surfaced one additional live same-family site (`searchMgmt.php:24`), filed as
[#1893](https://github.com/sebiboga/testlink-upgraded/issues/1893). The parallel
`intval($_REQUEST[...])` coercion (lines 90, 95-96; `?tproject_id[]=7` → 1) writes **no** event rows,
so it is a distinct silent-coercion defect, filed as **#1892** and not fixed here.

## The fix (minimal, 1 file, +16/-1 — landed in `6aac696c6`)

`lib/testcases/listTestCases.php:57-67` reads `feature` through the same `is_scalar()`-guarded
`shimReqScalar()` idiom already established in the repo (reqMgrSystemEdit.php:80-86,
attachmentdelete.php:33-38, `bffQueryScalar()` in api/reqmgrsystemedit):

```php
function shimReqScalar($name)   // null when absent OR array-shaped, else the trimmed string
{
    if (!isset($_REQUEST[$name]) || !is_scalar($_REQUEST[$name])) {
        return null;
    }
    return trim((string)$_REQUEST[$name]);
}

$rawFeature = shimReqScalar('feature');
$feature = ($rawFeature === null || $rawFeature === '') ? 'edit_tc' : $rawFeature;
```

A present-but-non-scalar feature never becomes a string — the coercion `"Array"` is never built, the
unknown-feature branch never fires, the E_WARNING is never raised, and no attacker-controlled text
reaches the log. The request falls through to the **legacy default `edit_tc`** → clean 302 to the
modern Test Specification.

### Why this method (and what was rejected)

* **HTTP 400 refusal for non-scalar `feature`** (prior root-cause plan's draft). Rejected: this file
  is a *bookmark redirector*; its contract is "never 500, always 302 somewhere sane", and the family
  precedent (reqMgrSystemEdit.php:94-102) deliberately 302s garbage input to a sane target. The array
  case degrades to the documented legacy default for a MISSING feature — a malformed feature argument
  is treated exactly as absent, no new code path, no new response shape.
* **Guessing an intended target from array contents.** Rejected outright — that is what the
  unknown-branch's "refusing to guess a modern target" exists to prevent.
* **Downgrading the unknown-*scalar* refusal from ERROR to INFO.** Kept as ERROR, unchanged
  (`?feature=unknown` → 400 + 1 ERROR row): a scalar unknown feature is a genuine client mistake
  worth surfacing, and the sibling shim logs unknown scalar `doAction` the same way
  (reqMgrSystemEdit.php:163-167). Only the array-shaped coercion, which logged rows *by accident*, had
  to stop.
* **Reusing `shimReqScalar`** rather than inventing a new guard: makes the fix obviously equivalent
  to the already-approved BFF/shim hardening a reviewer knows.

No user-facing string changed (the shim emits only redirects and log lines), so **no i18n keys were
added**.

## After the fix

`?feature[]=x` (and any array-shaped feature) is treated as a missing feature → 302 to the modern Test
Specification with **zero** Event-Viewer rows of any level. Every valid legacy feature still forwards
exactly as before.

One intentional, documented behavior delta: an **empty/whitespace** feature (`?feature=`,
`?feature=%20`) is now also treated as missing and defaults to `edit_tc` → 302 (pre-fix it coerced to
`''` → 400 + 1 ERROR row). This is the same "empty ⇒ default" contract the legacy `$feature` fallback
always used for an absent parameter, applied consistently to the empty-string case; it removes a
second way to write an ERROR row with no real feature value. Arrays and empty values can no longer
reach the unknown branch at all. (Regression case TC-1732-09.)

## Verification

Regression suite `Issue #1732`, appended to `tmp/TLU_Test_Cases.md`; suite gate
`TLU_REQUIRE_SUITE="Issue #1732" bash ai/verify_test_suites.sh` → 7/7 PASS.

| case | before | after |
|---|---|---|
| `?feature[]=x` | 400, **+2 rows** (WARNING + ERROR) | 302 → testSpec.html, **+0 rows** |
| `?feature=edit_tc` | 302 → testSpec.html | unchanged |
| `?feature=keywordsAssign&tproject_id=1&id=5&edit=testcase` | 302 → keywordsAssign.html?tproject_id=1&id=5&edit=testcase | unchanged |
| `?feature=assignReqs` | 302 → reqTcBulkAssign.html | unchanged |
| (no feature) | 302 → testSpec.html (edit_tc default) | unchanged |
| `?feature=unknown` (scalar) | 400 + 1 ERROR row | unchanged (intentional refusal semantics) |
| 3 × `?feature[]=x` | 400, 6 rows | 302, **+0 rows** (no growth) |
| `?feature=` / `?feature=%20` (empty) | 400 + 1 ERROR row | 302 → testSpec.html, +0 rows |
| `?feature[]=x&tplan_id=2` | 400, +2 rows | 302 → testSpec.html?tplan_id=2, +0 rows (scalar params still forwarded) |
| `php -l lib/testcases/listTestCases.php` | — | No syntax errors detected |
| browser end-to-end | — | legacy link `?feature[]=x` lands on modern Test Specification; Event Viewer clean (0 log_level 1/2 rows) |

## How to re-test in one command

```bash
# login admin/admin via curl session cookie, then:
curl -s -b c.jar -D- -o /dev/null \
  'http://localhost:8082/lib/testcases/listTestCases.php?feature%5B%5D=x'
# expect: HTTP/1.1 302 Found  +  Location: /gui/templates/testcases/testSpec.html
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2);"   # expect 0
```
