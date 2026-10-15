# Bugfix Issue #1917 — Performance Test Reports: 9 client bundles lost the `perf.*` keys (coverage gate did not gate the merge)

**Issue:** [#1917](https://github.com/sebiboga/testlink-upgraded/issues/1917)
**Branch:** `fix/issue-1917-i18n-perf-keys`
**Fix commit:** `08631dda3` — `fix(i18n): restore #1063 perf.* keys dropped from 9 bundles`
**Files:** `gui/templates/i18n/{ro,de,es,fr,it,pt,ru,ja,zh}.json` (restored), root cause at `gui/templates/i18n/i18n.js:175`

## Symptom

The modernized **Performance Test Reports** screen
(`gui/templates/results/perfReports.html`, Refs #1063) rendered raw i18n keys —
`perf.title`, `perf.summaryTitle`, `footers.perfReports`, … (75 keys) — for **every
non-English locale**, while English looked fine. `TLi18n` falls back silently to the
key (`gui/templates/i18n/i18n.js:175` → `var str = _strings[key] || key;`), so nothing
threw; only `ai/verify_i18n_coverage.sh` noticed (9/9 FAIL on the default branch).

## Root cause

1. `#1063` commit `5d6bd072d` added the 74 `perf.*` keys + `footers.perfReports` to
   all ten client bundles.
2. `#1134` commit `7023d9762` **whole-file rewrote the nine non-en bundles** from a
   copy taken *before* `5d6bd072d`, dropping all 75 keys. `en.json` was edited by a
   *separate* `#1134` commit (`2257ea295`) and kept them — so the regression was
   invisible to an English-only user.
3. `7023d9762` was a **direct push** to the default branch (single parent, no merge),
   and `ai/verify_i18n_coverage.sh` is **not referenced by any `.github/workflows/`
   file**, so no check stood between it and the default branch.

This is the same regression class as the `tmp/TLU_Test_Cases.md` whole-file clobbers
(#1849/#1851/#1836), now applied to the shared client bundles.

## Fix

The restoration already landed on the default branch in `08631dda3` (re-ran the
idempotent `tmp/add_perf_i18n.py`; all 10 bundles now hold the same 7875-key set).
This run **verified** it:

- key-set present in all 10 bundles (74 `perf.*` each);
- byte-faithful vs the original `5d6bd072d` add — deep compare of the
  `perf.*` + `footers.perfReports` maps for ro/de/ja/zh: **0 value diffs, 0 missing**;
- `ai/verify_i18n_coverage.sh` → **9 bundle(s) passed, 0 failed** (exit 0);
- negative control: removing `perf.title` from a bundle makes the gate **FAIL exit 1**;
- live runtime via headless Chrome — `perfReports.html?locale=ro` resolves
  `perf.title` → `Rapoarte de performanță`, `footers.perfReports` →
  `TestLink 2.0.1 - Rapoarte de performanță`, `perf.colLabel` → `Etichetă` (no raw-key
  leak).

## Follow-up (blocked, documented — not fixed here)

The issue's suggested structural fix — make the coverage gate a required CI check —
is **blocked at the token level**: `#1850` (closed) already attempted
`.github/workflows/i18n-coverage.yml` and the push was rejected because the CI agent
token lacks the `workflows` permission.

## Related

While verifying, a **separate still-live instance of the same class** was found:
`dfba3151f` whole-file rewrote the bundles and dropped the 50 `tmvc.*` keys +
`footers.containerMoveTC`, so `gui/templates/testcases/containerMoveTC.html` renders
raw keys in all locales. Filed as [#1918](https://github.com/sebiboga/testlink-upgraded/issues/1918).
