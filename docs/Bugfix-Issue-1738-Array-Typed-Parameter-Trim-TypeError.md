# Bugfix: array-shaped value for an input parameter raises an uncaught PHP 8 `TypeError` through `trim()` (Issue #1738)

**Issue**: an array-shaped query/body value (`?k[]=x`) for an input parameter reaches PHP's
string/number functions and raises an uncaught `TypeError` → **HTTP 500, 0-byte body, zero
`events` rows** (no observable log trail). The shared input layer is used by *every* TestLink
controller through `R_PARAMS()` / `P_PARAMS()` / `G_PARAMS()`, so one bracketed parameter name is
enough to kill a request from any screen.

## Root cause

Chain (each hop `file:line`, verified on `HEAD` before the fix):

1. PHP materialises `?k[]=x` (and `?k[][]=x`) as an **array** in `$_GET` / `$_POST` / `$_REQUEST`.
2. `lib/functions/inputparameter.class.php:153` — `fetchParameter()` reads the raw value.
3. `lib/functions/inputparameter.class.php:162-167` — the guard added for #1720 drops an array
   **only when the validation type is a scalar one**; it deliberately keeps arrays for
   `tlArrayValidationInfo` (`ARRAY_INT` / `ARRAY_STRING_N`).
4. `lib/functions/inputparameter.class.php:308` → `tlStringValidationInfo::normalize()` →
   `trim()` (`:330`): `ltrim()`/`rtrim()`/`trim()`/`tlSubStr()` have **no type guard**.
5. `lib/functions/inputparameter.class.php:424` → `tlIntegerValidationInfo::normalize()` →
   `intval(trim($value))`: same unguarded shape.
6. `lib/functions/inputparameter.class.php:501` → `tlArrayValidationInfo::normalize()` calls the
   *member* validator's `normalize()`; a nested array element still reaches steps 4/5.
7. `TypeError extends Error` — it is **not** an `Exception`, so the `catch (Exception)` /
   `catch (Throwable)` guards at the call sites do not turn it into a graceful message; the
   failure happens before `tLog()`, hence **0** `events` rows.

**Why it breaks now**: on PHP 5/7 `trim(array)` only emitted a warning and coerced; PHP 8 turns
it into a fatal `TypeError`. The #1720 fix closed the scalar case one level above the root cause;
the guard the issue itself asked for (in `trim()`) was never added, so nested array-typed
parameters remained fatal.

## Fix (approach)

Add the `is_scalar()` guard the issue suggested at the three sites that hand a value to PHP's
string/int functions. `null` is returned for a non-null non-scalar, so the parameter (or the
array member) reads as **absent** — matching the contract the existing per-screen workarounds
(`shimReqScalar()` in `lib/reqmgrsystems/reqMgrSystemEdit.php`, `reqSpecEditDropShapedAction()` in
`lib/requirements/reqSpecEdit.php`) already assume.

1. **`tlStringValidationInfo::trim()`** (`:330`) — `if ($value !== null && !is_scalar($value)) return null;`
   before the `switch`. Makes `trim()` itself never fatal for *every* caller, including direct /
   out-of-tree construction and array members.
2. **`tlIntegerValidationInfo::normalize()`** (`:430`) — the same guard before `intval(trim($value))`.
3. **`tlArrayValidationInfo::normalize()`** (`:501`) — `unset()` a non-scalar member so it never
   reaches the member normalizer (and the member validator never throws a spurious validation
   error for it).

**Why "reads as absent" (null) rather than coercing to `''`**: `''` would silently pass the
min/max-length validators; `null` is what the existing workarounds already return.

**Rejected alternatives**: a fourth fetch-layer guard (would again miss nested members and any
direct `tlInputParameter` construction — the duplication the issue already laments); a global
`set_exception_handler` (too broad for a parameter-shape defect).

## Files changed

- `lib/functions/inputparameter.class.php` — `is_scalar()` guards in
  `tlStringValidationInfo::trim()`, `tlIntegerValidationInfo::normalize()` and
  `tlArrayValidationInfo::normalize()` (+21 lines).

## Verification

- `php -l lib/functions/inputparameter.class.php` → clean.
- Shared-layer CLI matrix: scalar `STRING_N` / `INT` / `INT_N` / `CB_BOOL` with `?k[]=x` → `null`
  (unchanged, already fixed by `60b823d8d`); `ARRAY_INT` nested `?k[][]=x` →
  `TypeError … trim(): Argument #1 … array given` **before**, `array()` **after**;
  `ARRAY_STRING_N` nested no longer fatal.
- Live HTTP (temporary docroot probe, removed after the test):
  `?doAction=hi&nested[]=7` → 200 `{hi,[7]}`; `?doAction[]=x` → 200 `{null,null}`;
  `?nested[][]=x` → 200 `{null,[]}`; `?doAction=++spaced++&nested[]=1&nested[]=2` → 200
  `{"spaced",[1,2]}`. No 0-byte 500.
- `events` rows with `log_level<=2`: **0 before → 0 after** (no new Error/Warning).
- Regression suite `Regression — Issue #1738` (14 cases) 14/14 PASS; suite gate 7/7
  (`bash ai/verify_test_suites.sh`).
- No user-facing string added → no i18n bundle touched.

## Related

- #1720 — the scalar-parameter manifestation of the same defect (fixed at the fetch layer by
  `60b823d8d` / `aa53eab6e`); this issue completes it at the `trim()` root cause.
- #1731 (`reqMgrSystemEdit.php`), #1732 (`listTestCases.php`), #1736 (`reqSpecEdit.php`) —
  per-screen `is_scalar()` workarounds for the same shape.
