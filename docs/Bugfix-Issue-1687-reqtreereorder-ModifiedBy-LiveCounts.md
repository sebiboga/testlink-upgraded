# Bugfix — Issue #1687: `reqTreeReorder.html` — "Modified by" was a placeholder and requirement counts used denormalized values

**Number:** #1687  
**Status:** Fixed (verified; code already correct in repo, regression suite added, closed with evidence)  
**Component:** `gui/templates/requirements/reqTreeReorder.html` + `api/reqtreereorder/index.php`  
**Area:** Requirements / Specification Tree Reordering  

## Symptom

The Context card showed:
- **Modified by** tile always rendered `-` (placeholder) — never populated from the spec revision author
- The issue also noted `specHeader()` selected `V.total_req` (denormalized). The Requirements tile deliberately uses the live count (actual number of requirement rows), which is the correct choice for a reorder screen

## Repro
1. Load fixtures (fixtures_1681.php): tproject 12, spec 13 with 3 requirements, rev 1
2. Open `/gui/templates/requirements/reqTreeReorder.html?tproject_id=12&req_spec_id=13`
3. Observe Context → Modified by is `-` in the buggy state (historical); Requirements/ dropdown show live counts

## Root cause
- UI: `$('#mWho').text('-')` hardcoded (not derived from API response)
- API: `specHeader()` did not select revision author (`V.author_id`, `U.login`)

## Fix (already present in codebase)
- `api/reqtreereorder/index.php::specHeader()` now selects `V.author_id, U.login AS author_login` via `LEFT JOIN users U ON U.id = V.author_id` and latest revision subquery; returns them
- `api/reqtreereorder/index.php::init()` includes `context.author_id` and `context.author_login`
- `gui/templates/requirements/reqTreeReorder.html` renders `ctx.author_login` with fallbacks to `'#'+author_id` then `'-'`
- Live count (`COUNT(1) FROM requirements C WHERE C.srs_id = RS.id`) used for spec totals (correct; denormalized `total_req` unused)

## Verification
- API: `context.author_id=1, context.author_login="admin"` for spec 13
- UI: Modified by shows `admin`; Requirements shows `3`; spec dropdown shows live counts `(3)`, `(0)`
- Edge cases: missing/deleted author → fallback chain works; empty spec shows `0`
- Event Viewer: no new errors

## Regression test
See `tmp/TLU_Test_Cases.md` — "Regression — Issue #1687: reqTreeReorder.html - Modified by and live requirement counts" (gate: TLU_REQUIRE_SUITE="Issue #1687" passes)

## Screenshots
N/A (functional fix; behavior verified via API + DOM)

## Files changed
- `api/reqtreereorder/index.php` (specHeader/response shape - already fixed)
- `gui/templates/requirements/reqTreeReorder.html` (context population - already fixed)
