# Bugfix — Issue #1249: Legacy reports (displayMgr) E_WARNING + null read on 'currentUser' in sendByMail FORMAT_MAIL_HTML branch

## Problem

A crafted `?sendByMail=1` request to a legacy report URL whose anonymous
apikey matches no testproject/testplan entity logs two PHP 8 `E_WARNING` rows
into the `events` table (`activity=PHP`, `log_level=2`, source `GUI`):

```
E_WARNING Undefined array key "currentUser" - lib/results/displayMgr.php - Line 121
E_WARNING Attempt to read property "emailAddress" on null - lib/results/displayMgr.php - Line 121
```

The HTTP response stays 200 (warnings are logged, not thrown), so the symptom
is only visible in the Event Viewer / `events` table.

**Reproduce at HEAD (measured):** with a fresh session (no cookies) call

```
curl ".../lib/results/resultsGeneral.php?apikey=INVALIDKEY…64hex…&tplan_id=2&tproject_id=1&sendByMail=1"
```

→ HTTP 200, and the two E_WARNING rows above appear in `events`.
Authenticated sessions are unaffected (they always have `currentUser`).

This is the sibling branch of the already-fixed #1248 (which guarded
`displayMgr.php:102-103` and `resultsGeneral.php:270`); line 121 is a
different, pre-existing unguarded site of the same class.

## Root Cause

`generateHtmlEmail()` (lib/results/displayMgr.php:113-142) fills a missing
`from` property from the current user:

- `buildMailCfg()` (resultsGeneral.php:216-224) constructs the mail config
  with **only** `cc` and `subject` — `from` is never defined by any caller of
  the `displayReport()` FORMAT_MAIL_HTML branch, so
  `property_exists($mailCfg,'from')` at displayMgr.php:120 is always false.
- displayMgr.php:121 then reads `$_SESSION['currentUser']->emailAddress`
  unconditionally.
- On the anonymous invalid-apikey path `setUpEnvForAnonymousAccess()`
  (lib/functions/common.php:1356-1380) sets `$_SESSION['currentUser']` only
  when the apikey resolves to a testproject/testplan entity; a non-matching
  apikey leaves the key unset, so PHP 8 raises both warnings.

## Fix

Committed on branch `fix/issue-1249`, commit `1183719ff` (fix):

Defensive guard at the single flagged site — the same isset pattern already
used by the #1248 fix:

```php
// displayMgr.php:121 (generateHtmlEmail)
if( ! property_exists($mailCfg,'from') ) {
  $mailCfg->from = (isset($_SESSION['currentUser']) && is_object($_SESSION['currentUser'])
                    && property_exists($_SESSION['currentUser'],'emailAddress'))
                   ? $_SESSION['currentUser']->emailAddress : '';
}
```

**Why this approach:** the smallest change that makes the flagged read
null-safe on every path. With no currentUser the fallback is `''`, which flows
into the legacy graceful degradation already present at displayMgr.php:124-131
(`to = from = ''` → `status_ok=false` + the existing i18n message
`error_sendreport_no_email_credentials`) — no new user-facing strings, so no
locale-bundle changes. Authenticated behavior is byte-identical (the guard
passes, `from` = user email, exactly as before). One fixed site covers every
FORMAT_MAIL_HTML caller, because all report screens build their mail config
through `buildMailCfg()` and therefore root through this one line:
resultsByStatus, resultsTC, resultsTCAbsoluteLatest, tcNotRunAnyPlatform,
resultsByTSuite, resultsTCFlat, execTimelineStats, neverRunByPP,
baselinel1l2, testAutomationSpec. Rejected alternative: blocking the report
when the apikey resolves to nothing (a behavior change beyond a log-noise
bug), and fixing `setUpEnvForAnonymousAccess()` only (protects the reads
instead of moving the writes, but leaves any other future anonymous path
exposed).

## Files Changed

- `lib/results/displayMgr.php` — line 121: isset/is_object/property_exists
  guard with `''` fallback (1 site, 3 lines).

## Verification

All checks on branch `fix/issue-1249`, PHP 8.3, MySQL `testlink` 2.0.0
schema, fresh import (0 testprojects/testplans) + recreated fixture
(project FIXTP id 100, plan FIXTPL id 101; `users.email` set for the
authenticated-mail cases; `smtp_host` temporarily blanked so `email_send()`
takes its `stmp_host_unconfigured` short-circuit instead of throwing on the
placeholder host — reverted after):

- Invalid-apikey anonymous + `sendByMail=1` (pre-fix: 2 E_WARNINGs at
  displayMgr.php:121) → HTTP 200, **0** displayMgr:121 rows. Only the
  sibling `resultsGeneral.php:251/252/255` null-`get_by_id` rows remained —
  a different bug, filed as #1257.
- Authenticated + `sendByMail=1` with user email set → HTTP 200, **0**
  E_WARNING rows (`from` = user email).
- Authenticated + `sendByMail=1` with empty user email → HTTP 200, **0**
  E_WARNING rows; graceful `error_sendreport_no_email_credentials` path.
- Non-mail report (no `sendByMail`) → HTTP 200, **0** E_WARNING rows.
- `php -l lib/results/displayMgr.php` → clean.
- Event Viewer: `SELECT COUNT(*) FROM events WHERE activity='PHP' AND
  log_level=2` → **0** rows referencing `displayMgr.php:121` after the suite.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1249", 5/5 PASS.