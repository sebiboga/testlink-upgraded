# Modernize: Reorder Test Case Steps (`tcStepReorder`)

**Refs #1671** · status: DONE · BFF: `api/tcstepsreorder/index.php` · screen: `gui/templates/testcases/tcStepReorder.html`

## What this screen is

Re-ordering the **steps of a single test case version**. In 1.9.20 it was not a
screen at all — it was the TableDnD drag-and-drop of the step list, and the
capability existed only as its server side:

| Legacy piece | Role |
|---|---|
| `gui/templates/tl-classic/testcases/steps_horizontal.inc.tpl` | the TableDnD widget |
| `lib/ajax/stepReorder.php` | the write backend (`testcase::set_step_number()`) |

The template was never part of the shipped UI, so the endpoint had no URL of its
own, no dialog, no right — and nobody ever reviewed it.

## Why the legacy backend had to be retired

`lib/ajax/stepReorder.php` re-ordered steps with **no authorization at all**:

- no `checkSessionValid()`, so an **anonymous** request re-ordered steps
- no `mgt_modify_tc` (or any) right check, so *any* logged-in user — including
  `<no rights>` — re-ordered any version
- it read `$_REQUEST`, so a **GET** mutated data: classic CSRF, no token
- `stepSeq` was written **without any membership check**: step ids of a
  *foreign* version could be mixed in, silently corrupting both versions
- it ended with a hardcoded `tLog(..., DEBUG_MODE ...)` writing a debug row per call

It is now a **non-mutating shim** (issue **#1673**): session-guarded, `405` for
every write verb, and a `302` of GET to the modern screen. The mutation is
deliberately *not* replayed — a write verb is answered with a pointer to the BFF
instead of silently succeeding.

## The screen

Dashio layout: teal header + dark toolbar (Refresh / Close), a context card
(Test project, Test suite, Test case, Version, Steps) and the order table.
Reached without a version in the URL — the `$actions->tcStepReorder` hub entry,
or a bookmark of the retired shim — it shows a **version picker** instead of an
error card: filter on external id / name, live `n / total` counter, an empty
state, and only the versions that actually *have* steps (there is nothing to
re-order without one).

| Control | Behaviour |
|---|---|
| **Move to top / up / down / bottom** | per row, immediate write (as the legacy drop), boundary buttons disabled |
| **drag-and-drop** | reorders the local list only — nothing is written until *Apply order* is confirmed |
| **Apply order** | confirmation, then the full order is written |
| **Discard changes** | restores the stored order |
| **Renumber steps** | confirmation, repairs gapped `step_number`s while keeping the order |
| **Change version** | back to the picker |
| **Refresh / Close** | toolbar |

Rows show the position, an execution-type chip (Manual / Automated /
Automated-proceed / Automated-audited plus the upload paperclip), the step
actions and expected results **as plain text** — 2.0.1 keeps a RichEdit blob in
`tcsteps`, so the markup is stripped server-side, keeping the line break — and
the four move buttons. A version with fewer than two steps gets no move
operation at all, and `draggable` is off.

## BFF contract

`api/tcstepsreorder/index.php`

| Route | Notes |
|---|---|
| `GET ?action=init&tcversion_id=N[&tproject_id=M]` | context, ordered steps, rights |
| `GET ?action=versions&tproject_id=N` | picker list: external id, name, version, step count |
| `POST ?action=move` | `step_id`, `position` = `top\|bottom\|up\|down` |
| `POST ?action=reorder` | `order` — the hardened drag-and-drop parity endpoint |
| `POST ?action=normalize` | renumber 1..N keeping the order |

Guards: session auth, `bffSameOriginGuard()`, `bffEnforceSession()` (this
endpoint *writes*, so the legacy inactivity timeout is enforced), `mgt_view_tc`
for the two reads and `mgt_modify_tc` for the three writes — always checked on
the **owning** project, which is *proved* by walking `nodes_hierarchy.parent_id`
up to the project node rather than trusting a submitted id. Every submitted step
id must be a `testcase_step` child of the addressed version, a `reorder` list
must contain every step of that version exactly once (duplicates and partial
lists are refused), and a version with fewer than two steps refuses a move.

A write answers `no_change` (not `ok`) when neither the stored order nor the
stored numbering moved, so a boundary click and a pointless renumber are
reported honestly instead of as a success.

Error contract: 400 `bad_param` / `unknown_action`, 401 `session_expired`,
403 `forbidden` (rights, or a version of another test project), 404 `not_found`
(unknown version, or a node that is not a version), 405 for a write verb on GET.

## Wiring

- `$actions->tcStepReorder` in `lib/functions/common.php` — the hub entry
- a rights-gated **Reorder steps** button on every version card of
  `gui/templates/testcases/tcView.html`, passing that card's own
  `tcversion_id` (with several versions the popup must re-order the version the
  user clicked on) and disabled when the version has no steps
- `lib/ajax/stepReorder.php` — the retired shim

## i18n

`tcsr.*` + `footers.tcStepReorder` — **62 keys per bundle** in **all 10**
bundles (`python3 -m json.tool` validated), with real translations, inserted in
alphabetical position so the existing key order is untouched. The renumber copy
carries a `{count}` placeholder, interpolated with the screen's `tpl()` helper.

## Bugs found and fixed while building this

| Issue | Defect |
|---|---|
| **#1673** | The legacy endpoint: unauthenticated, GET-mutating, no rights check, no step-ownership proof, and a `DEBUG_MODE` log row per call. Retired as a non-mutating shim. |
| **#1674** | Three defects of the new screen, all found by the browser pass: **(a)** every delegated handler was bound inside an `if/else` on `tcversion_id`, so arriving through the picker left all four move buttons, the drag-and-drop and *Discard changes* **dead** (no POST ever left the browser) while a URL entry left the *Change version* picker rows **dead**; **(b)** the no-op detection compared *sorted* id sequences — identical before and after any write — so **every** move answered `no_change` and the user was never told the write landed, while renumbering (which changes numbers, not id order) was reported as a no-op even when it repaired `11,12,13,14` → `1,2,3,4`; **(c)** the renumber copy still showed a literal `{count}`. |

## Testing

Suite **1671** in `tmp/TLU_Test_Cases.md` — 55 cases, all PASS, across the
version picker (including the dead-picker regression), rendering/data contract
(RichEdit stripping, type chips, boundary state), move, drag → Apply → Discard,
renumber, 15 error and rights paths, the legacy shim, wiring and i18n. Fixture
`tmp/fixtures_1671.php` (project `1` `StepReorder Demo` / `TSR1671` with a
4-step version containing an automated step and RichEdit markup, a 2-step and a
1-step version, a second version of the same test case, plus a `norights`
user). Event Viewer clean — zero new rows after the whole matrix.

## Screenshots

- `docs/screenshots/issue-1671-tcstepreorder-picker.png` — the version picker (hub entry)
- `docs/screenshots/issue-1671-tcstepreorder-normal.png` — the step table with the context card
- `docs/screenshots/issue-1671-tcstepreorder-dragged.png` — a pending drag, Apply/Discard enabled
- `docs/screenshots/issue-1671-tcstepreorder-after-apply.png` — after Apply, with the success notice
- `docs/screenshots/issue-1671-tcstepreorder-locale-ro.png` — Romanian locale
- `docs/screenshots/issue-1671-tcstepreorder-access-denied.png` — no-rights user

## Commits

`dd8e8b5b4` BFF → `ce0e7d932` screen → `1ee7c403c` i18n ×10 + aside link +
`tcView` entry + legacy shim → `f08cd9520` the three test-found fixes (#1673,
#1674).

---

## Bugfix — Issue #1674: dead handlers after picker navigation, `no_change` on every write, literal `{count}`

Fix commit `f08cd9520`, landed on the default branch with the parent screen
(#1671) and therefore never closed as its own issue. Independently re-verified
on `fdc4d19a6` — see the `Regression — Issue #1674` suite in
`tmp/TLU_Test_Cases.md` (**11/11 PASS**). This section records the *approach*.

### 1. Dead delegated handlers — bindings nested in the `TCVER_ID` branch

**Approach.** The screen is one page with two cards that are only ever
*toggled*, never re-created (`#picker style="display:none"`,
gui/templates/testcases/tcStepReorder.html:151), and each card is reachable
from **both** entry points — the hub entry `?tproject_id=N` shows the picker,
the `tcView.html` button shows the step table, `Change version`
(`backToPicker()`, line 190/345) goes back to the picker and clicking a picker
row (`openVersion()`, line 484) returns to the step table.

jQuery delegated handlers are bound to an element *at bind time*, and the
original code branched inside the single `$(function(){…})`:

```js
if (!TCVER_ID) { $('#pkRows').on('click','tr',…); loadVersions(); }
else           { $('#rows').on('click','button.op',…); …; load(); }
```

so it permanently bound **one** card and left the other unbound — 8 handlers
(`#pkRows` click, `#rows button.op`, `#mask` backdrop, the 5 drag events,
`#btnReset`). Symptom: the DOM rendered perfectly while the **network layer
stayed completely silent** — no exception, no console error, 0 POSTs.

**Fix.** Bind every handler **unconditionally** in a plain block and branch
*only* the initial load (tcStepReorder.html:671-741):

```js
if (TCVER_ID) { load(); } else { loadVersions(); }   // line 741
```

**Alternatives rejected.** (a) Re-binding on every card switch — duplicates the
handlers and leaks on each navigation. (b) Splitting the two cards into two URLs
— changes the UX and breaks the `tcView.html` entry contract. (c)
`$.on` delegation on `document` — works, but is broader than needed and makes
the two-card structure implicit. Unconditional binding is what the sibling
modernized screens do.

### 2. `no_change` reported for every write

**Root cause.** `tsroWriteResult()` compared the **sorted** id sequences before
and after the write. The id *set* is invariant under every operation this screen
performs — only `step_number` changes — so `sort()` on both sides yielded two
identical arrays for every successful write and the endpoint answered
`status=no_change`. The client then took the correct branch
(tcStepReorder.html:583-584), so the move persisted in the DB while the UI
showed *no success notice*: the user was told nothing happened. The defect was
in the server's **verdict**, not in the UI.

The mirror defect: `?action=normalize` rewrites `step_number` only
(`11,12,13,14 → 1,2,3,4`) and leaves the id order untouched, so a real repair
was also reported as a no-op.

**Fix** (api/tcstepsreorder/index.php:463-468) — compare the **ordered** id
sequence *and* the **ordered `step_number`** sequence:

```php
$changed = ($before['ids'] !== $afterIds) || ($before['numbers'] !== $afterNumbers);
```

Both halves are required and they cover opposite cases: `move`/`reorder` change
the id order, `normalize` changes the numbers alone. Comparing only ids leaves
the normalize defect; comparing only numbers misses a reorder that restores the
same numbering.

**Alternatives rejected.** Returning a plain `ok` and letting the client diff —
would need the whole prior order shipped to the browser and still could not tell
a boundary refusal from a real write; the server already has both snapshots.

### 3. Literal `{count}` in the renumber copy

`TLi18n.t('tcsr.normalizeConfirm')` was called with no parameters, so the
`{count}` placeholder in the bundle was never substituted and the confirm dialog
read *"Renumber the steps from 1 to {count} …"*. The interpolator already exists
in `gui/templates/i18n/i18n.js`; the two call sites just omitted the argument.
Fixed at tcStepReorder.html:636 and :651 with
`TLi18n.t('tcsr.normalizeConfirm', { count: STEPS.length })`.

### Blast radius

`tsroWriteResult()` / `tsroIdsOf()` / `tsroNumbersOf()` are local to
api/tcstepsreorder/index.php — 1 definition, 3 call sites (`move` / `reorder` /
`normalize`), all in that one file; no other endpoint calls them. The
handler-binding block is the only `$(function(){…})` in the screen, so the
unconditional-bind change cannot affect another screen. The shared legacy write
primitive `testcase::set_step_number()` is untouched, so 2.0.1 rows stay
byte-compatible with 1.9.20 and with every modern consumer that does
`ORDER BY tcsteps.step_number`. The sibling screen `api/tcreorder` (#1660)
re-orders the specification *tree*, not steps, and is unaffected.

### Verification (11/11 PASS)

Fixture: project `9001` `BugRepro` → test case `9051` → version `9101` with
steps `9201..9204` numbered **11,12,13,14**. (2.0.1 folds the tree into
`nodes_hierarchy`; the picker only walks
`project -> testcase -> testcase_version -> testcase_step`.)

* picker → row → **Move to top** → `POST …{"action":"move","step_id":9202,"position":"top"}`
  → **200** `{"status":"ok"}`, DB `9202→1, 9201→2, 9203→3, 9204→4`, notice
  *"Step moved to the top of the version."*
* gaps `11,12,13,14` → **Renumber steps** → confirm → `{"status":"ok"}`, DB `1,2,3,4`
* the no-op guard (the fix must not over-correct into "everything is `ok`"):
  2nd `normalize` → `no_change`; `move up` on the first step →
  `no_change "Already at the boundary"`; `reorder` to the stored order →
  `no_change "Order already up to date"`
* dialog copy → *"Renumber the steps from 1 to 4 keeping the current order?"*
* drag row 1 onto row 4 → pending `Do A, Do C, Do D, Do B`; **Discard changes** →
  restored `Do B, Do A, Do C, Do D`, Apply/Discard disabled again
* `?tcversion_id=9101` → **Change version** → picker row has `data-ver="9101"`,
  clicking it re-renders the 4-row step table
* Event Viewer: `events` holds 1 row (`log_level 16`, `activity LOGIN`) —
  **no new Error/Warning**; console **0 errors, 0 warnings**

