# Bugfix Issue #1922 — `planaddtc/save`: `assignment_mgr::assign()` got the wrong map shape — 3× `E_WARNING`, NO `user_assignments` row ever written (`tester_assigned_features` lied)

Class of defect: a ported call site passed the legacy multi-op **envelope** (`['add' => [feature_id => props]]`) to a manager method whose documented contract is the **inner** feature map (`[feature_id => props]`) — so every key lookup inside the loop hit an undefined index, and the row-insert loop never executed while the endpoint still reported success.

## Symptom

Every `POST /api/plans/index.php/planaddtc/save` with `testerID > 0 && build_id > 0` and at least one freshly linked test case:

- logged **3× `E_WARNING: Undefined array key "build_id"/"type"/"user_id"`** from `lib/functions/assignment_mgr.class.php:179/180/182` into `events` (Event Viewer `WARNING(3 items)`), and
- inserted **zero rows into `user_assignments`** — the "assign newly added test cases to the tester" feature was completely non-functional — while the response still answered HTTP 200 `{"status":"ok","added":1,"tester_assigned_features":1}`.

Reachable without `send_mail` (independent of Issue #1920).

## Root cause chain

1. `api/plans/index.php:1998` builds the legacy envelope `$features2 = ['add' => []]`, and `:2004-2019` fills `$features2['add'][featureId][...]` (user_id, type, status, assigner_id, build_id, creation_ts, platform_name, tcase_id, tcversion_id).
2. `api/plans/index.php:2023` (pre-fix) called `$tplanMgr->assignment_mgr->assign($features2);` — the **whole envelope** instead of `$features2['add']`.
3. `assignment_mgr::assign()` (lib/functions/assignment_mgr.class.php:169) documents `$feature_map['feature_id']['user_id'|'type'|'build_id']` (`:156-162`) and iterates `foreach ($feature_map as $feature_id => $elem)` (`:176`). With the envelope: `$feature_id === 'add'` and `$elem === [featureId => props]`.
4. `:179` `intval($elem['build_id'])`, `:180` `intval($elem['type'])`, `:182` `(array)$elem['user_id']` → three `E_WARNING: Undefined array key` (rows written to `events`, log_level 2).
5. `(array)null = []`, so the insert loop `foreach ($uSet as $user_id)` (`:184`) executes **zero times** → `user_assignments` stays empty (`$safe['feature_id']` would have been `intval('add') = 0` anyway).
6. `api/plans/index.php:2024` (pre-fix) then set `$assigned = count($features2['add'])` unconditionally → `tester_assigned_features` reported *intent*, not *rows written* — the lie called out in the issue.

Regression source: the whole tester-assignment block was ported in commit `1db523a20` ("feat(plans): BFF endpoints for Add/Remove Test Cases screen … Refs #593"); the legacy source it mirrors unwraps the envelope first — `lib/plan/planAddTC.php:1064-1066`: `foreach ($features2 as $key => $values) { $tplanMgr->assignment_mgr->assign($values); }` — and the port dropped that unwrap.

## Blast radius

`assignment_mgr::assign()` has 5 call sites; **only** the BFF one was wrong:

| Call site | Shape passed | |
|---|---|---|
| `api/plans/index.php:2023` | envelope `['add' => …]` | ❌ this bug |
| `lib/plan/planAddTC.php:1066` | inner map (unwrap loop `:1064`) | ✅ |
| `lib/plan/tc_exec_assignment.php:91` | inner map (double unwrap) | ✅ |
| `lib/functions/testplan.class.php:1806` | inner map | ✅ |
| `lib/api/xmlrpc/v1/xmlrpc.class.php:7892` | inner map | ✅ |

No legacy screen, no xmlrpc API and no other BFF endpoint was affected; the fix therefore touches one call site only.

## Fix and why this method

Minimal change in the broken boundary (`api/plans/index.php:2022-2030`):

```php
if (count($features2['add']) > 0) {
    // assign() takes the INNER feature map (legacy planAddTC.php:1064
    // unwraps 'add' first) and RETURNS the INSERTs executed, so
    // tester_assigned_features reports rows written, not intent
    // (Issue #1922)
    $written = $tplanMgr->assignment_mgr->assign($features2['add']);
    $assigned = is_array($written) ? count($written) : 0;
}
```

- **Unwrap at the caller** — restores exact legacy parity (`planAddTC.php:1064-1066`) and keeps `assign()`'s documented contract intact.
- **Count the return value** — `assign()` returns the list of INSERT statements it executed (`assignment_mgr.class.php:201-231`), so `tester_assigned_features` becomes "rows actually written": duplicates are skipped *before* the insert (`:196-197`), so the field can now truthfully report 0.
- **Rejected alternative A** — normalize both shapes *inside* `assignment_mgr::assign()`: touches the shared legacy class used by 4 other call sites + the xmlrpc API and would silently mask shape mistakes for all of them.
- **Rejected alternative B** — keep passing the envelope and only re-point `$assigned` at the return value: fixes the reported number, not the broken feature (still 0 rows).
- Fallback behaviour: an exception inside `assign()` still lands in the endpoint's existing `catch (Throwable)` → `tester_assigned_features: -1` (pre-existing sentinel), linking itself unaffected.

## Files changed

- `api/plans/index.php` — the `planaddtc/save` assignment call + truthful `tester_assigned_features` (commit `6a7d915e6`).
- `tmp/fixtures_1922.php` — fixture (project/plan/suite/TC linked/TC fresh/open build), git-ignored.
- `tmp/TLU_Test_Cases.md` — suite *Regression — Issue #1922* (gate: 7 PASS / 0 FAIL, own suite present, 211 → 212 suites).
- `CHANGELOG` — 2.0.1 KEY BUGFIX entry.
- `docs/screenshots/issue-1922-eventviewer-prefix-warnings.png`, `docs/screenshots/issue-1922-planaddtc-assign-after.png`.

## Verification

Matrix executed on the fresh fixture (admin/admin, session plan context via `/api/plannav/index.php/init`, `X-Requested-With` CSRF hint), commit `6a7d915e6`:

| # | Case | Result |
|---|---|---|
| 1 | fresh TC + `testerID:1` + `build_id:1` (the issue's repro) | PASS — `added:1`, `tester_assigned_features:1`, **1 row** in `user_assignments` (`feature_id=3, user_id=1, type=1 testcase_execution, status=1 open, build_id=1`), **0** new `log_level<16` events |
| 2 | repeat save (dup-skip path) | PASS — `added:0`, `tester_assigned_features:0`, no new row, 0 warnings |
| 3 | fresh TC, save **without** `testerID` | PASS — `added:1`, `tester_assigned_features:0`, no row |
| 4 | `remove` only | PASS — `removed:1`, audit only (log_level 16), 0 warnings |
| 5 | fresh TC + `send_mail:true` (Issue #1920 regression) | PASS — `added:1`, 2nd row written, 0 mail-path warnings |
| 6 | Event Viewer gate (`events.log_level < 16` after baseline) | PASS — **0** new rows; newest E_WARNINGs remain the pre-fix ids 4-6 |
| 7 | browser (modern `planAddTCView.html` assign panel → tester + build → Save changes) | PASS — 3rd row written, 1 audit event, 0 warnings, no JS errors (only standard a11y lint) |
| 8 | `php -l api/plans/index.php` | PASS |

Code review subagent over the diff: **APPROVE** (correctness / send_mail+JSON contract / legacy parity / PHP warnings / style all clean).
