# Issue 460 — `updateTestSuiteCustomFieldDesignValue()`: early `return` inside the custom-fields loop drops every field after the first

**Issue:** [#460](https://github.com/sebiboga/testlink-upgraded/issues/460)
**Branch:** `fix/issue-460` · **Commit:** `7b8a7dd35` (fix, +2/-2)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

XMLRPC `tl.updateTestSuiteCustomFieldDesignValue` accepts a `customfields`
map ("key: Custom Field Name, value: value") and documents an
`array of {status,msg}` per field as its return contract. In reality only
the **first** entry of the map was ever processed:

```
request : customfields = {"CF_ALPHA":"value-A", "CF_BETA":"value-B"}
response: [{'status': 'ok', 'msg': 'Custom Field:CF_ALPHA processed '}]
db      : SELECT * FROM cfield_design_values WHERE node_id=2  ->  one row only (10|value-A)
```

Worse, when the *first* map entry was an unlinked/unknown field name the
loop returned the `ko` entry immediately and never even attempted valid
entries placed after it (`{"UNKNOWN_CF":"x","CF_ALPHA":"second-never-run"}`
left `CF_ALPHA` untouched).

Reproduced live against `http://localhost:8082` (PHP 8.3): fixtures created
through the API + SQL (project id=1 `Proj460`, suite id=2 `Suite460`,
string CFs `CF_ALPHA` id=10 / `CF_BETA` id=11 linked to test suites at
design time). The issue was originally reported from code reading; this run
confirmed it functionally.

## Root cause chain

1. `lib/api/xmlrpc/v1/xmlrpc.class.php:8288-8292` — after auth/checks pass,
   `foreach ($cfSet as $cfName => $cfValue)` iterates the requested fields.
2. `:8297-8311` — each iteration writes via
   `cfield_mgr->design_values_to_db()` and appends an `ok` entry to `$ret`,
   or appends a `ko` "skipped" entry.
3. `:8313` — `return $ret;` is the **last statement inside the foreach
   body**, so iteration 2 can never run. One call == at most one field.
4. `$ret` was also never initialized before the loop.
5. Upstream TestLink 1.9.20 defect carried over verbatim into 2.0.1 — not a
   modernization regression.

## Blast radius

- Single method: grep over the repo shows no other PHP call sites
  (XMLRPC dispatch only, line 9216).
- Sibling `updateBuildCustomFieldsValues()` (same file, loop at
  8386-8407) is correct: return sits after the loop — used as reference.
- Any API client batching multiple test-suite CF updates in one call.

## Approach — mirror the correct sibling method (minimal)

In `updateTestSuiteCustomFieldDesignValue()`:

1. Initialize `$ret = array();` right after `$itemID = ...`
   (parity with line 8385) — prevents a PHP 8.3 `E_WARNING Undefined
   variable` once the return moves out and an empty map is passed.
2. Move `return $ret;` from inside the foreach to directly after its
   closing brace — byte-identical shape to `updateBuildCustomFieldsValues()`.

Rejected alternatives:
- rewriting the loop with per-field try/catch or transaction handling
  (out of scope, changes semantics);
- deprecating the method in favor of a REST BFF endpoint (product decision,
  not a bug fix).

## Verification (Suite 460 — all PASS, see tmp/TLU_Test_Cases.md)

| Case | Result |
|------|--------|
| R1 two valid fields | `[ok CF_ALPHA, ok CF_BETA]`; rows `10→A1`, `11→B1` written |
| R2 unknown first + valid second | `[ko UNKNOWN_CF skipped, ok CF_ALPHA]`; valid row updated |
| R3 single valid field | unchanged legacy behavior |
| R4 empty customfields map | clean `[]`, no warning |
| R5a missing `customfields` param | code 200 required-param error unchanged |
| R5b nonexistent project | code 7000 unchanged |
| R5c invalid devKey | code 2000 unchanged |
| Event Viewer | no new Error/Warning entries during the whole suite |

`php -l lib/api/xmlrpc/v1/xmlrpc.class.php` → clean before commit.
