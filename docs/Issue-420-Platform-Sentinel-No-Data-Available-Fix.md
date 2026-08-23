# Issue 420 — Execution Pane "No data available" for Platform-less Test Plans (Sentinel −1 vs DB 0)

## Issue #420

**Test Case Execution → Execute Tests**: for a test plan **without platforms**,
clicking a test case in the execution tree rendered `No data available` instead
of the execution form — the case could never be executed.


## Root Cause

Two conventions for "no platform" coexisted and nothing translated between
them:

1. **UI/request side** — `init_args()` in `lib/execute/execSetResults.php:2292-2296`
   declares the sentinel:

   ```php
   $isNumeric = [
     'tplan_id'    => 0,
     'build_id'    => 0,
     'platform_id' => -1
   ];
   ```

2. **Database side** — `testplan_tcversions.platform_id` and
   `executions.platform_id` store **0** for platform-less plans (schema default 0).

When a test case was clicked, the navigator fired
`execSetResults.php?...&setting_platform=-1` (measured live in this run's
reproduction). The value was `intval()`-ed but never translated, so downstream
queries compared it directly against the column:

```sql
WHERE TPTCV.testplan_id = 5
  AND TPTCV.platform_id = -1     -- stored value is 0 → 0 rows
```

`getLatestExecSingleContext()` returned null → `$linked_tcversions` null → the
whole `if(!is_null($linked_tcversions))` block was skipped → `$gui->map_last_exec`
kept its initial null → the template took the `{if $gui->map_last_exec eq ""}`
branch and printed `no_data_available`.

Blast radius was not limited to the first render:
`testcase::get_last_execution()` built `AND e.platform_id IN (-1)`, so even a
result written to the DB (landing with `platform_id = 0`) could not be read back.

## The Fix (HOW)

Normalize the sentinel once, at the single parse chokepoint where every exec-screen
request funnels through (`lib/execute/execSetResults.php:2313-2320`, landed on the
default branch by commit `857555dad`, verified live 2026-08-23):

```php
// The platform filter uses -1 as the "no platform selected" sentinel, but
// testplan_tcversions.platform_id and executions.platform_id store 0 for
// test plans without platforms. Queries downstream compare the value
// directly against those columns, so -1 never matches and the execution
// pane renders "No data available". Normalize to the DB convention.
if($argsObj->platform_id < 0) {
  $argsObj->platform_id = 0;
}
```

### Why here (alternatives rejected)

- **Rewrite all downstream queries to accept -1** — touches many call sites
  (`getLatestExecSingleContext`, `get_last_execution`, `getFeatureID`,
  `get_linked_tcvid`, …); high regression risk for zero benefit.
- **Make the navigator emit 0** — other consumers still send -1 via cached form
  tokens/sessions; normalizing at parse time covers every entry path.
- Existing `> 0` guards elsewhere are unaffected: `0` is not `> 0`, exactly like `-1`.
- A repo-wide audit (`grep -rn "platform_id.*=>\s*-1\|setting_platform=-1" lib/ gui/`)
  confirms exactly ONE sentinel declaration remains (`execSetResults.php:2295`),
  neutralized by the ONE normalization at `:2318-2320`. No other leak path exists.

## Verification (2026-08-23, headless Chrome + MariaDB, default branch @ `4cb3bd6ec`)

Fixtures: project id=1 → suite id=2 → case I420-1 (tcversion 4) → plan id=5
without platforms (`testplan_tcversions(5,4,0)`) → build id=1.

| # | Case | Result |
|---|------|--------|
| V1 | Click TC with `setting_platform=-1` in URL | Full execution form rendered (no "No data available") — PASS |
| V2 | Save status Passed | POST accepted — PASS |
| V3 | Read-back | "Latest execution (current build): Build 1 … Status : Passed" — PASS |
| V4 | DB row | `{id:1, build_id:1, tcversion_id:4, platform_id:0, status:'p'}` — PASS |
| V5 | Sentinel audit | single declaration + single normalization point — PASS |

Regression suite: `tmp/TLU_Test_Cases.md` Suite 60 — **6/6 PASS**.

Event Viewer: no new errors attributable to this flow (only the pre-existing
execution-form E_WARNING trio recorded in Suite 59, unrelated to platforms).

## Side observation

During reproduction with hand-made SQL fixtures, a testsuite tree node *without*
its `testsuites` row makes `get_ts_name_details()` return nothing, so
`execSetResults.php:950` computes `$tsid = NULL` and
`testsuite::keywordIsLinked()` builds `WHERE fk_id IN ()` → raw "DB Access Error"
page. Only reachable with corrupted data (UI-created suites always have the
row); documented for the record, not filed as a separate bug.
