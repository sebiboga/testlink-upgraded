# Issue 678 — sysinfo.php: fatal 'Cannot redeclare checkPhpExtensions()' on every request — screen 500s (collides case-insensitively with configCheck.php)

**Issue:** [#678](https://github.com/sebiboga/testlink-upgraded/issues/678)
**Branch:** `fix/issue-678`
**Status:** FIXED & VERIFIED (2026-08-25)

## Symptom

`install/util/sysinfo.php` returns **HTTP 500 Internal Server Error** on every request with an empty body. PHP error log shows:

```
PHP Fatal error: Cannot redeclare checkPhpExtensions() (previously declared in .../install/util/sysinfo.php:48) in .../lib/functions/configCheck.php on line 608
```

The diagnostic/info screen is completely inaccessible.

## Root cause chain

1. `install/util/sysinfo.php:26` does `require_once config.inc.php`.
2. `config.inc.php` does `require_once configCheck.php`.
3. `lib/functions/configCheck.php:608` declares `checkPhpExtensions()` and `:858` declares `checkPhpVersion()`.
4. Back in `sysinfo.php`, line 32 declares `checkPHPVersion()` and line 48 declares `checkPHPExtensions()`.
5. PHP function names are **case-insensitive** — both pairs collide → **Fatal error: Cannot redeclare**.

**Regression source:** `configCheck.php` was introduced in commit `464cfc12b`; `sysinfo.php` was written independently before that, each defining their own diagnostic helper functions with colliding names.

## Blast radius

- Only `install/util/sysinfo.php` is affected — no other file calls its local versions.
- `configCheck.php` functions are part of the global include chain and must NOT be renamed.

## Fix

Renamed the two functions in `sysinfo.php` to unique names:
- `checkPHPVersion()` → `sysinfo_checkPHPVersion()` (definition line 32, call site line 263)
- `checkPHPExtensions()` → `sysinfo_checkPHPExtensions()` (definition line 48, call site line 264)

**Files changed:** `install/util/sysinfo.php` (4 lines)

## Verification

- `php -l install/util/sysinfo.php` → No syntax errors
- `curl -s -o /dev/null -w "%{http_code}" http://localhost:8082/install/util/sysinfo.php` → **200** (was 500)
- All 7 diagnostic sections render correctly (PHP Information, PHP Extensions, Memory & Execution Limits, Database Connection, Server Environment, File Permissions, footer)
- No new Error/Warning entries in Event Viewer
- Browser screenshot confirms full page rendering
