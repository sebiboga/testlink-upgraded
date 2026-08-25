# Issue 657 — Self-signup accepts duplicate email address (no uniqueness validation)

**Issue:** [#657](https://github.com/sebiboga/testlink-upgraded/issues/657)
**Branch:** `fix/issue-657-duplicate-email` · **Commit:** `252bbabb2` (fix)
**Status:** FIXED & VERIFIED (2026-08-25)

## Symptom

The self-signup page (`firstLogin.php?viewer=new`) accepted a second user registration with the same email address as an existing user. No error was shown; the duplicate user was silently created.

## Root cause chain

`lib/functions/tlUser.class.php:623-626` — `checkDetails()` validates login uniqueness via `doesUserExist()` but has **no email uniqueness check**.

1. `firstLogin.php:48` → `$user->writeToDB($db)` → `checkDetails()` called.
2. `checkDetails()` checks email **format** (`checkEmailAddress()` at line 619) but never queries the DB for duplicate emails.
3. The static method `doesUserExistByEmail()` exists at line 1111 but was **never called**.
4. No error constant or i18n string existed for duplicate email.
5. `firstLogin.php` had a secondary bug: after `writeToDB()` returned an error, it still redirected to `login.php` without showing the error.

**Why it breaks now:** Original design omission from 1.9.20 — not a regression.

**Blast radius:** All user creation paths through `tlUser::writeToDB()` + `checkDetails()` were affected: self-signup (`firstLogin.php`), admin user creation (`usersEdit.php`), XMLRPC user creation.

## Fix

1. Added `const E_EMAILALREADYEXISTS = -1024;` to `tlUser` class constants (`tlUser.class.php:122`).
2. Added email uniqueness check in `checkDetails()` (`tlUser.class.php:628-631`):
   ```php
   if ($result >= tl::OK && !$this->dbID)
   {
     $result = self::doesUserExistByEmail($db,$this->emailAddress) ? self::E_EMAILALREADYEXISTS : tl::OK;
   }
   ```
3. Added error case in `getUserErrorMessage()` (`users.inc.php:267-269`):
   ```php
   case tlUser::E_EMAILALREADYEXISTS:
     $msg = lang_get('email_already_used');
   break;
   ```
4. Added `email_already_used` i18n key to all 19 locale bundles.
5. Wrapped `firstLogin.php` post-`writeToDB()` block in `if ($result >= tl::OK)` so errors are displayed instead of silently redirected.

## Verification matrix

| # | Check | Result |
|---|---|---|
| R1 | POST with duplicate email → error message displayed | PASS |
| R2 | POST with unique email → redirect to login.php | PASS |
| R3 | POST with duplicate login → existing error still works | PASS |
| R4 | POST with empty email → existing error still works | PASS |
| R5 | Browser: form displays error on duplicate email | PASS |
| R6 | DB: no two users share email | PASS |
| R7 | Event Viewer: no new Error/Warning entries | PASS |

## Files changed

- `lib/functions/tlUser.class.php` (+5 lines: constant + email check)
- `lib/functions/users.inc.php` (+4 lines: error case)
- `firstLogin.php` (+11 / -7 lines: result check wrapper)
- 19 locale `strings.txt` files (+1 line each: `email_already_used` key)
