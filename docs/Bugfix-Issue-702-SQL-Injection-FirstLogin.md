# Bugfix Issue #702: SQL Injection in firstLogin.php notifyGlobalAdmins()

**Date:** 2026-08-25  
**Branch:** fix/issue-702-sql-injection  
**Commit:** f1f0d0c5b  
**Priority:** CRITICAL (security)

## Symptom

`firstLogin.php:141-142` — the `notifyGlobalAdmins()` function builds an SQL `IN(...)` clause using `implode("','", $cfg->userSignUp->to->users)` **without escaping** each value. Any value containing a single quote breaks out of the string literal, enabling SQL injection.

Additionally, `firstLogin.php:143` originally contained a debug `echo '<br>' . __LINE__` left in production code. This was already removed by commit `2492d763c` (Refs #704) before this fix run.

## Root Cause

`config_get('notifications')` returns `$cfg->userSignUp->to->users` (set in `config.inc.php:322`). When non-null (e.g. `array('admin','testuser')`), the values flow unsanitized into SQL via string concatenation:

```php
// VULNERABLE (firstLogin.php:141-142):
$sql = " SELECT id,email FROM {$tables['users']} " .
       " WHERE login IN('" . implode("','", $cfg->userSignUp->to->users) . "')";
```

## Fix

Escape each value using `$dbHandler->prepare_string()` (database.class.php:399, wraps ADOdb `qstr()`) before interpolation:

```php
// FIXED (firstLogin.php:140-145):
$escapedUsers = array_map(function($u) use ($dbHandler) {
    return $dbHandler->prepare_string($u);
}, $cfg->userSignUp->to->users);
$sql = " SELECT id,email FROM {$tables['users']} " .
       " WHERE login IN('" . implode("','", $escapedUsers) . "')";
```

## Blast Radius

- **Single call site:** `firstLogin.php:141-142` only
- **Trigger condition:** `$cfg->userSignUp->to->users` must be non-null AND user self-signup must complete successfully AND `$cfg->userSignUp->enabled` is true
- **Default config:** `users = null` → vulnerable path not reached by default

## Files Changed

- `firstLogin.php` — lines 139-145: added `array_map` with `prepare_string()` escaping

## Verification

1. PHP syntax: `php -l firstLogin.php` → clean
2. Self-signup with default config: user created, redirects to login
3. Self-signup with configured notification users: SQL query runs with escaped values, no error
4. Event Viewer: no new Error/Warning entries
