# Bugfix — Issue #1949: Legacy 302 shims sanitize `$_SESSION['basehref']` (open-redirect / CRLF hardening)

**Issue:** https://github.com/sebiboga/testlink-upgraded/issues/1949
**Branch:** `fix/issue-1949` — commit `d5d69eadc` (fix), `1d9718323` (regression suite)

## Symptom

Several legacy redirect shims built their `Location:` header straight from
`$_SESSION['basehref']` without the hardening that `lib/attachments/attachmentupload.php`
(#1947) and `lib/testcases/tcCreateFromIssueMantisXML.php` (#1948) already carry. With a
poisoned session value the shims answered `302 Location: https://evil.example/...`
(open redirect — phishing vector), and a CRLF payload killed the redirect entirely
(silent `200`, no `Location`) while persisting an `E_WARNING "Header may not contain more
than a single header, new line detected"` row into the Event Viewer.

## Root cause

1. `get_home_url()` (`lib/functions/configCheck.php:42`) builds the base URL from
   **client-controlled** headers — `HTTP_X_FORWARDED_PROTO`, `HTTP_X_FORWARDED_HOST`,
   `HTTP_HOST`.
2. `setPaths()` (`lib/functions/common.php:442`) stores it once per session as
   `$_SESSION['basehref']`.
3. The retired-Smarty controllers kept as session-guarded 302 shims interpolated the raw
   session value into `header('Location: ' . $base . 'gui/...')`, e.g.
   `lib/testcases/tcCreateFromIssue.php:19-25`, `lib/execute/execNavigator.php`,
   `lib/general/mainPage.php`, `lib/attachments/attachmentdownload.php`.

## Fix — method chosen

Point-of-use normalization, **the exact block from #1948**, applied at every sink:

```php
$base = str_replace(array("\r", "\n"), '', strval($_SESSION['basehref'] ?? ''));
if (preg_match('~^https?://[^/]+(/.+)$~i', $base, $m)) { $base = rtrim($m[1], '/') . '/'; }
if ($base === '' || $base[0] !== '/' || strpos($base, '//') === 0) { $base = '/'; }
```

Steps: strip CRLF (kills header injection + the `E_WARNING`), downgrade a
`scheme://host(/path)` base to its root-relative path (kills the open redirect — targets
become same-origin by construction), fall back to `/` for empty, non-`/`-leading or
`//`-prefixed values.

**Why this method (alternatives rejected):**
- *Sanitizing the stored value once in `setPaths()`* was rejected: `$_SESSION['basehref']`
  is legitimately absolute for non-redirect consumers (direct-link copy features,
  print-header `<base href>`, email-style shareable URLs). Forcing it to a path at set
  time would change those flows — a broader behavior change than a hardening fix may take.
- *A shared helper function* was rejected: the two shims that need it most
  (`tcCreateFromIssue.php`, `tcCreateFromIssueMantisXML.php`) deliberately include
  nothing (bare `session_start()`), and adding a `require` to 45 files is larger churn
  than the inline block; #1947/#1948 already established the inline pattern.
- *Central normalization in `testlinkInitPage()`* was rejected: some shims
  (`reqMgrSystemEdit`, `cfields*`) authenticate through `checkSessionValid()` only, so
  the hook would not be universal.

## Blast radius — 45 files changed (all `lib/**`, no template/API/i18n changes)

testcases: `tcCreateFromIssue`, `tcExecute`, `scriptAdd`, `scriptDelete`;
execute: `execNavigator`, `execSetResults`, `execNotes`, `bugAdd`, `bugDelete`,
`editExecution`; attachments: `attachmentdownload` (was only partially hardened — empty
fallback but no CRLF strip / absolute downgrade), `attachmentdelete`; events:
`eventviewer`, `eventinfo`; general: `mainPage` (2 sinks), `frmWorkArea`, `show_help`;
results: `printDocOptions`, `priorityBarChart`, `platformPieChart`, `keywordBarChart`,
`overallPieChart`, `topLevelSuitesBarChart`, `testCasesWithCF`; requirements: `reqTcAssign`
(2 sinks), `reqSpecListTree`, `reqEdit`/`reqSpecEdit` (refuse-unknown-action redirectors);
keywords: `keywordsEdit` (4 sinks incl. `kwShimOut()` + the CSRF origin check), `keywordsImport`,
`keywordsExport`; plans: `planTCNavigator`, `planAddTCNavigator`; platforms:
`platformsEdit`, `platformsImport`; usermanagement: `usersView`, `usersEdit`, `rolesEdit`;
cfields: `cfieldsEdit`, `cfieldsTprojectAssign`; search: `searchMgmt`; project:
`project_req_spec_mgmt`; reqmgrsystems: `reqMgrSystemEdit`; ajax: `gettestcasesummary`
(also builds the login bounce from the same base), `getrequirementnodes`.

**Already hardened (untouched):** `attachmentupload.php` (#1947),
`tcCreateFromIssueMantisXML.php` (#1948).
**Not vulnerable (root-relative literals, no basehref):** the `lib/ajax` tree shims
(`getreqcoveragenodes`, `gettprojectnodes`, `stepReorder`, `dragdrop*`, `getreqlog`,
`getreqspeclog`, `getreqmonitors`), `reqSpecSearchForm`, `reqSpecSearch`, `listTestCases`.
**Out of minimal scope (non-shim sinks, follow-up candidates):** `lib/functions/csrf.php`
error bounce, the logout bounce (`common.php:1672`, `tlUser.class.php:1529`),
`planAddTC.php`/`planUpdateTC.php` static-page notices, `projectView.php` bootstrap.

## Verification (measured)

- `php -l` clean on all 45 files.
- Open-redirect repro (session poisoned to `https://evil.example/phish/`): 20 shims
  re-tested + full 40-site sweep — every `Location` now same-origin root-relative
  (`/phish/gui/...`), evil host dropped.
- CRLF repro: pre-fix `execNavigator.php` answered `200` with **no `Location`** and wrote
  `E_WARNING` row `events id=2`; post-fix `302 Location: /phishX-Injected: yes/gui/...`
  (single same-origin header, no warning, no new row).
- Clean-session regression: all 40 entry points still 302 to their correct modern
  `/gui/templates/...` screen with query strings intact; `keywordsEdit` POST `do_create`
  still performs the legacy write (303 + audit event); `gettestcasesummary` keeps its
  XHR-405 / document-302 contract.
- Browser: logged in as admin, `lib/execute/execNavigator.php?tplan_id=1` →
  `gui/templates/execute/execNavigator.html` (only pre-existing `tplan_id=0 → 400` BFF
  state in console, identical pre-fix).
- Event Viewer: 0 new Error/Warning rows from any post-fix test.
- Regression suite `Regression — Issue #1949` (4 parts) appended to
  `tmp/TLU_Test_Cases.md`; gate `TLU_REQUIRE_SUITE="Issue #1949" bash ai/verify_test_suites.sh`
  = 34 PASS / 0 FAIL.
