## Bugfix — Issue #1919: `planAddTC` save emits 3× E_WARNING "Undefined array key send_mail"

**Symptom:** On every `POST /api/plans/index.php/planaddtc/save` without `send_mail` (UI hides assignment panel when no build), three `E_WARNING: Undefined array key "send_mail"` rows were logged in the `events` table.

**Root cause:** In `api/plans/index.php:1990`, the boolean expression did three strict comparisons against the missing key after `!empty()` was false: 
```php
$sendMail = !empty($body['send_mail']) || $body['send_mail'] === true || $body['send_mail'] === 1 || $body['send_mail'] === "1";
```
Each `===` access of `$body['send_mail']` when undefined emitted a warning.

**Fix (minimal):** `api/plans/index.php:1990` → `$sendMail = !empty($body['send_mail']);`. Truth table preserved; no undefined key dereference.

**Verification:** Post-fix, saves without `send_mail` and with `send_mail:false` produced no new `log_level=2` events. Regression test added to `tmp/TLU_Test_Cases.md`.

**Files changed:** `api/plans/index.php:1990`

Refs #1919
