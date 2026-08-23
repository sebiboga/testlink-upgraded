# Issue 427 — Baselines L1 & L2 report: null `$rsu` loop guard (no-baseline E_WARNING)

Issue: https://github.com/sebiboga/testlink-upgraded/issues/427
Related: #424 (earlier mitigation on this file), #639 (L18N notices in this screen's XLS export — separate bug)

## Symptom

Opening **Reports and Metrics → Baselines Level 1 & Level 2 Test Suites** for any
test plan that has **never had a baseline saved** wrote an E_WARNING into the
`events` table on every render:

```
E_WARNING
foreach() argument must be of type array|object, null given - in lib/results/baselinel1l2.php - Line 95
```

The original issue body reported a worse variant — a fatal
`Cannot use object of type stdClass as array` at compiled-template line 140
(`$gui->statistics[$platId]`) — plus a second defect where
`$gui->statistics = array()` **inside** the platform loop reset the accumulator
so only the last platform survived. Both of those were already mitigated by run
#424 (initialization hoisted above the loop), which is why post-#424 renders
returned HTTP 200. What remained was the unguarded loop over a null row map.

## Approach

Root cause chain:

1. `lib/functions/database.class.php:981` — `fetchRowsIntoMap4l()` starts with
   `$items = null` and only materializes the array inside its row loop; an empty
   result set returns **null** (:1004).
2. `lib/results/baselinel1l2.php:80` assigns that null to `$rsu` whenever the
   plan has zero rows in `baseline_l1l2_context` × `baseline_l1l2_details`.
3. `lib/results/baselinel1l2.php:95` iterates `$rsu` unconditionally → PHP 8
   E_WARNING, logged per render.

Fix (minimal, single call site):

```php
$rsu = $db->fetchRowsIntoMap4l($sql,$keyCols,true);

// fetchRowsIntoMap4l() returns NULL when the plan has no saved baseline
// yet; iterating null raised "E_WARNING foreach() argument must be of
// type array|object, null given" on every no-baseline render. (#427)
if( is_null($rsu) ) {
  $rsu = array();
}
```

Alternatives rejected:

- Changing `database.class.php` return contract (null → array) — ~10 call sites
  share that fetcher; a contract change could ripple into unrelated reports.
- Wrapping the `foreach` in `is_array()` instead — equivalent behavior but the
  normalization reads cleaner next to the hoisted init from #424.

Commit: `6504fc15b` (`fix(results): guard baselinel1l2 loop against null $rsu …`, branch `fix/issue-427`).

## Verification (regression matrix, run 2026-08-23)

Fixtures: `php tmp/fixtures_427.php` → project RPT427 / plan Plan427A (id 6,
no platforms, no baseline); `php tmp/fixtures_427b.php` → project RPT427B /
plans Plan427B (id 10, platforms P-ONE + P-TWO, baselines on both) and
Plan427C (id 11, platform P-ONE only, baseline). Baseline rows carry passed=7 /
not_run=3 of total 10 per top→child pair.

| Case | Request | Result |
|---|---|---|
| R1 no baseline | `baselinel1l2.php?tplan_id=6&tproject_id=1&format=0` | HTTP 200 header-only report; **0 new Error/Warning events** (pre-fix produced E_WARNING events id 3) |
| R2 two platforms | `tplan_id=10&…&format=0` | HTTP 200; BOTH `Results on Platform: P-ONE` and `P-TWO` metric tables render with 70%/30% percentages — accumulator intact across loop iterations |
| R3 single platform | `tplan_id=11&…&format=0` | HTTP 200; single P-ONE section |
| R4 XLS export | `tplan_id=6&…&format=1&spreadsheet=1` | HTTP 200 valid .xls download; no fatal on the touched path |

Event Viewer after the suite: zero new Error/Warning rows. The export path did
surface two pre-existing **L18N-level** (log_level 32) notices
(`priority_level`, `not_run` not localized for en_GB) — logged separately as #639,
deliberately not fixed in this scope.

Full suite: `Suite 427` in `tmp/TLU_Test_Cases.md` (5/5 PASS).
