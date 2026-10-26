# Requirement Spec Coverage — Issue #1969 fix

Issue: [#1969](https://github.com/sebiboga/testlink-upgraded/issues/1969) — Requirement Spec Coverage: shared test cases double-count in plan/executed counters

## Root cause
The BFF (`api/reqspeccoverage/index.php`) incremented summary `plan_linked_tcs` per requirement link and aggregated per-spec counters by summing per-requirement values / counting per-link executions without deduplicating by `tcversion_id`. When the same test case version was linked to multiple requirements, counters were inflated (and per-spec `executed` could exceed `plan_tcversions`).

## Fix (minimal)
- Track distinct plan-assigned linked tcversions across all requirements in `$linkedPlanTcvsSummary` and set `summary.plan_linked_tcs = count(...)`.
- In per-spec aggregation, collect distinct sets of tcversion_ids (`_in_plan_tcv`, `_executed_tcv`, `_passed_tcv`, `_failed_tcv`, `_blocked_tcv`) for plan-assigned links; replace summed values with set counts. Keep `_tcversions` for `plan_tcversions`.

Files changed:
- `api/reqspeccoverage/index.php`

## Verification
- `php -l api/reqspeccoverage/index.php` OK.
- Regression suite appended to `tmp/TLU_Test_Cases.md` (Issue #1969) and gate passed.
