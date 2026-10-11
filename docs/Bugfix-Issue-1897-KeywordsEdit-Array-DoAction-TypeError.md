# Bugfix: array-shaped `doAction` on `keywordsEdit.php` raised a `TypeError` → HTTP 500 (Issue #1897)

**Issue**: `GET /lib/keywords/keywordsEdit.php?doAction[]=x` answered **HTTP 500 with a blank
body** while the scalar control `?doAction=x` answered the normal **302**. This is the
array-shaped twin of #1886 (`planMilestonesEdit.php`), #1731 (`reqMgrSystemEdit.php`) and the
#1738 root-cause class (array-typed input parameter handed to a `string`-typed PHP function).

## Root cause

Chain on the pre-fix revision:

1. `lib/keywords/keywordsEdit.php:34` — `testlinkInitPage($db)` authenticates the session and
   returns; the crash is *after* the auth gate, so any authenticated user can reach it.
2. `lib/keywords/keywordsEdit.php:66` —
   ```php
   $doAction = isset($_REQUEST['doAction']) ? trim($_REQUEST['doAction']) : '';
   ```
   `isset()` is **true** for `?doAction[]=x` (a non-null array), so the array is passed to
   `trim()`.
3. `trim()` declares `string $string`, so PHP 8 throws
   `TypeError: trim(): Argument #1 ($string) must be of type string, array given`.
   Uncaught → **HTTP 500**, blank body.
4. Lines 82 (`in_array($doAction, ...)`) and 158 (`switch ($doAction)`) are never reached.

`keyword` / `notes` were already hardened two lines below (`lib/keywords/keywordsEdit.php:68-73`,
the `is_array()` casts) — `doAction` was the one read left unguarded in the file.

## Fix (approach)

Mirror the #1886 remedy (`planMilestonesEdit.php:66`): an `is_scalar()` guard that ignores a
non-scalar action so the request falls through to the normal GET redirect.

```php
$doAction = isset($_REQUEST['doAction']) && is_scalar($_REQUEST['doAction'])
	? trim((string)$_REQUEST['doAction']) : '';
```

Why this method:
- **Minimal** — one guarded read, no control-flow change.
- **Behaviour-preserving for scalars** — `trim((string)$x)` is exactly the legacy `trim($x)`
  for every scalar (keeps the whitespace trim that the `switch` on `edit`/`cfl` relies on),
  so the `do_create` / `do_update` / `do_delete` / `do_cfl` write paths and the `edit` / `cfl`
  GET dispatchers are byte-for-byte unchanged.
- Alternatives rejected: casting blindly (`(string)$_REQUEST['doAction']`) would turn an array
  into the string `"Array"` and let it flow into the switch/`in_array`; rejecting with a
  dedicated HTTP 400 error page is more code than the legacy shim's graceful redirect needs and
  diverges from the sibling files' established pattern.

## Files changed

- `lib/keywords/keywordsEdit.php` (1 guarded read, +7/−1 with comment).
- `docs/Bugfix-Issue-1897-KeywordsEdit-Array-DoAction-TypeError.md` (this mirror).
- wiki page `Bugfix-Issue-1897-KeywordsEdit-Array-DoAction-TypeError.md`.
- `tmp/TLU_Test_Cases.md` — `Regression — Issue #1897` suite.

## Verification

- **Pre-fix** (branch before the edit): `?doAction[]=x` → **HTTP 500**; `?doAction=x` → **302**;
  the exact expression isolated on the CLI reproduces
  `trim(): Argument #1 ($string) must be of type string, array given`.
- **Post-fix**:
  - `?doAction[]=x` → **302** to
    `gui/templates/keywords/keywordsEdit.html?mode=create&tproject_id=…` (byte-identical to the
    scalar control).
  - `?doAction=x` → 302 `mode=create`; `?doAction=edit&id=999` → 302 `mode=edit`;
    `?doAction=cfl&tcversion_id=5` → 302 `mode=cfl`.
  - POST `doAction[]=do_create&keyword=x` → 302 (safe, no write);
    POST `doAction=do_create&tproject_id=1` (project fixture) → **303**, keyword row created;
    `events` shows only the audit `log_level=16` rows, **0** new Error/Warning.
  - Browser (chrome-devtools, admin session): `?doAction[]=x&tproject_id=1` lands on the modern
    *Keyword Management* popup, 0 console errors.
  - `php -l lib/keywords/keywordsEdit.php` → no syntax errors.
- Regression suite `Regression — Issue #1897` (12 cases) 12/12 PASS; suite gate 7/7
  (`TLU_REQUIRE_SUITE="Issue #1897" bash ai/verify_test_suites.sh`).
- No user-facing string added → no i18n bundle touched.

## Related / follow-ups

- #1731 (`reqMgrSystemEdit.php`), #1738 (array-typed input parameter root cause),
  #1886/#1894 (`planMilestonesEdit.php`), and the shim-hardening family
  (#1892 `listTestCases.php`, #1893 `searchMgmt.php`).
- A grep for the same shape (`trim($_REQUEST[...])` with a bare `isset()`) still finds other
  legacy reads such as `lib/project/projectView.php:53` and
  `lib/functions/common.php:1725`; those are separate screens, out of scope here, and belong to
  their own tickets.
