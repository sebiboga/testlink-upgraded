# Bugfix — Issue #1500: userinfo API profile save fatals on undefined tlUser::E_EMAILINVALID

## Problem

`PUT /api/userinfo` (modern "My Settings" / User Profile -> Save) with an email
the validation rejects — e.g. `admin@testlink.local` (the validation regex
`/^([\w]+)(.[\w]+)*@([\w-]+\.){1,5}([A-Za-z]){2,4}$/U` requires a 2–4 letter TLD,
`.local` is 5) — threw a PHP fatal instead of returning a clean error:

- HTTP response: **400 with an empty body** (client only showed the generic
  "Error saving profile" fallback toast).
- Server log (`tmp/php_server.log`):
  `PHP Fatal error: Uncaught Error: Undefined constant tlUser::E_EMAILINVALID in
  /api/userinfo/index.php:174`.

## Root Cause

Chain (each hop file:line):

1. `api/userinfo/index.php:166` — `PUT /userinfo` calls `$user->writeToDB($db)`.
2. `lib/functions/tlUser.class.php:405` — `writeToDB()` calls `$this->checkDetails($db)`.
3. `lib/functions/tlUser.class.php:619` — `checkDetails()` calls
   `self::checkEmailAddress($this->emailAddress)`.
4. `lib/functions/tlUser.class.php:1077-1090` — `checkEmailAddress()` returns
   `tlUser::E_EMAILLENGTH` (-2) for a blank email or `tlUser::E_EMAILFORMAT`
   (-512) when the regex rejects the format — **`E_EMAILFORMAT`** for
   `admin@testlink.local`.
5. `api/userinfo/index.php:171-176` — the negative-result branch read
   `if ($result == tlUser::E_EMAILINVALID)`. **`E_EMAILINVALID` does not exist**:
   tlUser defines only `E_EMAILLENGTH`, `E_EMAILFORMAT`, `E_EMAILALREADYEXISTS`
   (tlUser.class.php:113,121,122). PHP 8 treats an undefined constant reference
   as a fatal `Error`, hence the empty 400 body.

**Why it broke NOW:** the defect was introduced when the userinfo BFF was
modernized. The legacy `lib/usermanagement/userInfo.php` path used
`getUserErrorMessage()` (`lib/functions/users.inc.php:247-252`) which maps the
real codes (`E_EMAILLENGTH` → `empty_email_address`, `E_EMAILFORMAT` →
`no_good_email_address`); the BFF invented a constant tlUser never had.

**Blast radius:** a single call site — `grep -rn "E_EMAILINVALID"` matched only
`api/userinfo/index.php:174`. No other screen or BFF shares the path. The modern
frontend (`gui/templates/usermanagement/userInfo.html:259-266`) already parses
both `body.message` and `body.messageKey`, so returning clean JSON restores the
correct UX without any frontend change.

## Fix

Branch `fix/issue-1500`, commit(s): fix `(fix(userinfo): map real tlUser email
error codes instead of undefined E_EMAILINVALID)`, regression suite, docs.

One-line change at `api/userinfo/index.php:174`:

```php
if ($result == tlUser::E_EMAILFORMAT || $result == tlUser::E_EMAILLENGTH) $msg = 'Invalid email address';
```

**Why this method:** minimal and complete. It maps the two real error codes
`checkEmailAddress()` can return for an invalid email, deduplicating both paths
into the already-present `"Invalid email address"` message; the `code` field in
the JSON payload stays for client introspection. Rejected alternatives: (a)
guessing a new constant value avoids the fatal but adds an undocumented
non-existent contract; (b) switching to `getUserErrorMessage()` would introduce
`lang_get()` + locale `strings.txt` coupling in the BFF layer for one message.

## Files Changed

- `api/userinfo/index.php` — line 174, bogus `E_EMAILINVALID` comparison replaced
  with the real `E_EMAILFORMAT || E_EMAILLENGTH` codes.
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1500"
  (8 cases, 8/8 PASS).
- `docs/Bugfix-Issue-1500-Userinfo-Email-Rejection-Undef-Constant.md` — this
  write-up (mirror, without image lines).
- `docs/screenshots/issue-1500-invalid-email-clean-error.png` — post-fix UI
  state: profile save with `admin@testlink.local` shows the clean toast.
- `CHANGELOG` — one line under the 2.0.1 KEY BUGFIX section (Refs #1500).

## Verification

- Invalid TLD `admin@testlink.local` → HTTP 400, JSON
  `{"status":"error","message":"Invalid email address","code":-512}`, no fatal.
- Blank email `""` → HTTP 400, JSON `... "code":-2` (`E_EMAILLENGTH` mapped).
- Valid email `test@testlink.com` → HTTP 200 `Profile updated`, GET reflects it.
- Full profile save (first/last/locale) → 200, GET reflects; values restored.
- Browser UI (headless Chrome): invalid email → toast "Invalid email address",
  value not persisted, 0 JS errors; valid email → toast "Profile updated".
- `events` table: zero ERROR/WARNING rows generated during the whole run.
- `php -l api/userinfo/index.php`: no syntax errors; repo-wide grep confirms no
  remaining `E_EMAILINVALID` reference.

Full matrix: `tmp/TLU_Test_Cases.md` suite 1500 (8/8 PASS).