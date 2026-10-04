# Bugfix — Issue #1690: `reqTreeReorder.html` — the "Back to specification management" link pointed at the WRONG test project, and its fallback was a dead end

**Number:** #1690
**Status:** Fixed (verified — the reported hardcoded id was already fixed in the default branch; this run
re-measured it, then fixed the residual its own fallback left behind)
**Component:** `gui/templates/requirements/reqTreeReorder.html`
**Area:** Requirements / Specification Tree Reordering
**Fixing commit (code):** `d66f0feb7` — *fix(#1690): reqTreeReorder back link - derive the project from
the referrer when the URL carries none*
**Verification commit (suite):** `b07732b72`

## Symptom

Two defects, one control.

1. **The reported one — the wrong project.** `#1686` replaced `href="#"` by baking a real target into the
   **static** markup: `<a class="btn-ghost" id="backLink" href="/gui/templates/requirements/reqSpecMgmt.html?tproject_id=13">`.
   `reqTreeReorder.html` is not PHP and is served to *every* test project, so the literal `13` — the id of
   the fixture project the screen was developed on — was correct for exactly one project. Measured on
   project 60: the page header said **Project 60** while *Back* carried **`tproject_id=13`**, and following
   it landed on `"Project 13 - Requirement Specification Management"`.
2. **The residual this run fixes — the fallback is a dead end.** The corrective commit `1ba5015e7` made
   the markup fallback the **id-less** `/gui/templates/requirements/reqSpecMgmt.html`, which its own
   comment calls "always a valid, in-app destination". It is valid, but on the one state it exists for
   (`TPROJECT_ID = 0`, i.e. `reqTreeReorder.html` opened with no `tproject_id`, where `load()` paints
   `MISSING_TPROJECT`) it is **unusable**: `reqSpecMgmt.html` reads the project from the URL only
   (`:495`) and has **no project selector**, so it renders `Test Project: -` with every data dropdown
   empty. The escape hatch of a dead end was a second dead end.

## Root cause

| Hop | Location | What was wrong |
|---|---|---|
| 1 | `reqTreeReorder.html:166` | `TPROJECT_ID = parseInt(q.get('tproject_id') \|\| '0', 10)` → `0` (and `NaN` for `?tproject_id=abc`) when the URL carries no project |
| 2 | `reqTreeReorder.html:181-186` (pre-fix shape, `1ba5015e7`) | `buildBackLink()` had nothing to put in the href, so it fell back to the id-less `reqSpecMgmt.html` |
| 3 | `reqTreeReorder.html:471-473` | `load()` bails out on the *same* condition → `MISSING_TPROJECT`, so this is not a hypothetical state: it is exactly the state that fallback is written for |
| 4 | `reqSpecMgmt.html:495` → `:518` | the target loads with `tproject_id=0`; `action=options&tproject_id=0` returns nothing and no project can be chosen |

Both requirements were again conflated: *valid destination* (not a self-loop, not a foreign project) was
treated as *usable destination*. The residue is a control that is syntactically right and functionally
stuck.

### Why the issue stayed open

The reported defect was fixed by `1ba5015e7` (Refs #1681), an ancestor of the default branch; the
implementing run left the issue **OPEN** and documented the fix in the issue body, so triage kept
surfacing it as the oldest open bug. Nothing was lost in the code for hop 1 — what was lost was the
closure step, plus the verification that would have exposed hop 2.

## Fix

* **URL first, always.** `TPROJECT_ID > 0` wins, unchanged — the verified success and 404 paths are
  bit-identical to the state certified for `#1686`/`#1690`, so this cannot regress them. A `NaN`
  (`?tproject_id=abc`) correctly fails this test and falls through instead of poisoning the href.
* **Same-origin referrer as the last resort** (new `referrerTprojectId()`): when the URL carries no
  usable project, the `tproject_id` of the page the user came from is the honest "back" target — it is
  the project they were working in.
* **Foreign referrers are rejected** by comparing the parsed anchor's `origin` with
  `window.location.origin` (measured: `https://evil.example.com/x?tproject_id=99 → 0`,
  `http://127.0.0.1:8082/…?tproject_id=60 → 0`). Pointing the escape hatch at another project is
  precisely what #1690 was filed for, so the guard is conservative: *no usable same-origin referrer →
  the id-less link stays, i.e. the previous behaviour*.
* **Everything else untouched:** still synchronous, still inside the existing `$(function(){ … })` at
  DOM-ready (`:218-219`), still **before** `TLi18n.load()` and before `load()`'s `init` call, so no API
  outcome can degrade the link. No new i18n key, no backend change, no schema change.

### Alternatives rejected

1. **Add `?action=context` to the BFF and fetch the session project** — correct, but it puts an async
   call on the path whose entire value is that it does *not* depend on the API, and adds a route for a
   corner case.
2. **Add a project selector to `reqSpecMgmt.html`** — correct, but that is a different screen and a
   feature, not this bugfix.
3. **Restore a hardcoded id** — the exact defect #1690 was filed for.

## Verification (measured, 9/9 cases PASS)

Fixtures: `tmp/fixtures_1690.sql` — a fresh run DB has **0** test projects and one project is not enough
(the defect is only visible on a project other than the hardcoded one), so two are created:
`13 TL` / `60 ALT`, spec `21 SRS-A` in 13, spec `61 SRS-B` in 60.

| Case | Measured result |
|---|---|
| Pre-fix (`git show 1ba5015e7^:…`) | `href=…reqSpecMgmt.html?tproject_id=13` while the header read *Project 60*; the link led to `"Project 13 - Requirement Specification Management"` — **symptom reproduced** |
| Post-fix, success path `?tproject_id=60&req_spec_id=61` | `href=…?tproject_id=60`, target `200`, *Project 60*, 3 rows, chip `SRS-B` |
| 404 path, referrer says 13 | `href=…?tproject_id=60` — URL wins over the referrer; `apiStatus 404`, `stateCode req_spec_not_found` |
| URL `13` while the referrer says 60 | `href=…?tproject_id=13` — URL wins again (2 rows, chip `SRS-A`) |
| **No project in the URL**, same-origin referrer with project 60 | `href=…?tproject_id=60` while `stateCode MISSING_TPROJECT` — **this is the fix**; pre-fix it was id-less |
| Referrer without a project | id-less — last resort unchanged |
| Cross-origin referrer | id-less; guard unit-checked in-page: `sameOrigin→60`, `evil.example.com→0`, `127.0.0.1→0`, `NaN→0`, empty→0 |
| `?tproject_id=abc` + referrer project 60 | `TPROJECT_ID === NaN`, `href=…?tproject_id=60` — `NaN` does not win |
| Screen still functions | reorder ↓ on row 1 → *Apply order* → *"The new order was saved."*; `nodes_hierarchy.node_order` 1,2,3 → 0,1,2 (id 9062 first) |
| Event Viewer / console | `events` = 1 row, the `log_level 16` `audit_login_succeeded` audit — **zero** Error/Warning; console empty |

Gates: `node --check` on the extracted inline script → PASS; `git diff` empty after restoring the file
the pre-fix measurement temporarily swapped in → PASS; suite gate
`TLU_REQUIRE_SUITE="Issue #1690" bash ai/verify_test_suites.sh` → **7 PASS / 0 FAIL / 0 SKIP**
(suite headings 78 → 79, nothing lost).

Regression suite: `## Regression — Issue #1690` in `tmp/TLU_Test_Cases.md` (9 cases).

## Files changed

| File | Purpose |
|---|---|
| `gui/templates/requirements/reqTreeReorder.html` | `buildBackLink()` + new `referrerTprojectId()` — the only production change (+26/-3) |
| `tmp/fixtures_1690.sql` | two test projects + specs + requirements, so "wrong project" is observable |
| `tmp/TLU_Test_Cases.md` | appended regression suite (append-only, `>>`) |
| `docs/Bugfix-Issue-1690-reqtreereorder-Back-Link-Wrong-Project.md` + screenshot | this page |
| `CHANGELOG` | one-line entry under `2.0.1 (in-progress)` |

## Commits

* `d66f0feb7` — the fix (code + fixtures), `Refs #1690`
* `b07732b72` — regression suite `Issue #1690`
* docs + wiki mirror commit for this run

## Reference

* Issue: <https://github.com/sebiboga/testlink-upgraded/issues/1690>
* The reported defect's own fix: `1ba5015e7` — *fix(reqtreereorder): the back link's tproject_id was
  hardcoded to 13 (Refs #1681)*
* Screen page: [Reorder Requirements Tree (reqTreeReorder) Modernized](Reorder-Requirements-Tree-Modernized.md)
* Sibling bugfixes in the same screen, same testing pass:
  [#1686 — back link dead self-reload](Bugfix-Issue-1686-reqtreereorder-Back-Link-Dead-Self-Reload.md),
  [#1688 — toolbar live on the 403/404 page](https://github.com/sebiboga/testlink-upgraded/issues/1688),
  [#1689 — read-only rows still draggable](https://github.com/sebiboga/testlink-upgraded/issues/1689),
  [#1691 — no latest-version filter, a revised requirement listed once per version](https://github.com/sebiboga/testlink-upgraded/issues/1691).
