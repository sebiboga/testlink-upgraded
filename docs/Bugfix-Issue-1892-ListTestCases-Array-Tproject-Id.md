# Issue #1892 — the `listTestCases.php` deep-link shim coerced an array-shaped id to test project **1**: `?tproject_id[]=7` dropped the caller into the wrong project with no diagnostic

**Issue:** [#1892](https://github.com/sebiboga/testlink-upgraded/issues/1892)
**Branch:** `fix/issue-1892` (fix + verification + docs of this run)
**Status:** VERIFIED-FIXED — regression suite `Issue #1892` 11/11 PASS, suite gate 7/7, no new Event-Viewer rows
**Related:** [#1731](Bugfix-Issue-1731-ReqMgrSystemEdit-Shim-Array-Request-E_WARNING.md) (the same
`shimReqInt()` idiom in the sibling shim), [#1732](Bugfix-Issue-1732-ListTestCases-Array-Feature-E_WARNING.md)
(the `shimReqScalar()` hardening of the *string* args in this very file that never reached the id args),
[#1660](https://github.com/sebiboga/testlink-upgraded/issues/1660) (the retired tree the shim redirects for).

## Symptom

An authenticated GET with an array-shaped id parameter was answered with a redirect into **test
project 1**, regardless of the value asked for:

```
GET /lib/testcases/listTestCases.php?feature=edit_tc&tproject_id[]=7
  -> 302 Location: /gui/templates/testcases/testSpec.html?tproject_id=1
GET /lib/testcases/listTestCases.php?feature=edit_tc&testproject_id[]=7
  -> 302 Location: /gui/templates/testcases/testSpec.html?tproject_id=1
```

The caller asked for test project 7 and was silently dropped into test project 1. No diagnostic was
emitted and **no `events` row** was written — the coercion is completely silent.

## Investigation (measured before any code was touched)

Environment: app `http://localhost:8082` (PHP 8.3.35 built-in server, docroot = repo root), DB
`testlink` freshly imported, `admin`/`admin` via curl session cookie (`POST login.php`). Test projects
1 and 7 both exist.

| probe | result |
|---|---|
| `?feature=edit_tc&tproject_id[]=7` | `302 → /gui/templates/testcases/testSpec.html?tproject_id=1` |
| `?feature=edit_tc&testproject_id[]=7` | `302 → /gui/templates/testcases/testSpec.html?tproject_id=1` |
| `?feature=edit_tc&tproject_id=7` (control) | `302 → …testSpec.html?tproject_id=7` |
| `php -r 'var_dump(intval(["7"]));'` | `int(1)` (warning-free) |
| `events` where `log_level IN (1,2)` | 0 rows (defect is silent) |

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | request `?tproject_id[]=7` | PHP parses it into `$_REQUEST['tproject_id'] = array('7')`; `isset()` is true for arrays. |
| 2 | `lib/testcases/listTestCases.php:90` | `if (isset($_REQUEST[$k]) && intval($_REQUEST[$k]) > 0)` → `intval(array('7')) === 1` (non-empty array → 1, no PHP 8 warning), guard `> 0` passes, `$qs['tproject_id'] = 1`. |
| 3 | `lib/testcases/listTestCases.php:95-97` | Same `intval($_REQUEST['testproject_id'])` for the legacy spelling. |
| 4 | `:116` + `:119` | Builds `…?tproject_id=1` and sends `302 Location: …testSpec.html?tproject_id=1`. |

**Why it breaks:** the file was already hardened for the *string* args (`feature`, `id`, `edit`) via
`shimReqScalar()` (lines 57-63, added by #1732), but the *integer* id args were never routed through a
shape guard. `intval()` on an array is warning-free in PHP 8 and invents the value `1` — exactly the
defect #1731 removed in the sibling `lib/reqmgrsystems/reqMgrSystemEdit.php:88-92`.

## Blast radius

`grep -n 'intval($_REQUEST' lib/testcases/listTestCases.php` → exactly the two raw sites above
(lines 90 and 96/97). The `feature`/`id`/`edit` args are already `is_scalar()`-guarded. The sibling
`reqMgrSystemEdit.php` shim already uses `shimReqInt()`; the `api/**` BFFs use `bffQueryInt()`.

Impact: wrong-context navigation only, on a crafted/malformed query string — no data write, no auth
bypass. Priority **minor**, as filed.

## The fix (minimal, 1 file, +13/-6)

Added `shimReqInt()` next to the existing `shimReqScalar()` (identical idiom to
`reqMgrSystemEdit.php:88-92`) and routed the five id params in the loop plus the legacy
`testproject_id` spelling through it:

```php
function shimReqInt($name)
{
    $v = shimReqScalar($name);
    return ($v === null || !is_numeric($v)) ? 0 : intval($v);
}

$qs = array();
foreach (array('tproject_id', 'tplan_id', 'tcase_id', 'container_id', 'idSRS') as $k) {
    $v = shimReqInt($k);
    if ($v > 0) {
        $qs[$k] = $v;
    }
}
// legacy tree arg spelling was testproject_id
if (!isset($qs['tproject_id'])) {
    $v = shimReqInt('testproject_id');
    if ($v > 0) {
        $qs['tproject_id'] = $v;
    }
}
```

An array-shaped id now yields `0` = "no context" (the exact semantic the sibling shim uses), so the
malformed parameter is **dropped**, never guessed as `1`.

### Why this method (and what was rejected)

* **HTTP 400 refusal for non-scalar ids.** Rejected: this file is a *bookmark redirector* whose
  contract is "never 500, always 302 somewhere sane". Dropping a malformed context parameter is the
  least-surprising behaviour and matches the `reqMgrSystemEdit.php` precedent (issue #1892's own
  "Expected" section names that exact idiom).
* **Guessing the project from `array('7')`'s first element.** Rejected outright — that is the
  "refusing to guess" principle the shim already applies to an unknown `feature`.
* **Reusing `shimReqScalar` + `is_numeric` inline at both sites.** Rejected in favour of a named
  helper so the two sites cannot drift and the idiom is byte-identical to the already-approved sibling
  `shimReqInt()`.

No user-facing string changed (the shim emits only redirects and a pre-existing log line for unknown
features), so **no i18n keys were added**.

## After the fix

`?tproject_id[]=7` and `?testproject_id[]=7` now redirect to the modern Test Specification **without**
a `tproject_id` in the Location (context dropped) — never to project 1. Valid scalar deep links are
byte-for-byte unchanged, and the `events` table stays empty of Error/Warning rows.

## Verification

Regression suite `Issue #1892`, appended to `tmp/TLU_Test_Cases.md`; suite gate
`TLU_REQUIRE_SUITE="Issue #1892" bash ai/verify_test_suites.sh` → 7/7 PASS.

| case | before | after |
|---|---|---|
| `?feature=edit_tc&tproject_id[]=7` | `…testSpec.html?tproject_id=1` | `…testSpec.html` (no context) |
| `?feature=edit_tc&testproject_id[]=7` | `…testSpec.html?tproject_id=1` | `…testSpec.html` (no context) |
| `?feature=edit_tc&tproject_id=7` | `…?tproject_id=7` | unchanged |
| `?feature=edit_tc&tproject_id=0` | no context | unchanged |
| `?feature=edit_tc&tproject_id=abc` | no context | unchanged |
| `?feature=edit_tc&tplan_id[]=9&tproject_id=7` | — | `…?tproject_id=7` (array `tplan_id` dropped, scalar kept) |
| `?feature=keywordsAssign&id[]=3` | no `id` | unchanged (already guarded) |
| `?feature=keywordsAssign&tproject_id=7&id=3&edit=testcase` | full context | unchanged |
| `?feature[]=x` (array feature) | 302 → testSpec (default) | unchanged (pre-existing #1732) |
| `php -l lib/testcases/listTestCases.php` | — | No syntax errors detected |
| `SELECT COUNT(*) FROM events WHERE log_level IN (1,2)` | 0 | 0 (no new Error/Warning) |

## How to re-test in one command

```bash
# login admin/admin via curl session cookie, then:
curl -s -b c.jar -D- -o /dev/null \
  'http://localhost:8082/lib/testcases/listTestCases.php?feature=edit_tc&tproject_id%5B%5D=7'
# expect: HTTP/1.1 302 Found  +  Location: /gui/templates/testcases/testSpec.html
#   (NO '?tproject_id=1' — the malformed context is dropped)
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2);"   # expect 0
```
