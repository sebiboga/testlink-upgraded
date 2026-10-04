# Bugfix — Issue #1688: `reqTreeReorder.html` — the toolbar stayed live on the 403/404 error page

**Number:** #1688
**Status:** Fixed (verified — the toolbar fix was already in the default branch as `571760ea4`; this run re-measured it, closed the residual row-control hole in the same defect class, pinned both with regression suites and closed the issue)
**Component:** `gui/templates/requirements/reqTreeReorder.html`
**Area:** Requirements / Specification Tree Reordering
**Fixing commit (code):** `571760ea4` — *fix(reqtreereorder): disable the whole toolbar on the 403/404 error page (Refs #1681)*
**Regression-suite commit:** `bc4dfde43`

## Symptom

When the `init` call fails with **403 `no_right`** or **404**, the screen correctly hid the
Context / Move / Reorder cards and showed the error card — but the **toolbar above it stayed
fully enabled**:

```
applyDisabled: false, moveDisabled: false, specSelDisabled: false
```

Clicking **Apply order** on an *access denied* page answered **"Nothing to apply"**, and
**Discard changes** / **Move requirement** were equally live. The specification picker and the
position picker stayed enabled too. On top of that the page still advertised two things that
were wrong there:

* the **read-only banner** ("You have read-only access to requirements: moving and reordering
  are disabled") — a user who may *view but not modify* is a different situation from a user
  who may not see the page at all;
* the **drag-and-drop hint** ("Drag and drop reorders locally until you apply it").

So the visible state and the actual state disagreed: a live-looking toolbar pointed at an empty
client-side model.

## Root cause

Not a wrong condition — a **missing transition**. `idleUI()` is the single function that owns the
enabled/disabled state of every control, and it was only ever invoked from the **success**
handler of the `action=init` AJAX call.

Root cause chain. Line numbers are those of the **current** file — the pre-fix code had no `DEAD`
column, and five later commits added the `buildBackLink()` block and the `#specChip` target, so the
pre-fix line numbers are lower:

| Hop | Location (line numbers are those of the **current** file; the pre-fix code lacked the `DEAD` column entirely) | What was wrong |
|---|---|---|
| 1 | `reqTreeReorder.html` `:74-75` `#applyBtn` / `#discardBtn`, `:123` `#moveBtn`, `:90-91` `#specSel`, `:113` `#targetSel`, `:117` `#posSel` | All six shipped **enabled** — no `disabled` attribute in the markup. |
| 2 | `idleUI()` — the only place that ever called `.prop('disabled', …)` **for those six toolbar controls** (the row buttons have their own owner, `applyRowState()` — see *Residual hole* below) | Derived the state from `BUSY` and `GRANT.modify` alone. Both come from the *success* payload. |
| 3 | `showState(text, code)` — reached from `:410` `MISSING_TPROJECT`, `:421` `BAD_PAYLOAD`, `:464` `HTTP_403`, `:465` `HTTP_404`, `:466` `HTTP_405`, `:467` any other status | It only toggled **card visibility**: `$('#stateCard').css('display','block')` and `$('#ctxCard,#moveCard,#ordCard').css('display','none')`. **It never called `idleUI()`** — hop 2 never ran. |
| 4 | `load()`'s `success:` handler (now `:453`, the only `idleUI()` call site) | The *only* `idleUI()` call site, so the disabled state was produced exclusively by a **successful** `action=init`. |
| 5 | `applyOrder()` | `if (ITEMS.length < 2) { showMsg(true, t('reqtr.nothing')); return; }` — with `ITEMS` empty on the dead page this answered **"Nothing to apply"** instead of refusing. `doMove()` / `discard()` were equally reachable. |

### Why it broke "now" — regression source

The defect was introduced **with the screen**: `reqTreeReorder.html` is the new Dashio rewrite of
the legacy requirement-spec reordering (Refs **#1681**), whose server-rendered template was
`gui/templates/dashio/requirements/reqReorder.tpl` (64 lines) plus
`gui/templates/dashio/include/drag_drop.inc.tpl`, POSTing to
`lib/requirements/reqEdit.php?doAction=doReorder`. That legacy pair contained no JavaScript at all
(`grep -c ExtJS` → `0`), so the whole panel — markup *and* data — was drawn in one response and
the invariant *"a control is never enabled without data behind it"* held by construction.

The rewrite broke that invariant in a specific way: it split the markup (always rendered, static
`.html`, no PHP) from the data (loaded by `action=init` over AJAX), and it placed the toolbar
**outside** the three cards that `showState()` hides. The six toolbar controls therefore have no
server-rendered data behind them at all until `init` succeeds.

### Blast radius

* **6 error entry points**, all funnelling through `showState()`: 403, 404, 405, any other HTTP
  status, `MISSING_TPROJECT`, `BAD_PAYLOAD`.
* **6 controls**: `#applyBtn`, `#moveBtn`, `#discardBtn`, `#targetSel`, `#posSel`, `#specSel`.
* **2 misleading messages**: `#roBanner` (`:81`), `#dragHint` (`:78`).
* **3 action handlers**: `applyOrder()`, `doMove()`, `discard()`.
* **Sibling of the same class**: [#1689](https://github.com/sebiboga/testlink-upgraded/issues/1689)
  — draggable rows in read-only mode, i.e. *control state derived only from the success path*.
* **Same screen, same #1681 browser-testing run, different defects**:
  [#1686](https://github.com/sebiboga/testlink-upgraded/issues/1686) (back link was a dead
  self-reload on the error path — also success-path-only, but for the `href` rather than the
  `disabled` state), [#1687](https://github.com/sebiboga/testlink-upgraded/issues/1687)
  (hardcoded `-` in the *Modified by* tile) and
  [#1690](https://github.com/sebiboga/testlink-upgraded/issues/1690) (`tproject_id` hardcoded to
  13). #1686 and #1687 are closed as verified; #1689 and #1690 are separate open issues and are
  **out of scope for this run**.

## Fix (already in the default branch — shape of the correct solution)

`571760ea4` (+19 −4, one file):

| Location (current) | Change |
|---|---|
| `:163-165` | a page-level `var DEAD = false;` (unchanged by this run) — deliberately **outside `GRANT`**, so a 403 can never be mistaken for "read-only" |
| `:222` / `:227` | `showState()` sets `DEAD = true` (`:222`) **and calls `idleUI()`** (`:227`) — the missing transition of hop 3 |
| `:234-235` | `hideState()` clears `DEAD = false` — the success path re-arms the screen |
| `:240-241` | `idleUI()` folds `DEAD` into its condition: `var off = BUSY \|\| DEAD \|\| !GRANT.modify;` |
| `:244` | `#roBanner` shown only when `!DEAD && !GRANT.modify` |
| `:246` | `#dragHint` toggled on `!DEAD && !!GRANT.modify` |
| `:249` | the `#specSel` read-only re-enable is itself gated on `!DEAD`, so the dead state wins over the read-only exception |
| `:473`, `:514`, `:550` | `doMove()` / `applyOrder()` / `discard()` start with `if (DEAD) { return; }` — enforced in the **handlers**, not only visually |

### Alternatives rejected

1. *Disable the controls in the markup and let `idleUI()` re-enable them on success.* Rejected:
   it would have masked the real problem (an error path that never runs the state owner) and
   still left `GRANT` empty, so the read-only banner would keep claiming "you may view but not
   modify" on a forbidden page.
2. *Reuse `GRANT.modify` for the dead state.* Rejected: `GRANT` describes a **rights** answer and
   is compared to a boolean in several places; overloading it would make a 403 indistinguishable
   from a read-only grant. Hence the separate `DEAD` flag.
3. *Leave the toolbar live and only make the handlers refuse.* Rejected as insufficient on its own:
   the buttons would still *look* actionable. The fix does both — `DEAD` disables the pixels
   **and** guards the handlers.

### Why the issue stayed OPEN

The fix commit is timestamped `2026-09-28 05:36:55Z` — the **same second** the issue was created
(`createdAt: 2026-09-28T05:36:55Z`). The #1681 run fixed the bug inside its own branch and
documented the fix in the issue **body** (*"Fix applied in the Refs #1681 branch"*), but never ran
`gh issue close`, so triage kept surfacing #1688 as the oldest open bug. **Nothing was lost in the
code; the closure step was lost.** This run supplied it, after re-measuring the symptom rather
than assuming it.

## Residual hole in the same defect class (fixed in this run)

The mandatory pre-commit code review of `fix/issue-1688` measured a **residual gap that the first
pass had missed**: `571760ea4` made `DEAD` authoritative for `idleUI()` and for the three toolbar
handlers, but the **row** controls were never folded into it.

| Location (current) | Pre-fix | Gap |
|---|---|---|
| `applyRowState()` `:256` / `:259` | `BUSY \|\| !GRANT.modify` and `!GRANT.modify` | no `DEAD` term |
| `showState()` `:227` | called `idleUI()` only | never called `applyRowState()` (it is the last statement now, `:231`), so surviving rows kept their live state |
| `nudge()` `:366` | `if (BUSY \|\| !GRANT.modify) { return; }` | no `DEAD` guard |
| `.pickbtn` click handler `:315-316` | unguarded one-liner | a Select could still arm a Move that could never be submitted |
| `drop` handler `:329` | `if (!GRANT.modify \|\| BUSY) { return; }` | no `DEAD` guard |

**Reachability.** Whenever the page transitions **success → dead** — `init` succeeds once and a
*later* `load()` fails, e.g. picking a specification that then 404s. The rows survive that
transition, so on a page the user may not even see, `nudge(0,'down')` still reordered
`ITEMS ["REQ1","REQ2"] → ["REQ2","REQ1"]` and lit the *Unsaved changes* chip, and `.pickbtn.click()`
still set `PICKED`. The toolbar was dead; the state underneath it was not. It was **visually**
hidden (the rows live in `#ordCard`, which `showState()` hides), which is exactly why the toolbar
suite did not catch it — and why the toolbar suite was extended rather than left as-is.

**Fix** (`gui/templates/requirements/reqTreeReorder.html`, +13 −5): `DEAD` added to both
`applyRowState()` disabled computations, a new `DEAD` term for `.pickbtn` (the only row control
enabled for a view-only user), `draggable` forced to `"false"` and the grip icon hidden when
`DEAD`; `showState()` now ends with `applyRowState()`; `nudge()` and the `drop` handler extended
their guards; the `.pickbtn` handler gained `if (DEAD) { return; }`.

Verified as `TC-1688-12..21` in the addendum suite (10/10 PASS), including the explicit
non-regression check that on the success path `nudge()` still reorders, rows stay
`draggable="true"` with a visible grip, and `.pickbtn` stays enabled.

## Verification (measured, 11/11 cases PASS)

Fixtures (the DB is re-imported on every run): tproject 13, req specs 16 (`RS1`) / 17 (`RS2`),
requirements 20 (`REQ1`) / 21 (`REQ2`) in spec 16, plus user `tr1681norights` on
`role_id = 3` (`<no rights>`, no `role_rights` rows).

| Case | Measured result |
|---|---|
| API layer, `GET ?action=init&tproject_id=13&req_spec_id=16` as the no-rights user | `HTTP 403` `{"status":"error","code":"no_right","message":"You are not authorized to view requirements"}` |
| 403 — `window.DEAD` | `true` |
| 403 — `#applyBtn` / `#moveBtn` / `#discardBtn` `.disabled` | `true / true / true` (was `false`) |
| 403 — `#specSel` / `#targetSel` / `#posSel` `.disabled` | `true / true / true` (specSel was `false`) |
| 403 — `#roBanner` className / `#dragHint` display | `"banner"` (no `show`) / `none` |
| 403 — `#refreshBtn` `.disabled` | `false` — retry stays available on an error page (intended; the issue never complained about Refresh) |
| 403 — `applyOrder()`, `doMove()`, `discard()` invoked **with the `disabled` attribute bypassed** | `#msg` `display:none`, `text:""`, no exception — the reporter's *"Nothing to apply"* does not occur |
| 403 — real `.click()` on `#applyBtn` | `#confirmModal` `display:none`, no message |
| 404 — `?tproject_id=999&req_spec_id=16` | `DEAD:true`, code `tproject_not_found`, all 6 controls disabled, banner hidden, handlers no-op |
| no project in context — bare `?req_spec_id=16` | `DEAD:true`, code `MISSING_TPROJECT`, all 6 controls disabled, banner hidden |
| Success path as `admin` | `DEAD:false`, `GRANT:{"view":true,"modify":"yes"}`, `ITEMS:["REQ1","REQ2"]`, `SPECS:["RS1","RS2"]`, all 6 controls `disabled:false`, `#dragHint` `block`, `rowDraggable:["true","true"]`, `#mTproject` "TP 13 Fixture", `#mWho` "admin" — **no regression** |
| Event Viewer / `events` | `SELECT COUNT(*) FROM events WHERE log_level IN (0,1,2)` → **0**; 3 rows total, all `log_level=16` (`audit_login_succeeded`) |

Coverage note: of the **6** `showState()` entry points named above, **3** are measured end-to-end
over HTTP (403, 404, `MISSING_TPROJECT`). The other three (`BAD_PAYLOAD`, 405, any other status)
are only reachable by forcing the handler, because this fixture set cannot produce them — they are
covered **structurally** (all four funnel through the same `showState()`, and `TC-1688-20` drives
it directly), not by a live HTTP response.

Regression suites in `tmp/TLU_Test_Cases.md`: `## Regression — Issue #1688` (`TC-1688-01..11`)
and `## Regression — Issue #1688 (addendum)` (`TC-1688-12..21`, the residual hole above).
Merge-base gate `TLU_REQUIRE_SUITE="Issue #1688" bash ai/verify_test_suites.sh`
→ **7 PASS / 0 FAIL** (suite headings 72 → 73, nothing lost).

## Retest recipe

```bash
# fixtures (see the suite for the full SQL — note req_versions.type is 1 char)
mysql -h 127.0.0.1 -utestlink -ptestlink testlink

curl -s -c cj -b cj -X POST \
  -d 'tl_login=tr1681norights&tl_password=admin&ssodisable=1' \
  http://localhost:8082/login.php
curl -s -c cj -b cj -o /dev/null -w '%{http_code}\n' \
  'http://localhost:8082/api/reqtreereorder/index.php?action=init&tproject_id=13&req_spec_id=16'
# -> 403

# then open, as that user:
# http://localhost:8082/gui/templates/requirements/reqTreeReorder.html?tproject_id=13&req_spec_id=16
# and evaluate in the console:
#   DEAD, document.querySelector('#applyBtn').disabled, document.querySelector('#roBanner').className
```

## Screenshot

![the toolbar is fully disabled on the 403 error page](screenshots/issue-1688-error-page-toolbar-dead.png)

## Files changed

* This run: `gui/templates/requirements/reqTreeReorder.html` (+13 −5 — the residual row-control
  hole above), `tmp/TLU_Test_Cases.md` (two append-only regression suites), `CHANGELOG`,
  `docs/Bugfix-Issue-1688-reqtreereorder-Toolbar-Live-On-Error-Page.md`, Wiki mirror + screenshot.
* The toolbar fix itself is `571760ea4` and was already on the default branch.

## Commits

* `571760ea4` — the actual code fix (Refs #1681), already on the default branch
* `bc4dfde43` — regression suite + CHANGELOG for this run
* `8fca3c697` — `docs/` page + screenshot for this run
* `fix/issue-1688` head — residual `DEAD` hole in the row controls (see *Residual hole* below)

## Reference

See also: [Reorder Requirements Tree (reqTreeReorder) Modernized](Reorder-Requirements-Tree-Modernized.md),
[Bugfix #1685 — drag/drop dirty flag](Bugfix-Issue-1685-reqtreereorder-Drag-Drop-Dirty-Flag.md),
[Bugfix #1686 — back link dead self-reload](Bugfix-Issue-1686-reqtreereorder-Back-Link-Dead-Self-Reload.md) and
[Bugfix #1687 — Modified by / live counts](Bugfix-Issue-1687-reqtreereorder-ModifiedBy-LiveCounts.md).
