# Bugfix Issue #704: Undefined Variables, Wrong Return, Debug Echo

## Summary

Four PHP bugs fixed across `lnl.php`, `linkto.php`, `lostPassword.php`, and `firstLogin.php`:

1. **lnl.php:129** — undefined `$key` variable in default switch case
2. **linkto.php:243** — `init_args()` returns boolean instead of args object
3. **lostPassword.php:53** — undefined `$note` variable
4. **firstLogin.php:143** — debug `echo` left in production code

## Fixes

### Bug 1: lnl.php — undefined `$key`

**Root cause:** In the `default:` case of `switch($args->type)`, line 129 used `$key` which was never defined. The correct variable is `$args->type`.

**Fix:** `strpos($key, $needle)` → `strpos($args->type, $needle)`

**File:** `lnl.php:129`

### Bug 2: linkto.php — boolean return from init_args()

**Root cause:** When a malformed testcase URL (no glue character) is provided, `init_args()` returned `$args->status_ok` (a boolean) instead of the `$args` object. All downstream code expects an object with properties.

**Fix:** `return $args->status_ok;` → `return $args;`

**File:** `linkto.php:243`

### Bug 3: lostPassword.php — undefined `$note`

**Root cause:** Line 53 referenced bare `$note` which was never defined. At line 43, `$gui->note` was set from `$result['msg']`. The condition should check `$gui->note`.

**Fix:** `if ($note != "")` → `if ($gui->note != "")`

**File:** `lostPassword.php:53`

### Bug 4: firstLogin.php — debug echo

**Root cause:** `echo '<br>' . __LINE__;` was left in production code, outputting debug HTML on every execution of that code path.

**Fix:** Remove the debug echo line.

**File:** `firstLogin.php:143`

## Commit

- `2492d763c` — `fix: undefined vars, wrong return, debug echo in lnl/linkto/lostPassword/firstLogin`

## Verification

- All 4 files pass `php -l` syntax check
- No PHP warnings generated for undefined variables
- No debug output in HTTP responses
- No new Event Viewer errors
