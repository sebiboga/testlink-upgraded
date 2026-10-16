# Bugfix Issue #1926 — `testUrgency.html`: bare project deep-link left `tplanId = 0`, suite table stayed empty (`400 NO_TPLAN`)

Class of defect: the client dropped a value the server had already normalized — on a bare
`?tproject_id=` deep-link the BFF substitutes the project's default plan and returns it as
`tplan` in the payload, but the screen only mirrored that id into the `<select>` and never
updated the module-level `tplanId`, so every later request went out with `tplan_id=0`.

## Symptom

Opening the modern **Set Test Urgency** screen with only `tproject_id` in the URL (no
`tplan_id`) rendered the plan selector correctly (default plan selected) and populated the
suite list, but the right-hand test-case table stayed **empty** and the footer was blank. The
browser console logged one `400 (Bad Request)`.

- `window.tplanId` → `0` while `#tplanSel.value` → `"<default plan id>"`.
- `GET /api/plans/index.php/urgency/suite?tplan_id=0&tproject_id=1&tsuite_id=4…` → `400`
  `{"status":"error","message":"Invalid test plan id","error_code":"NO_TPLAN"}`.
- URL rewritten by the screen to `…?tproject_id=1&tplan_id=&build_id=12&platform_id=` (empty `tplan_id`).

Navigator deep-links normally carry `tplan_id`, so only a bare project link showed the defect
(priority minor/major).

## Root cause chain

1. URL has only `tproject_id` → `gui/templates/plans/testUrgency.html:149` parses `tplanId = 0`.
2. `load()` (`:236`) sends `GET /urgency?tproject_id=1&tplan_id=0…`.
3. The server **normalizes** the bare link: `api/plans/index.php:2402-2416` keeps `tplan_id=0`,
   then `:2417-2426` sets `$tplan_id = intval($tplans[0]['id'])` and builds `$tplanInfo` — the
   response carries `tplan = {id: 2, …}` plus the suite rows for that plan.
4. `render()` (`:242`) at `:251` (pre-fix):
   ```js
   if (r.tplan && r.tplan.id > 0) { $sel.val(String(r.tplan.id)); }
   else { tplanId = parseInt($sel.val() || '0', 10); }
   ```
   The truthy branch only mirrors the id into the `<select>`; it **never assigns `tplanId`**,
   which stays `0`. The `else` branch — the only one that reassigns `tplanId` — can never run
   when a default plan exists.
5. `syncUrl()` (`:273`) persists the stale empty `tplan_id` back into the address bar.
6. `renderSuites()` (`:286`, `:319`) auto-selects the first suite → `selectSuite(4)` (`:322`)
   issues `GET /urgency/suite?tplan_id=0…`.
7. `api/plans/index.php:2246-2253` `urgencyNeedCtx()` rejects `tplan_id <= 0` → `400`
   `NO_TPLAN` → `apiFail()` toast, `#tcRows` stays empty.

Regression source: the `render()` plan-selector sync was written without propagating the id
into the JS state; the `else` branch proves the intended contract (adopt the select value when
no plan came back), but the default-plan-returned branch was left incomplete.

## Blast radius

Only `gui/templates/plans/testUrgency.html`. `grep -n "r.tplan && r.tplan.id > 0"` finds the
same *shape* in `planUpdateTC.html:287` and `platformsAssign.html:215`, but both servers return
`tplan: null` (not a default plan) for a bare project link
(`api/plans/index.php:2851`; `api/platforms/index.php:619` `$tplan_id > 0 ? … : null`), so those
screens take their `else`/pick branch and `tplanId` is not needed. **No sibling bug** — no new
issues filed.

## Fix and why this method

One line, in the branch that already trusts the server's choice
(`gui/templates/plans/testUrgency.html:251`, commit `86e7dc2d6`):

```diff
-  if (r.tplan && r.tplan.id > 0) { $sel.val(String(r.tplan.id)); }
+  if (r.tplan && r.tplan.id > 0) { tplanId = parseInt(r.tplan.id, 10); $sel.val(String(r.tplan.id)); }
```

- Adopt the id the server normalized — the response is the single source of truth for the
  default plan; the screen already trusts `r.build_id` / `r.platform_id` the same way (`:255`,
  `:265`).
- **Rejected alternative A** — read the plan back from `#tplanSel.value` after setting it:
  equivalent result but re-reads the DOM in the branch that just wrote it; the `else` branch
  already covers the "no plan from server" case with exactly that read.
- **Rejected alternative B** — change the BFF to redirect / force a plan: wrong layer, the
  endpoint is already correct and intentionally returns the chosen default.
- **Rejected alternative C** — derive the plan from `r.tplans[0]` client-side: duplicates the
  server's selection logic and would drift from it.
- No user-facing string changed → no i18n bundle touched. Fallback behaviour unchanged: when the
  server returns no plan, `else` still adopts the `<select>` value.

## Files changed

- `gui/templates/plans/testUrgency.html` — 1 line (`:251`), commit `86e7dc2d6`.
- `tmp/fixtures_1926.sql` — fixture (project `URG Project`, plans `URG Plan A` default /
  `URG Plan B`, suite A + B, 3 directly-linked TCs, build), git-ignored.
- `tmp/TLU_Test_Cases.md` — suite *Regression — Issue #1926* (gate: `33 PASS / 0 FAIL`, own
  suite present, 893 → 894 suites), commit `25f91529e`.
- `CHANGELOG` — 2.0.1 KEY BUGFIX entry.
- `docs/screenshots/issue-1926-testurgency-before.png`, `docs/screenshots/issue-1926-testurgency-after.png`.

## Verification

Matrix executed on fixture `tmp/fixtures_1926.sql` (admin/admin, `http://localhost:8082`),
commit `86e7dc2d6`:

| # | Case | Result |
|---|---|---|
| 1 | bare `?tproject_id=1` (the issue's repro) | PASS — `window.tplanId === 2`, suites `[4,5]`, `#tcRows` = 2 rows, footer `2 test cases linked`, URL corrected to `…&tplan_id=2&…`, `/urgency/suite?tplan_id=2…` → 200, 0 console messages |
| 2 | no query params | PASS — first accessible project recovered via `/api/projects`, screen usable |
| 3 | full link `?tplan_id=3` (plan B, no linked TCs) | PASS — plan B selected, empty-state message, no error |
| 4 | plan switch via `#tplanSel` | PASS — `tplanId` follows the select (`3`), URL updated |
| 5 | suite click (suite B) | PASS — 1 row, footer `1 test cases linked` |
| 6 | build selector | PASS — `URG Build 1` present and selected |
| 7 | radio change → **Set Urgency for Test Cases** | PASS — toast `Urgency was updated`; DB `testplan_tcversions` id 15 `urgency` 1 → 3; priority recalculated to `Medium (3)` |
| 8 | invalid `?tplan_id=9999` | PASS — server falls back to default plan 2, screen usable, URL corrected |
| 9 | Event Viewer gate (`events.log_level`) | PASS — only the login audit row (`log_level=16`), no Error/Warning |

BEFORE / AFTER screenshots (bare link `?tproject_id=1`, pre-fix file vs fixed file) are
attached to the GitHub Wiki page and stored in `docs/screenshots/` as
`issue-1926-testurgency-before.png` and `issue-1926-testurgency-after.png`.
