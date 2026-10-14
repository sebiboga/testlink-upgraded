# Issue 1758 — Move/Reorder Test Suites: a malformed destination was silently turned into an in-container reorder

**Issue:** [#1758](https://github.com/sebiboga/testlink-upgraded/issues/1758)
**Branch:** `fix/issue-1758-malformed-destination` · **Fix commits:** `7d2906e9c`, `b13df1094`
**Screen:** `gui/templates/testcases/suiteMove.html` · **BFF:** `api/suitemove/index.php`
**Status:** VERIFIED FIXED (2026-10-14)

## Symptom

`POST ?action=move` with a malformed `new_parent_id` answered `200 ok` and moved the suite
**inside its current container** instead of reporting a bad request. A caller that passed a broken
destination got a silent, unintended reordering.

Two forms reproduced on the current default branch:

- **form-encoded float** — `new_parent_id=1.5` with `position=bottom` reordered the root children
  `[3,4] → [4,3]` and answered `200 {"status":"ok","changed":true}`;
- **JSON body** — `{"new_parent_id":"abc"}` did the same, because the guard read `$_REQUEST`, which
  does not carry a JSON body.

Related: `new_parent_id=3.9` answered `409 cycle "A suite cannot be moved inside itself"` and
`new_parent_id=1e3` / `75.5` answered `404 Destination not found` — all malformed values being
silently coerced to a *different* integer instead of refused with `400`.

## Root cause chain

All in `api/suitemove/index.php`:

| # | Site | Behaviour |
|---|---|---|
| 1 | `:714` `$newParentId = getInt('new_parent_id', 0);` | reads the destination |
| 2 | `:187-203` `getInt()` | accepts when `is_numeric($v)` (`:199`), then returns `intval($v)` (`:202`) — `is_numeric()` accepts floats and scientific notation, and `intval()` truncates: `1.5→1`, `3.9→3`, `75.5→75`, `1e3→1000`, `+5→5` |
| 3 | `:799` `if ($newParentId <= 0) {` | the raw-value guard only ran when `getInt()` had **already** collapsed the value to `≤ 0`; a non-integer numeric value truncated to a positive int never entered it |
| 4 | `:803` `$_REQUEST['new_parent_id']` | `$_REQUEST` is GET/POST/cookie; the endpoint contract is JSON I/O and reads the JSON body through `getParam()` (`:172-185`, `bffBody()` `:162-170`), so JSON callers skipped the guard entirely |
| 5 | `:817` `$newParentId = $oldParentId;` | when the guard did run, the destination fell back to the current parent; a truncated value equal to that parent (`1.5→1`) reached the in-container reorder at `:893-908` and answered `200 ok` |

### Why it survived the previous review

`13f2ca183` ("#1740: apply code-review fixes to Move/Reorder Test Suites") added the guard
for `new_parent_id`, but placed it *inside* the `≤ 0` fallback and read it from `$_REQUEST`.
`tmp/TLU_Test_Cases.md` T50 only exercised the form-encoded `abc` path (which that guard does
catch), so the residual class — numeric non-integers and JSON bodies — stayed uncovered and the
issue remained open.

## Fix

`api/suitemove/index.php`, `move` branch (`top`/`bottom`), replacing the guard that used to sit
inside the fallback:

```php
$rawDest = getParam('new_parent_id', '');
if (!is_string($rawDest) && !is_int($rawDest)) {
    out(array('status' => 'error', 'code' => 'bad_request',
              'message' => 'Invalid destination test suite'), 400);
}
$rawDest = trim((string)$rawDest);
if ($rawDest !== '' && !preg_match('/^\d+$/', $rawDest)) {
    out(array('status' => 'error', 'code' => 'bad_request',
              'message' => 'Invalid destination test suite'), 400);
}
if ($newParentId <= 0) {
    $newParentId = $oldParentId;
}
```

- the raw value is validated **unconditionally**, before `getInt()`'s truncation can hide it;
- read through the endpoint's own JSON-aware `getParam()` instead of `$_REQUEST`;
- the check rejects the **type** as well as the string form: a JSON `true` stringifies to `"1"`
  (which the digit regex would accept) but `getInt()` reads a boolean as `0`, i.e. it would still
  degrade into the silent in-container reorder; a JSON float (`1e3`, `4.0`) is likewise not the
  integer the caller named. Only a string of digits or a JSON int is accepted;
- `empty` / `"0"` / JSON `null` still mean "destination omitted" and keep the existing fallback;
  `up`/`down` still ignore the destination and `reorder` was untouched.

### Alternatives considered

| Option | Verdict |
|---|---|
| replace `getInt()` with a strict integer parser everywhere | rejected — `getInt()` is shared by every parameter of the endpoint and changing it would affect `tproject_id`, `node_id`, `container_id`, …; the destination is the only caller-supplied id that feeds a *write* destination |
| reject only when the raw value differs from the `intval` round-trip | rejected — equivalent in effect but harder to read; an explicit `/^\d+$/` states the contract |
| answer `404`/`409` for the coerced cases (status quo) | rejected — the issue asks for `400 bad_request`; `409 cycle` on `3.9` even leaked a plausible node id |

## Verification

Live, on `ab4318e46`, fixture `php tmp/fixtures_1759.php` (project `1`, root `1`, suites `3`/`4`):

| `new_parent_id` (node `3`, `position=bottom`) | Pre-fix | Post-fix |
|---|---|---|
| `abc` | `400` | `400 bad_request` |
| `-1` | `400` | `400 bad_request` |
| `1.5` | **`200 ok` + reorder `[3,4]→[4,3]`** | `400 bad_request`, order `[3,4]` |
| `3.9` | **`409 cycle`** | `400 bad_request` |
| `1e3` | **`404`** | `400 bad_request` |
| `75.5` | **`404`** | `400 bad_request` |
| `%2B5` / `1E3` / `0x4` / `4abc` | `404`/`409` | `400 bad_request` |
| JSON `"abc"` | **`200 ok` + reorder** | `400 bad_request`, order unchanged |
| JSON `1.5` | not caught | `400 bad_request` |
| JSON `true` | **`200 ok` + silent reorder** (`(string)true === "1"`) | `400 bad_request`, order unchanged |
| JSON `1e3` (float) | `404` | `400 bad_request` |
| JSON `4` (int) / JSON `null` | `200` | `200` / `200` fallback |
| `0` / absent | `200` fallback | `200` fallback |
| ` 4 ` / `4` | `200` | `200` |
| `new_parent_id[]=x` | `400` | `400` |
| `reorder` `75.5,76` / `1e3` | `400` | `400` |
| `events` ERROR/WARNING rows | — | `0` |

Suite `Regression — Issue #1758` in `tmp/TLU_Test_Cases.md`: **21/21 PASS / 0 FAIL**;
`TLU_REQUIRE_SUITE="Issue #1758" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL / 0 SKIP.

The bug is a BFF-contract bug; the screen (`suiteMove.html`) itself is unchanged and paints the
`400` through its existing error branch (`setNotice('err', r.message)`).

## Related

- #1740 — the modernized screen and BFF this issue was found while reviewing.
- [Bugfix-Issue-1790](Bugfix-Issue-1790-SuiteMove-Move-Path-404-Message-Oracle) — the sibling
  follow-up that hardened the two destination-not-found branches and the array-cast E_WARNING.
