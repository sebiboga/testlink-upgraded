# Modernize: Move / Reorder Test Suites (`suiteMove`) — Refs #1740

Modern screen: `gui/templates/testcases/suiteMove.html`
BFF: `api/suitemove/index.php`
Entry point: Test Specification toolbar → **Move / Reorder Test Suites** (`testSpec.html`)
Legacy predecessor: `lib/ajax/dragdroptreenodes.php`

## Why

In 1.9.20 the move / re-parent / re-order of a test suite was performed by the ExtJS tree itself
through `lib/ajax/dragdroptreenodes.php` — a **GET** request that called `tree::move_node()` and
wrote `nodes_hierarchy.parent_id`. It had

* no rights check (`mgt_modify_tc` was never consulted),
* no proof that the moved node or the destination node belonged to the project,
* no same-origin check, so any bookmark, crawler or cross-site `<img>` hit could re-parent a suite.

The endpoint is now a **non-mutating** session-guarded shim (browser → 302 to the modern screen,
XHR → an escaped fragment); its legacy writes are never executed.

## API

| Route | Purpose |
|---|---|
| `GET ?action=init&tproject_id=N&container_id=M` | context (project `name (prefix)`, container, rights `can_modify`) + the ordered sub-suites with test-case / sub-suite counts |
| `GET ?action=suites&tproject_id=N[&exclude_id=K]` | the depth-indented list for the destination picker (excluding `K` **and its whole subtree**) |
| `POST ?action=move` | `{node_id, new_parent_id, position: top\|bottom}` |
| `POST ?action=reorder` | `{container_id, nodelist: "id,id,…"}` |

Contract: `401` anonymous, `403` no right / CSRF, `404` unknown or foreign node or project,
`400` bad parameter, `405` wrong verb, `409` cycle, and a no-op is a **success**
`200 {"status":"no_change"}` (same as `tcreorder`, `tcstepsreorder`, `reqtreereorder`).

## Guarantees

* `mgt_modify_tc` on the **owning** project.
* Every id proven to be a `node_type_id = 2` node of that project (the ownership walk also proves
  a suite really hangs off a test project).
* A container can never be moved into its own subtree (cycle guard).
* The destination picker walks **down** from the project node, so the walk itself proves
  ownership — no per-suite query (`#1757`).

## Bugs found while testing

| Issue | Defect |
|---|---|
| #1742 | every suite listed twice in the picker |
| #1743 | "move to first/last" refused for every legitimate move (`node_order` is not dense in 2.0.1) |
| #1744 | empty project name in the context bar |
| #1745 | **cycle guard inverted** — moving a container into its descendant detached the subtree |
| #1746 | a real reorder answered `no_change` |
| #1747 | `can_modify` ignored, write actions always rendered |
| #1748 | one `E_WARNING: Undefined array key "node_type_id"` per row |
| #1750 | toolbar tooltip reused a row-button title |
| #1751 | another agent's commit deleted all `smv.*` keys from the 10 bundles |

## Mandatory code-review fixes

| Issue | Defect |
|---|---|
| #1752 | the container picker filtered the **current** container out of its own selector |
| #1753 | a no-op answered `400 status:error`; the reorder no-op was decided after the write |
| #1754 | `fill()` re-escaped `TLi18n.t()`'s output — `R&D Suite` → `R$$&D Suite` |
| #1755 | `BUSY` latched forever on an unhandled path, disabling every action |
| #1756 | a write after the session timeout printed a raw server string instead of the login page |
| #1757 | the picker was O(all suites × depth) across the whole installation |
| #1758 | a malformed `new_parent_id` was silently degraded into an in-container reorder |
| #1759 | `403` on a foreign suite leaked that node's existence (now `404`) — see [Bugfix-Issue-1759](Bugfix-Issue-1759-SuiteMove-403-Leaks-Foreign-Suite) |

## Bugfix #1751 — `smv.*` i18n keys verified restored in all 10 bundles

Concurrency clobber: commit `9ca0fa7bb` ("feat(tcview): full platforms panel", Refs #1037) rewrote
all ten `gui/templates/i18n/*.json` from a stale base, so every `smv.*` key another agent had added
in `0674bbdd5` was dropped (`git show 9ca0fa7bb:gui/templates/i18n/en.json` counts `smv.*` = **0**).
`TLi18n` then fell back to the raw key / hardcoded English (`gui/templates/i18n/i18n.js:175`), so
the whole screen rendered untranslated outside English.

The repair landed in `7163125d6` (sibling fix for #1750), which re-added the keys in all ten bundles;
that commit is an ancestor of the default branch, so the symptom is not reproducible. This ticket was
never closed after the fix. Verification only — **no new code change**.

Evidence:
* working-tree `en.json` → **48** `smv.*` keys; every bundle `de/es/fr/it/ja/pt/ro/ru/zh` → 48 each.
* every `smv.*` + `footers.suiteMove` key referenced by `suiteMove.html` / `testSpec.html` exists in
  `en.json` → `missing: []`.
* `bash ai/verify_i18n_coverage.sh` → `9 bundle(s) passed, 0 failed`, exit 0 (7369 keys each).
* runtime `?locale=ro` → title "Mută / reordonează suite de test", table header "Suite de test",
  footer "TestLink 2.0.1 - Mută / reordonează suite de test", move modal "Mută suita de test" with the
  `{suite}` placeholder interpolated; `?locale=de` → "Testsuite verschieben / neu sortieren".
* `events` table after the test: 1 audit `LOGIN` row (`log_level=16`), no Error/Warning; console clean.

Regression suite `Issue #1751` in `tmp/TLU_Test_Cases.md`: **10/10 PASS**. Screenshot:
`docs/screenshots/issue-1751-suitemove-ro.png`.

## Bugfix #1755 — the in-flight guard (`BUSY`) can no longer latch

`postAction()` is the only mutating path of the screen: it sets `BUSY` once
(`gui/templates/testcases/suiteMove.html`) and renders every row button with a `BUSY` term, so a
path that never released the guard left **all** actions greyed out until reload. Pre-fix the release
lived in the per-branch handlers only, so a write that *succeeded* and then had its follow-up
`load(true)` rejected (or any unhandled path) returned with `BUSY === true` forever.

The repair landed in `13f2ca183` ("#1740: apply code-review fixes…"): the release collapsed into a
single `}).done(...).fail(...).always(function () { setBusy(false); })`. That commit is an ancestor
of the default branch, so the symptom is not reproducible. This ticket was filed
(`2026-09-30T13:30:59Z`) after the fix was authored (`13:30:30Z`) but before it landed
(`13:35:13Z`), i.e. it documented code that no longer existed by the time it shipped. Verification
only — **no new code change**.

Evidence:
* Shipped code, POST ok + follow-up `load(true)` → 404: `BUSY=false`,
  buttons `[T,F,F,F,F,T,F,F]`, `anyEnabled=true` — screen usable.
* Pre-fix `postAction()` reconstructed verbatim from `13f2ca183^`, same scenario: `BUSY=true`,
  **all 8 buttons `disabled`** — the reported symptom, so the suite exercises the right path.
* Release site: `gui/templates/testcases/suiteMove.html:414-418`
  (`.always(function () { setBusy(false); })`).
* `git merge-base --is-ancestor 13f2ca183 origin/sebiboga` → true.
* Same guard present in the sibling screens that carry a `BUSY` flag (`tcReorder.html:415-416`,
  `tcStepReorder.html:589-590`) — no second latch (`containerMoveTC.html` uses `#loadingOverlay`).
* `events` table after the run: only audit rows, no Error/Warning; console clean.

Regression suite `Issue #1755` in `tmp/TLU_Test_Cases.md`: **9/9 PASS**. Screenshot:
`docs/screenshots/issue-1755-suitemove-buttons-usable.png`.

## Verification

Suite 1740 in `tmp/TLU_Test_Cases.md`: **57/57 PASS** (incl. 11 review-fix cases) + 30 API
contract checks. Event Viewer clean (0 new Error/Warning rows). Screenshots:
`docs/screenshots/issue-1740-suitemove-0{1,2,3}-*.png`.
