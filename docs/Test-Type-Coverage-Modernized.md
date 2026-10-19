# Test Type Coverage matrix — Modernized (TestLink 2.0.1, Refs #1945, parent #1052)

Net-new in-app **test type coverage matrix** for TestLink 2.0.1, third entry of
the **Test Strategy** ASIDE section (right after *Test Strategy Documents*).
TestLink 1.9.20 shipped no test-type tracking entity at all — the Test Strategy
area only held the static prose chapter `gui/templates/strategy/testTypes.html`,
so *"which test types does this project actually apply, and how far along are
they?"* was never capturable. This screen is a project-scoped matrix over the 13
canonical test types of the #1052 catalogue (6 functional + 7 non-functional),
each with a lifecycle status, an owner and a description. Tracking issue
[#1945](https://github.com/sebiboga/testlink-upgraded/issues/1945), parent feature
[#1052](https://github.com/sebiboga/testlink-upgraded/issues/1052).

## Why this screen

- Parent enhancement **#1052** (test types / test type catalogue). The catalogue
  itself landed earlier; nothing yet recorded *per project* which of those types
  are actually applied.
- The ledger's TODO section is empty (every ASIDE entry already maps to a modern
  `.html` + BFF), so a genuine net-new Test Strategy screen was taken instead of
  a legacy port — this behaviour simply does not exist in 1.9.20.
- Test managers need an answer for audits and strategy reviews: coverage gaps
  (a type never planned), work in flight and who owns it.

## Modern stack

| Layer | File |
|---|---|
| Screen | `gui/templates/strategy/testTypeCoverage.html` (Dashio standalone HTML/JS/CSS) |
| BFF | `api/testtypecoverage/index.php` (`GET ?action=init\|list`, `POST ?action=save\|delete`; session auth + same-origin guard + session-inactivity check) |
| Wiring | `$actions->testTypeCoverage` in `lib/functions/common.php`, consumed by `api/aside/index.php` section 5 (Test Strategy) |
| Schema | `tc_test_type_coverage` — in `install/sql/mysql/testlink_create_tables.sql`, mirrored by a lazy `CREATE TABLE IF NOT EXISTS` in the BFF, plus the `object.class.php` table whitelist |
| i18n | `ttc.*` (**64** keys, including the 13 `ttc.type.*` catalogue labels) + `footers.testTypeCoverage` in **all 10** client bundles; `$TLS_href_test_strategy_coverage` in **all 19** server locales |

## Content

- **Project chip** — resolved project name and prefix of the addressed
  `tproject_id` (deep-linked, session project as fallback).
- **Metric tiles** — total types (13), tracked, done, in progress, planned and
  not planned; recomputed live after every save/reset.
- **Toolbar** — Refresh, a group filter (All / Functional / Non-functional), a
  status filter (All / Not planned / Planned / In progress / Done / Not
  applicable) and a project info chip.
- **Coverage table** — one row per catalogue type in catalogue order: type
  (localized `ttc.type.<key>` label + machine key chip), group badge, status
  badge, owner, description tooltip, last update, and the actions View / Edit /
  Reset (Reset only on stored rows, and only for users with the modify right).
  DataTables provides search, sort and pagination with a translated empty state.
- **Edit modal** — read-only type field (key in parentheses), status select,
  owner (128 chars) and description (15 000 chars, byte-capped for the TEXT
  column), with Save / Cancel and a localized toast on success.
- **Details modal** — type, key, group, status, owner, description, creation and
  update timestamps of the stored row (defaults are shown as "not set").
- **Reset confirm** — `Reset "<type>" to its default values?`; the reset DELETES
  the stored row so the row falls back to the catalogue defaults
  (`not_planned`, empty owner/description).

## State cards

Machine-code state cards replace raw failures: **401** (session — *Log in* →
`/login.php?note=expired`), **403** (*No permission*, matrix hidden), **404**
(*No test project* — addressed project does not exist) and a generic load-error
card with the raw code. The page content is hidden while a state card is shown.

## Rights model

Rights are evaluated on the **addressed** test project *before* it is resolved
(the #1697 existence-oracle lesson):

- reads require `mgt_view_tc` **OR** `mgt_modify_tc`;
- writes require `mgt_modify_tc`;
- a read-only user (e.g. role *tester*) sees all 13 rows with **View** only —
  no Edit, no Reset;
- `norights` (role 3) gets the 403 state card.

## BFF contract

`GET /api/testtypecoverage/index.php?action=init&tproject_id=N` →

```json
{ "status":"ok",
  "context":{"tproject_id":1,"tproject_name":"TCO1945","tproject_prefix":"TC4",
             "can_view":true,"can_modify":true},
  "domains":{"statuses":["not_planned","planned","in_progress","done","not_applicable"],
             "groups":["functional","non_functional"]},
  "stats":{"total":13,"tracked":3,"done":1,"in_progress":1,"planned":1,
           "not_planned":10,"functional":6,"non_functional":7},
  "items":[{"id":0,"type_key":"functional","group":"functional",
            "label":"Functional testing","status":"not_planned",
            "description":"","owner":"","is_stored":0,
            "created_by":0,"creation_ts":"","update_ts":""} ...] }
```

`GET ?action=list` returns the same `items`. `POST ?action=save`
`{tproject_id, type_key, status[, description, owner]}` is a true **upsert by
`type_key`** (Created/Saved); `POST ?action=delete {tproject_id, type_key}`
resets a stored row to its defaults.

Errors: `400 no_testproject|unknown_action|bad_status`,
`401 not_authenticated`, `403 no_right` (+ the same-origin proof required by
`bffSameOriginGuard()` on state-changing methods), `404
tproject_not_found|type_not_found|not_tracked`, `405 wrong_method`
(`Allow: GET, HEAD, POST`), `500 server_error`. Writes emit `TESTTYPE_SAVE` /
`TESTTYPE_RESET` AUDIT events for the Event Viewer.

## Verification

- Browser suite **Issue #1945 = 32/32 PASS** (append-only suite in
  `tmp/TLU_Test_Cases.md`; gate `TLU_REQUIRE_SUITE="Issue #1945"
  bash ai/verify_test_suites.sh` → 34 PASS / 0 FAIL, no suite lost).
- `bash ai/verify_i18n_coverage.sh` → 9/9 bundles, 8907 keys, 0 missing.
- BFF contract exercised end-to-end with curl (create/update/reset/400/401/403/
  404/405/HEAD/array-parameter), plus browser checks of every button, modal,
  filter, search, locale, state card and the read-only role path.
- Event Viewer clean (only AUDIT `LOGIN`/`TESTTYPE_SAVE`/`TESTTYPE_RESET` rows,
  zero ERROR/WARNING); console clean on the screen.

## Notes

- The catalogue labels travel from the server in English and are mapped
  client-side to `ttc.type.<key>` so every locale shows a translated type name;
  a bundle without the key falls back to the server label.
- The type column links to the details modal; edit/reset handlers receive their
  argument through a JSON-string-escaped inline handler (`jsArg()`), not through
  plain `esc()` — HTML entity decoding happens before JS parsing.
- Resetting a row is a DELETE, not a status change: the row leaves the table and
  the tile counters immediately reflect the catalogue defaults.
