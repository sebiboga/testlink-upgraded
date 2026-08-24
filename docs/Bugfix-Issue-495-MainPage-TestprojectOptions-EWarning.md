# Issue 495 — mainPage.php E_WARNING `Undefined array key "testprojectOptions"` (line 424)

**Issue:** [#495](https://github.com/sebiboga/testlink-upgraded/issues/495)
**Branch:** `fix/issue-495` · **Fix already landed in:** `38c7d6f16`, `3071beefb`, `3266f4329`, `8748128fb`
**Status:** VERIFIED-FIXED & CLOSED (2026-08-24) — no new code needed; ticket closed with verification evidence

## Symptom

Event Viewer entry reported in the issue (2026‑08‑18 11:15:03):

```
E_WARNING Undefined array key "testprojectOptions" - in ...\lib\general\mainPage.php - Line 424
```

Every render of the main page (`lib/general/mainPage.php`) emitted the warning under PHP 8.

## Root cause chain

1. `getGrants()` inside `mainPage.php` read `$_SESSION['testprojectOptions']->inventoryEnabled`
   unconditionally. The session key `testprojectOptions` is **never written anywhere in this
   codebase** (`grep -rn "\['testprojectOptions'\] *=" lib/ www/` → 0 hits), so PHP 8 threw
   `Undefined array key` on every mainPage load.
2. **Fix wave 1** — `38c7d6f16` (2026‑08‑18) added exactly the guard proposed in the issue body:
   `isset($_SESSION['testprojectOptions']) && $_SESSION['testprojectOptions']->inventoryEnabled`.
   `3071beefb` applied the same double-guard to the sibling read at lines 187–189
   (`$gui->opt_requirements`).
3. **Supersession (current behavior)** — `3266f4329` + `8748128fb` (2026‑08‑21) replaced the dead
   session-key logic: `getGrants()` now reads LIVE options from the DB via
   `testproject::getOptions($tproject_id)` and normalizes inventory grants to int `1/0`. Rationale:
   an `isset()` on a never-set key made that branch dead code and let raw `'yes'/'no'` strings from
   `hasRight()` leak into grants — `'no'` is truthy, which rendered the Inventory link while
   inventory was disabled. Current code: `lib/general/mainPage.php:530-550`.

## Verification performed (2026-08-24)

Environment: fresh DB (`events` empty at baseline), app http://localhost:8082, admin/admin,
headless Chrome. Measured after EVERY step via `SELECT id,log_level,description FROM events WHERE log_level>16`.

| Path exercised | Result |
|---|---|
| Blind-folded path: fresh login, no testproject in session → getGrants(tproject_id=0), redirect to project create | 0 warnings |
| First test project created through UI ("Issue495 Project", prefix I495, id=1) | audit only |
| mainPage render with active project (`index.php?tproject_id=1&tplan_id=0`) | 0 warnings |
| Reload / project-switch path | 0 warnings |

Final sweep: the only warning-level rows in the entire `events` table were ids 3–4 produced by a
DELIBERATE reproduction of the sibling bug (loading `freeTestCases.php` directly). Zero events are
sourced from `mainPage.php`. Static check confirms every remaining `$_SESSION['testprojectOptions']`
access in `mainPage.php` is guarded (lines 187–189).

Regression suite: `tmp/TLU_Test_Cases.md` → **Suite 495 — 8/8 PASS**.

## Sibling bugs (out of scope here)

The same never-written session key is still read UNGUARDED in other screens — filed separately as
[#679](https://github.com/sebiboga/testlink-upgraded/issues/679):

```
lib/results/freeTestCases.php:25        (live-reproduced: Undefined array key + property-on-null)
lib/results/tcNotRunAnyPlatform.php:116,216,228
lib/plan/tc_exec_assignment.php:222
gui/templates/dashio/plan/tc_exec_assignment.tpl:175,221,229
```

The recommended fix there is the same pattern this issue ended with: drop the dead session key and
read live options via `testproject::getOptions($tproject_id)`.
