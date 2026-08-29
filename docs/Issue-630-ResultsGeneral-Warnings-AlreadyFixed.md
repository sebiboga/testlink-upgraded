# Issue 630 — resultsGeneral.php 6x E_WARNING on every view (tprojOpt / items2loop)

Issue: https://github.com/sebiboga/testlink-upgraded/issues/630
Status: **verified already fixed on the default branch** — no code change needed in this run.

## Symptom (as reported, 2026-08-23T11:19Z)

Every view of **General Test Plan Metrics** logged ~6 E_WARNING entries into the
Event Viewer (observed 11:02 and 11:18 on 23/08/2026):

- `Undefined variable $items2loop`
- `foreach() argument must be of type array|object, null given`
- `Undefined property: stdClass::$tprojOpt`
- `Attempt to read property "testPriorityEnabled" on null`

## Outcome

**The symptom is not reproducible on the current default branch.** The defect was
already root-caused and fixed by two earlier runs:

| Warning family | Fix | Commit |
|---|---|---|
| `$items2loop` undefined (`lib/results/resultsGeneral.php:50` initializer) | #633 | `73d9c7c97` |
| `$gui->tprojOpt` property mismatch in `gui/templates/dashio/results/resultsGeneral.tpl` (now `testprojectOptions`, lines 179/247) | #633 | `73d9c7c97` |
| `testPriorityEnabled on false` (empty `testprojects.options`) | #581 | `560fd906a` |

This report predates the #633 fix by ~3.5 h, so its observed events come from the
exact code path #633 fixed. See also
[Issue-633-ResultsGeneral-EWarnings-NoPlatform.md](Issue-633-ResultsGeneral-EWarnings-NoPlatform.md)
and `Bugfix-Issue-582-ResultsGeneral-ForEachNull-TprojOpt.md` for the full root-cause
chains.

## Verification performed (2026-08-29)

Fixtures: `php tmp/fixtures_424.php` → tproject 1, plan 9 (no platforms, priorities
OFF); `php tmp/fixtures_633_r2.php` → tproject 10, plan 18 (platform PLAT-633 linked,
priorities ON). `events` table cleared between runs.

- Plan 9 `format=0` view → HTTP 200, renders, **0 rows `log_level<=2`**.
- Plan 18 `format=0` view → HTTP 200, "Results by Platform" + "Results by priority"
  sections render, **0 warning rows**.
- XLS exports (`format=3&spreadsheet=1`) for both plans → HTTP 200, **0 warning rows**.
- Mail flows (`format=6&sendByEmail=1`) for both plans → HTTP 200, **0 warning rows**.
- Edge: priorities ON + no platforms → HTTP 200, **0 warning rows**.
- Compiled template `gui/templates_c/74967ec9…_file.resultsGeneral.tpl.php` regenerated
  and contains **0 `tprojOpt` references** (grep clean on source + compiled artefact).
- Control test proving the error→event pipeline is live: an unsupported-format request
  (`format=1`, FORMAT_ODT) produced exactly one `E_WARNING … displayMgr.php:232` row ⇒
  the zero-row results above are genuine, not a disabled handler.

## New bug found during verification

`format=1/2/5` (ODT/ODS/PDF — not offered by the UI) hit `$file_extensions[$format]`
with a missing key at `lib/results/displayMgr.php:232`. Filed as
[issue #767](https://github.com/sebiboga/testlink-upgraded/issues/767) (minor, latent,
hand-crafted URLs only). Not in this issue's scope — not silently fixed.

## Files touched in this run

- `tmp/TLU_Test_Cases.md` — regression suite 630 (8/8 PASS).
- `docs/Issue-630-ResultsGeneral-Warnings-AlreadyFixed.md` — this doc mirror.
- Wiki page `Issue-630-ResultsGeneral-Warnings-AlreadyFixed.md` (+ screenshots).