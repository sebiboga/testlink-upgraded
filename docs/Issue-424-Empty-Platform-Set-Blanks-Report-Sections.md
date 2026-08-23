# Issue 424 — Empty platform set blanked every per-platform report section

Issue: https://github.com/sebiboga/testlink-upgraded/issues/424

## Symptom

For a test plan **without platforms**, the report pages rendered their
per-platform sections as bare headings with no tables: on **General Test Plan
Metrics** "Results by Top Level Test Suite", "Results by Priority" and "Results
by Keyword" were silently absent of data while a meaningless empty "Results by
Platform" table appeared. Nothing errored — the numbers simply vanished.

## Approach

- Root cause: `tlPlatform::getLinkedToTestplanAsMap()` always returns an array,
  so a no-platform plan yields `[]`, never `null`. All four report pages guarded
  only `is_null($gui->platformSet)`, so `showPlatforms` stayed `true` with an
  empty set; the templates then looped `{foreach from=$platforms …}` zero times
  instead of falling back to `$gui->fakePlatform = array('')`.
- Fix (shipped in commit `764d625e1`, verified end-to-end by this run): widen the
  guard to treat empty same as null in all four pages:
  - `lib/results/resultsGeneral.php`
  - `lib/results/resultsByTSuite.php`
  - `lib/results/baselinel1l2.php`
  - `lib/results/execTimelineStats.php`

  `if( is_null($gui->platformSet) || count($gui->platformSet) == 0 ) {`
- Alternatives rejected: changing the getter's contract to return null (blast
  radius over every caller), or fixing it in each template (leaves the wrong
  flag exported to every consumer).
- Regression safety: the widened condition only changes behaviour for EMPTY
  sets; non-empty sets execute the byte-identical else-branch (`natsort`,
  `showPlatforms = true`).

## Verification (6/6 PASS — see tmp/TLU_Test_Cases.md suite 64)

Fixture: project RPT424, suite "Suite 1", TC-pass/TC-fail executed PASSED+FAILED
on build B1, plan Plan424 with **no platforms**.

- Pre-fix (reverted hunk): heading-only Top-Level-Suite section, bogus platform
  table present, Priority/Keyword sections gone.
- Post-fix: sections = Overall Build Status + Results by Top Level Test Suite;
  data row `Suite 1 | Total 2 | Not Run 0 | Passed 1 (50%) | Failed 1 (50%) |
  Completed 100%`; no platform section.
- Other three pages: HTTP 200, render gracefully on the same dataset.
- Platform-linked variant still renders identically to before the guard change.
- Event Viewer clean for the fix itself; latent E_WARNINGs surfaced by making
  `showPlatforms=false` reachable were filed separately (#632, #633, #427).

Note: these report pages require the `format` parameter when called directly —
without it they exit silently (`displayMgr.php`), which is pre-existing upstream
behaviour and unrelated to this defect.


