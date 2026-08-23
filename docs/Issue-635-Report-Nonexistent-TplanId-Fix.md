# Issue 635 — Report URLs with a nonexistent tplan_id: graceful info page instead of E_WARNING + HTTP 500

Issue: https://github.com/sebiboga/testlink-upgraded/issues/635
Related: #634 (valid plan without builds still fatals — separate, open), #630 (resultsGeneral tprojOpt warnings — separate, open)

## Symptom

Any report controller that goes through `initArgsForReports()` with a
**nonexistent `tplan_id`** in the query string emitted an E_WARNING and died
with a raw **HTTP 500 / blank page**:

```
E_WARNING
Trying to access array offset on null - in lib/results/displayMgr.php - Line 72
```

Repro (measured 2026-08-23, localhost:8082, PHP 8.3.33): login admin/admin →
GET `/lib/results/resultsByTSuite.php?tplan_id=999999&tproject_id=1&format=0`
→ `HTTP:500 bytes:0`, events table row id 2 log_level 2 with the message above.

## Approach

Root cause chain:

1. `lib/results/displayMgr.php:70-71` (GUI branch of `initArgsForReports()`)
   calls `$tplanMgr->get_by_id($args->tplan_id)`; `testplan::get_by_id()`
   (`lib/functions/testplan.class.php:437`) returns **null** when no row matches.
2. Line 72 immediately reads `$tplan['testproject_id']` — offset access on
   null → E_WARNING.
3. `$args->tproject_id` stays null; the guard at lines 75-78 throws
   `"Invalid Test Project ID"` which no caller catches → uncaught exception →
   blank 500. Fixing only the warning would have left the fatal, so both were
   addressed.

Fix (minimal, 2 files):

- `lib/results/displayMgr.php`: null-check `$tplan` before the offset read;
  on miss render the purpose-built helper
  `displayInfo(lang_get('error_print_doc_title'), lang_get('error_print_doc_missing_testplan'))`
  (`lib/functions/info.inc.php:29`) and exit.
  - Strings are **pre-existing localized keys** (`locale/en_GB/strings.txt:641-642`);
    `lang_get()` auto-falls back to en_GB in locales lacking them
    (`lib/functions/lang_api.php:78-87`) — no locale bundle edits needed.
  - One shared function ⇒ all 7 controllers inherit the fix: neverRunByPP,
    testAutomationSpec, resultsTC, resultsByTSuite, execTimelineStats,
    baselinel1l2, resultsGeneral.
  - Alternatives rejected: guard reordering only (still a raw 500);
    per-controller handling (7× duplication); navigator redirect (needs a valid
    plan context anyway).
- `gui/templates/dashio/workAreaSimple.tpl`: optional-link block
  `{if $link_to_op ne ''}` → `{if isset($link_to_op) && $link_to_op ne ''}`.
  Reviving `displayInfo()` exposed that this template had never been rendered
  under PHP 8.3 strictness: each render wrote 2 E_WARNINGs per request
  (`Undefined array key "link_to_op"` + `Attempt to read property "value" on
  null` for `$hint_text`). Guarding at the template kills it for every future
  caller of the helper.

## Verification (6/6 PASS — see tmp/TLU_Test_Cases.md suite 635)

- Poisoned URL ×7 controllers + absent-id case: all **HTTP 200** friendly info
  page; zero recurrences of the original warning.
- Valid plan (SQL fixture project 9001 / plan 9002 / build 9003): reports still
  render normally (HTTP 200, "Metrics by Level 1 & Level 2 Test Suites").
- Event Viewer: after the template fix, zero new Error/Warning rows from this
  path; browser pass shows the page below with an empty console.


Commits: 97f01db27 (displayMgr guard), c773394ba (template null-safety).
