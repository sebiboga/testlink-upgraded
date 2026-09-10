# Bugfix — Issue #1342: Self-signup logs spurious WARNING `external_password_mgmt`

## Problem

Every successful self-sign-up via the BFF (`POST /api/auth/signup`) generated a spurious WARNING-level entry in the Event Viewer: `config option not available: external_password_mgmt`.

The registration itself succeeded — this was purely log/Event Viewer noise.

## Root Cause

`api/auth/index.php:272` called `config_get('external_password_mgmt', false)`. The key `external_password_mgmt` is never defined in `$GLOBALS` or `$tlCfg`, so `config_get()` (at `lib/functions/common.php:681-705`) unconditionally logged a WARNING despite a default value being supplied.

## Fix

**File:** `api/auth/index.php:272`

Replaced:
```php
$externalPwd = (bool) config_get('external_password_mgmt', false);
```
With:
```php
$externalPwd = (bool) tlUser::isPasswordMgtExternal();
```

`tlUser::isPasswordMgtExternal()` (defined at `lib/functions/tlUser.class.php:206-224`) reads `$tlCfg->authentication` — a key that IS defined — and returns whether the current auth method manages passwords externally. It never queries an undefined config key, so no WARNING is emitted. This is the same pattern already used at `api/auth/index.php:328` for the password-reset route.

## Blast Radius

Single call site (`api/auth/index.php:272`). Every self-sign-up previously triggered one spurious WARNING. No functional impact; no other screens affected.

## Regression Testing

Verified by re-executing the self-signup flow after the fix:
- `events` table contained only `audit_users_self_signup` (log_level=16, AUDIT).
- Zero WARNING rows (`log_level=2`).
- Login with the newly created user also produced no spurious warnings.
