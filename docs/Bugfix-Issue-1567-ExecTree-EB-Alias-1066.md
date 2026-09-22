# Bugfix — Issue 1567 — SQL 1066 "Not unique table/alias 'EB'" in exec tree when a combined bug+platform filter matches nothing

**Issue:** [#1567](https://github.com/sebiboga/testlink-upgraded/issues/1567)
**Branch:** `fix/issue-1567-eb-alias-collision`
**Status:** FIXED & VERIFIED (2026-09-22)

## Symptom

Running the execution-navigator tree through the modern BFF with a linked-bug
filter whose bug id has no matching `execution_bugs` row produces the MySQL error

```
ERROR 1066 (42000) at line 1: Not unique table/alias: 'EB'
```

- Modern BFF (`/api/execnavigator/index.php?action=init&tplan_id=2&tproject_id=1&filter_bugs=X-1&setting_platform=999&setting_testplan=2`) rendered the legacy **DB Access Error** page instead of the JSON contract and logged two `DATABASE` ERROR rows (`log_level=1`) in `events` with the full failing SQL (`…sqlUnion - executions… JOIN execution_bugs EB ON EB.execution_id = E.id … JOIN execution_bugs EB ON EB.execution_id = E.id … AND EB.bug_id IN ('X-1')`).
- Standalone harness (same SQL piped into `mysql`) reproduced `ERROR 1066` directly, independent of browser/session.

Severity Low (crafted `filter_bugs` input only; the modernized screen exposes no
bugs filter input), as reported.

## Root cause chain

1. `helper_bugs_sql()` — `lib/functions/testplan.class.php:1087-1106` — builds the bug filter join as `JOIN execution_bugs EB ON EB.execution_id = E.id` with the alias **hardcoded** to `EB` (`:1103`) and stores it as `$my['join']['bugs']` (assigned at `:6526-6527`).
2. `getLinkedForExecTree()` (`lib/functions/testplan.class.php:6166`) emits `$my['join']['bugs']` in the exec branch at **two** positions:
   - `:6277` — in the first join block (with `ua`/`keywords`/`cf`/`tsuites`/`aliens`), i.e. BEFORE `JOIN ({$sqlLEBBP}) AS LEBBP` and BEFORE `JOIN executions E` (spurious);
   - `:6293` — after `JOIN executions E`, annotated `// need to be here because uses join with E table alias` (intended, correct).
3. When `bug_id` is set, `$my['join']['bugs']` is non-empty at BOTH positions → the same query contains two `execution_bugs` tables both aliased `EB` → MySQL 1066 at execution time (tree pipeline: `execTree()` → `fetchRowsIntoMap()`, via `lib/functions/execTreeMenu.inc.php:146`).
4. The `not_run` UNION branch is NOT part of the collision: it is nulled whenever `bug_id` is set (`:6211`, guarded at `:6215`; return at `:6298`). The collision is entirely within the single exec branch — this corrects the report's original "UNION branch" hypothesis (see the issue's INVESTIGATION/ROOT CAUSE comments).

### Why it broke / regression source

Upstream commit `ff21e99201` (2020-04-04, "new class build.class.php", the big
`testplan.class.php` refactor) ADDED the spurious pre-`LEBBP` bugs join at `:6277`.
The pre-refactor function emits the bugs join only once, at the post-`E` position
(`git show ff21e99201^:lib/functions/testplan.class.php` around "sqlUnion -
executions"). So 2.0.1 inherited the duplicate-alias defect from that refactor.

### Blast radius

- `lib/functions/testplan.class.php` — `join['bugs']` occurrences: `:6237` (not_run branch; never emitted when bug filter active — harmless), `:6277` (BUG), `:6293` (intended).
- Other exec-tree generators in the same file (`getLinkedForExecTreeCross` `:8118`, `getLinkedForExecTreeIVU` `:7971`) do not emit the bugs join → unaffected.
- Consumers: `execTree()` ← `tlTestCaseFilterControl::build_tree_menu()` (`execution_mode`, `lib/functions/tlTestCaseFilterControl.class.php:1092-1098`) ← BFF `api/execnavigator/index.php` and legacy `lib/execute/execNavigator.php`. Any `bug_id`/`filter_bugs` on an execution tree with aligned session settings hits the 1066.

## Fix (minimal)

Removed the spurious duplicate from the exec branch:

```
lib/functions/testplan.class.php
-                     $my['join']['bugs'] .        (pre-LEBBP block, :6277)
```

The intended post-`E` bugs join (`:6293`) is kept unchanged, so bug-filter
semantics are identical (the change also removes the latent forward-reference
of `E.id` carried by the deleted join). No refactoring, no other files touched.

Rejected alternatives: renaming the post-`E` alias to `EB2` (removes the error but keeps a redundant duplicate join); keeping only the pre-`LEBBP` join (would reference `E.id` before `E` is introduced — MySQL-only tolerance). Removing the pre-`LEBBP` duplicate matches the original pre-2020 code.

## Verification

- SQL-level, unmatched bug (`bug_id='X-1'`, platform 999, build 1): generated query has exactly ONE `JOIN execution_bugs EB …`; executes cleanly (0 rows).
- SQL-level, matching bug (seeded `execution_bugs`): returns the executed TC (`exec_status=p`) with a single EB join.
- BFF end-to-end (admin session): `filter_bugs=X-1&setting_platform=999` → `200 {"status":"ok"}` empty tree; `filter_bugs=BUG-123&setting_platform=1` → `200 status:ok` tree returns the bug-linked TC; baseline (no bug filter) unchanged.
- Event Viewer: `events` table max id unchanged after the post-fix runs — no new Error/Warning rows.
- Regression suite: `Suite 1567` in `tmp/TLU_Test_Cases.md` — PASS.

## Test matrix (regression)

| Case | Pre-fix | Post-fix |
|---|---|---|
| `bug_id` unmatched (SQL) | `ERROR 1066` | OK, 0 rows |
| `bug_id` matching (SQL) | — (never ran) | OK, returns TC |
| BFF bug+platform filter | DB Access Error + DATABASE 1066 events | 200 `status:ok`, empty tree |
| BFF no-filter baseline | OK | OK (unchanged) |
| Event Viewer | 1066 ERROR rows | no new rows |