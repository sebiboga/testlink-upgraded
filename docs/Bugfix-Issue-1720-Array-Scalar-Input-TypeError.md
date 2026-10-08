# Bugfix: array-shaped value for a scalar input parameter raises an uncaught PHP 8 `TypeError` (Issue #1720)

**Issue**: `GET /lib/requirements/reqEdit.php?req_title[]=x` (or any legacy URL where a
`tlInputParameter::STRING_N` / `INT` / `INT_N` / `CB_BOOL` parameter is sent with the PHP
array syntax `?k[]=x`) answered **HTTP 500 with a 0-byte body and zero `events` rows**.
The request died inside the shared input filter, before any controller code ran.

## Root cause

Chain (each hop `file:line`, verified on `HEAD` before the fix):

1. `lib/general/staticPage.php:60` declares `key` as `tlInputParameter::STRING_N`
   (same shape: `lib/requirements/reqEdit.php:59` `req_title`).
2. `lib/functions/inputparameter.inc.php:178` — `GPR_PARAM_STRING_N()` is called.
3. `:227-228` — `new tlParameterInfo(...)` + `new tlInputParameter($pInfo,$vInfo)`.
4. `lib/functions/inputparameter.class.php:85` → `normalize()`
   (`:170`) → `tlStringValidationInfo::normalize()` (`:295`) → `trim()` (`:330`).
5. PHP 8 materialises `?key[]=x` as an **array** in `$_GET`/`$_POST`/`$_REQUEST`;
   `trim(array)` throws `TypeError: trim(): Argument #1 ($string) must be of type
   string, array given`.
6. `lib/functions/inputparameter.inc.php:230` — the only guard is
   `catch (Exception $e)`. `TypeError extends Error implements Throwable`, **not**
   `Exception`, so the handler never fires; the `Error` escapes as a fatal.
   Because the failure happens before `tLog()`, no `events` row is written.

`tlIntegerValidationInfo::normalize()` (`:411`, `intval(trim($value))`) and
`tlCheckBoxValidationInfo::normalize()` (`:516`, `trim($value)`) carry the identical
unguarded shape.

**Latent since PHP 8**: on PHP 5/7 `trim()` only warned, so the old `catch (Exception)`
was never the failing link. The `?k[]=x` syntax has always produced an array.

**Blast radius**: every controller that reads a scalar parameter through
`I_PARAMS()` / `R_PARAMS()` / `P_PARAMS()` / `G_PARAMS()` — i.e. essentially all legacy
screens. Sibling manifestations were already worked around per screen
(`shimReqScalar()` in `lib/reqmgrsystems/reqMgrSystemEdit.php:80`, Ref #1731;
`reqSpecEditDropShapedAction()` in `lib/requirements/reqSpecEdit.php:168`, Ref #1736),
which is exactly the duplication a shared-layer fix removes.

## Fix (approach)

Two changes, both in the shared input layer:

1. **`lib/functions/inputparameter.class.php` — `tlInputParameter::fetchParameter()`.**
   When the fetched value is an array and a validation type is present that is not a
   `tlArrayValidationInfo`, mark the parameter as *not fetched* with value `null`:

   ```php
   if (is_array($value) && $this->validationInfo !== null
       && !($this->validationInfo instanceof tlArrayValidationInfo))
   {
       $value = null;
       $fetched = false;
   }
   ```

   `fetchParameter()` is the single point every scalar type passes through, so one
   guard closes STRING_N / INT / INT_N / CB_BOOL at once. ARRAY_INT and
   ARRAY_STRING_N (`tlArrayValidationInfo`) keep receiving arrays unchanged.

2. **`lib/functions/inputparameter.inc.php:230` — widen the catch.**
   `catch (Exception $e)` → `catch (Throwable $e)` at the `GPR_PARAM_STRING_N`
   construction site. This is the defect named in the issue title and acts as
   defense-in-depth for any other `Error` raised while building the parameter object.

**Why "reads as absent" (null) rather than coercing to `''`**: it is the contract the
existing screen-level workarounds already assume (`shimReqScalar()` returns `null` for a
non-scalar), and it lets the min/max-length validators behave as for a missing value
instead of accepting an empty string.

**Rejected alternatives**: changing only the `catch` (would convert the 500 into the
legacy `echo`+`exit()` path but leave `intval(trim(...))` and `trim()` still reaching
the array); a global `set_exception_handler` (too broad for a one-parameter defect);
per-screen `is_scalar()` guards (the N-times duplication the issue already laments).

## Files changed

- `lib/functions/inputparameter.class.php` — `fetchParameter()` non-scalar guard
  (+11 lines, guard + comment).
- `lib/functions/inputparameter.inc.php` — `catch (Exception)` → `catch (Throwable)`
  (+1/-1).
- `docs/screenshots/issue-1720-staticpage-array-param.png` — crafted URL renders the
  graceful `Error: Invalid page parameter.` (was a 0-byte 500).

## Verification

- `php -l` clean on both files.
- Before/after on the input layer (pristine `HEAD` copy in `/tmp/before`):
  `R_PARAMS(["key"=>STRING_N])` with `$_REQUEST["key"]=array("x")` →
  `UNCAUGHT TypeError: trim() ... array given` **before**, value `NULL` **after**.
- Live HTTP: `GET /lib/general/staticPage.php?key[]=x` → **500 / 0 bytes before**,
  **200 / 30 bytes** (`Error: Invalid page parameter.`) **after**;
  well-formed `?key=foo` still renders (200 / 3127 bytes).
- No `trim(): Argument` occurrences added to `tmp/php_server.log`; Event Viewer delta
  for the crafted URLs = 0 rows.
- Regression suite `Regression — Issue #1720` (12 cases) 12/12 PASS, suite gate 7/7
  (`bash ai/verify_test_suites.sh`).
- No user-facing string added → no i18n bundle touched.

## Related

- New bug found while testing, filed separately as **#1884**: `planUrgency.php` without a
  valid `tplan_id` answers 200 but writes 5-7 `E_WARNING` rows per request (independent of
  this fix).
