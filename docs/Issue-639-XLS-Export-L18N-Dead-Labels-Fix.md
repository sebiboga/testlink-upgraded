# Issue 639 — XLS exports fired 2 L18N events (`priority_level` / `not_run` not localized)

**Issue:** [#639](https://github.com/sebiboga/testlink-upgraded/issues/639)
**Branch:** `fix/issue-639-xls-l18n-dead-labels` · **Commits:** `c40723aab`, `4dd1265be`
**Status:** FIXED & VERIFIED (2026-08-23)

## Symptom

Every **Baselines L1 & L2** spreadsheet export
(`baselinel1l2.php?...&format=1&spreadsheet=1`) wrote 2 localization warning
events (log_level 32 = L18N) into `events`:

```
string 'priority_level' is not localized for locale 'en_GB'
string 'not_run' is not localized for locale 'en_GB'
```

The export itself still produced a valid .xls, so the impact was Event Viewer
noise plus raw tag-prefixed keys as fallback label values.

## Root cause chain

1. `lib/results/baselinel1l2.php:285` — `createSpreadsheet()` calls
   `$lbl = initLblSpreadsheet()` on every spreadsheet request.
2. `lib/results/baselinel1l2.php:557,559` — `initLblSpreadsheet()` requested
   `'priority_level' => null` and `'not_run' => null`.
3. `lib/functions/lang_api.php:317-325` — `init_labels()` resolves a `null`
   label as `lang_get($key)`.
4. `lib/functions/lang_api.php:135` — neither `$TLS_priority_level` nor
   `$TLS_not_run` exists in **any** of the 19 locale bundles (only
   `$TLS_test_status_not_run`, locale/en_GB/strings.txt:324), so each lookup
   logged a `logL18NWarningEvent`.

Key insight: the two labels were **never consumed anywhere**
(`grep "lbl\['priority_level'\]\|lbl\['not_run'\]" lib/ gui/` → 0 hits). They
were dead entries whose only observable effect was firing the failing lookups.

## Blast radius

Identical dead entries existed in 4 reports sharing the same
`initLblSpreadsheet()` pattern — each fired the same 2 events per export:

| File | Lines |
|---|---|
| `lib/results/baselinel1l2.php` | 557, 559 |
| `lib/results/resultsByTSuite.php` | 532, 534 |
| `lib/results/resultsGeneral.php` | 584, 586 |
| `lib/results/testAutomationSpec.php` | 390, 392 |

## Approach — why deletion instead of adding translations

Chosen fix: **delete the two dead entries** from all four functions.

- Nothing consumes the labels → translating them into 19 languages would
  invent unused strings.
- Upstream TestLink vocabulary expresses this concept via
  `test_status_not_run`; a bare `not_run` key would be vocabulary noise.
- Removal is provably behavior-neutral since the values are never read.

Rejected alternatives:

- Add `$TLS_priority_level` / `$TLS_not_run` to all locale bundles — fixes the
  symptom while keeping dead weight; requires unverifiable new translations.
- Point entries at existing keys (`'not_run' => 'test_status_not_run'`) — same
  dead weight for zero benefit.

Diff per file (2 lines removed):

```php
   $lbl = init_labels(
            array('testsuite' => null,
                  'testcase_qty' => null,'keyword' => null,
                  'platform' => null,'priority' => null,
-                 'priority_level' => null,
                  'build' => null,'testplan' => null,
-                 'testproject' => null,'not_run' => null,
+                 'testproject' => null,
                  'completed_perc' => 'trep_comp_perc',
                  'generated_by_TestLink_on' => null));
```

## Verification (2026-08-23)

Fixtures: project id=1001 + plan id=1002 (no baseline data needed).

| Check | Result |
|---|---|
| Pre-fix repro | HTTP 200 valid XLS **+ events id 4–5** exactly matching the issue |
| Post-fix baselinel1l2 / resultsByTSuite / resultsGeneral exports | HTTP 200, valid CDFV2 .xls, **zero new L18N events** |
| Workbook strings | `Test Project/Test Plan/Build/Not Run/Passed/Failed/Blocked/Completed [%]/Test Case #` all localized; no raw keys |
| UI export button on report screen | POST format=3&spreadsheet=1 → HTTP 200, no new events |
| Event Viewer after full matrix | clean |



## Related

- #642 — testAutomationSpec.php direct export HTTP 500 on empty plan,
  discovered during this verification, verified unrelated (pre-existing).
