# Bugfix — Issue #1598: the 'Assign Requirements' modal on Test Specification was always empty

**Screen:** `gui/templates/testcases/testSpec.html` (modern *Test Specification*, Dashio)
**Fixed by:** `09e89bd2c` — frontend only, **no backend change**, no new endpoint
**Verified:** 2026-09-27 — browser (headless Chrome) on a fresh database, signed in as `admin`;
13-case regression suite executed, see `tmp/TLU_Test_Cases.md` § *Regression — Issue #1598*

Screenshot: `docs/screenshots/issue-1598-postfix-assign-requirements.png` (post-fix, populated modal
showing FREE `(1)` / ASSIGNED `(1)` for test case 9).

---

## 1. Symptom

The per-test-case **"Assign Requirements"** button on the Test Specification screen opened a
modal whose **FREE REQUIREMENTS and ASSIGNED REQUIREMENTS lists were always empty**, with blank
counters, and whose **Assign** / **Unassign** buttons therefore did nothing (they would have
posted `tcase_id=0`).

The **requirement specification** combo in the modal header *was* populated correctly, so the
screen looked functional while being a dead end. No error, no console message, no log entry.

**Impact:** the legacy feature "assign a requirement to a single test case"
(`lib/requirements/reqTcAssign.php`, single mode) had **no working entry point in 2.0.1** — the
only path left was the suite-level grid of #1595 (Requirements Bulk Assignment).

## 2. Root cause

`openAssignReqs(tcaseId)` accepted the test-case id that the button passes and **never stored
it**. The module-level `arqTcaseId` stayed `0` for the whole session, so the guard in
`arqLoadReqs()` short-circuited **before** the `GET /assign-reqs` request was ever issued:

```js
// gui/templates/testcases/testSpec.html — BEFORE
function openAssignReqs(tcaseId) {
  $('#arqSpecSel').empty();      // tcaseId is accepted here and dropped
  …
}

var arqTcaseId = 0;              // the only write to it, ever

function arqLoadReqs() {
  var specId = parseInt($('#arqSpecSel').val() || '0', 10);
  if (!specId || !arqTcaseId) return;   // <-- always true: returns BEFORE the request
  arqApiGet('/assign-reqs', {req_spec_id: specId, tcase_id: arqTcaseId}, …);
}

function arqDoAssign() {
  …
  arqApiPost('/assign-reqs', {tcase_id: arqTcaseId, req_ids: …}, …);  // tcase_id = 0
}
```

The root-cause chain, hop by hop:

| # | Location | What happens |
|---|---|---|
| 1 | `testSpec.html:892` (pre-fix) | `renderTcView()` renders `onclick="openAssignReqs(' + tc.id + ')"` — the id **is** passed correctly |
| 2 | `testSpec.html:357` (pre-fix) | the parameter is **never read** — the defect |
| 3 | `testSpec.html:433` (pre-fix) | `var arqTcaseId = 0;` is the only write in the repository |
| 4 | `testSpec.html:381-384` (pre-fix) | `if (!specId || !arqTcaseId) return;` short-circuits → no request, empty lists |
| 5 | `testSpec.html:407` (pre-fix) | `arqDoAssign()` would post `tcase_id=0`, which the API rejects (`api/requirements/index.php:2530`) |

So the **list + link** half of the modal was unreachable while the half that *is* implemented
(the specification combo) worked — precisely the asymmetry that makes the screen look healthy.

### 2.1 The backend was proven healthy

Measured on the same loaded page, before any edit:

```js
arqTcaseId                                       // -> 0
await (await fetch('/api/requirements/index.php/assign-reqs?req_spec_id=2&tcase_id=9',
  {headers:{'x-requested-with':'XMLHttpRequest'}})).json()
// -> 200 {"status":"ok","unassigned":[REQ-001, REQ-002], "assigned":[]}
```

and the network panel showed that opening the modal issued **only**
`GET /assign-reqspecs?tproject_id=1` → `200`; the `assign-reqs` call that fills the two lists
was **never issued**. The endpoint and the legacy single-test-case semantics were correct; the
frontend simply never asked. **No backend change was needed.**

### 2.2 Why it breaks NOW

The variable and the two guards were written for the full feature, but the assignment was never
added — a **latent omission**, not a regression. The button only became *reachable* through the
`requirementsEnabled && req_tcase_link_management` gate restored by **#1596** (same screen,
adjacent code path). Before that commit there was no way to call `openAssignReqs()` at all, so
the dead code was unreachable and therefore invisible.

### 2.3 Blast radius

`arqTcaseId` appears in exactly 5 lines, all in `testSpec.html` → **1 code path, 1 screen**, one
caller (`openAssignReqs(tc.id)` from `renderTcView()`), no other screen or plugin references it.

`tcView.html:598` and `tcEdit.html:988` also define an `openAssignReqs()`, but those are
**different, argument-less functions** with their own modal markup. They were deliberately left
untouched, and they independently confirm that reading the id from the caller's context is the
established idiom in this codebase — `testSpec.html` was the odd one out.

## 3. The fix

`09e89bd2c` — **5 hunks in one file + 1 line in each of the 10 locale bundles**, frontend only.

1. **Store the id that the caller already passes** (`openAssignReqs()`):

   ```js
   arqTcaseId = parseInt(tcaseId || 0, 10) || 0;
   ```

   `parseInt(...) || 0` rather than bare `parseInt` so a non-numeric/`NaN` id collapses to `0`
   and fails the downstream guard instead of propagating `NaN` into the query string. This alone
   restores hops 4 and 5.

2. **Reset the state on close** via a new `closeAssignReqs()`, used by both footer buttons
   (Cancel and Close), so a **closed modal can never leave a stale test-case id behind** for a
   later reader — and therefore can never reuse one test case's id for another:

   ```js
   function closeAssignReqs() {
     arqTcaseId = 0;
     arqSpecs = [];
     arqResetUi();
     closeModal('assignReqsModal');
   }
   ```

3. **Extract the duplicated clear-block to `arqResetUi()`**, called from both `openAssignReqs()`
   and `closeAssignReqs()`, so the open and close paths converge on the same state and cannot
   drift apart.

4. **Refuse to POST a stale selection** — `arqDoAssign()` returns early with a **localised**
   message instead of silently posting `tcase_id=0`:

   ```js
   if (arqTcaseId <= 0) { toast(t('reqAssign.noTestCase'), true); return; }
   ```

   The guard sits **before** the "select a requirement" check on purpose: after a close, a
   never-populated modal has no options either, and the reverse order would report the
   misleading *"Please select at least one requirement."* A silent no-op is exactly the failure
   mode of this bug.

5. **Normalise the `arqLoadReqs()` guard** to `!(arqTcaseId > 0)` so both guards reject a
   non-numeric value identically.

### 3.1 Why this method, and what was rejected

* **Fixing the backend** — nothing to fix; `GET`/`POST /assign-reqs` were *measured* returning
  `200` with the correct legacy semantics before the change.
* **Removing the `!arqTcaseId` guard in `arqLoadReqs()`** — that would mask the missing
  assignment and send `tcase_id=0` to the API, which rejects it with a `400`. It would convert a
  visible dead end into an opaque HTTP error toast.
* **Reading the id from the URL** — the screen has no `tcase_id` parameter; the test case is
  chosen from the tree, so the URL is not a valid source of truth. The caller's `tc.id` already
  carries the correct, authoritative value.

### 3.2 i18n

The new user-facing message is key `reqAssign.noTestCase`, added to **all 10** bundles
(`de, en, es, fr, it, ja, pt, ro, ru, zh`) with a real translation per locale — e.g.
`ro`: *"Niciun caz de test selectat. Deschideți Asociere cerințe dintr-un caz de test."*.
Each bundle was validated with `python3 -m json.tool`; `git diff --stat` confirms **exactly one
inserted line per bundle** and no reformatting churn.

## 4. Verification

Full detail, with the measured value of every case, in `tmp/TLU_Test_Cases.md`
(§ *Regression — Issue #1598*). Summary — **13/13 PASS**:

| Area | Result |
|---|---|
| Open the modal on a test case | `arqTcaseId=9`, FREE = 2 requirements, counters `(2)` / `(0)` |
| **Assign** | toast `1 requirement(s) assigned.`, item moves to ASSIGNED, counters `(1)` / `(1)` |
| **Unassign** | toast `1 link(s) removed.`, item returns to FREE, counters `(2)` / `(0)` |
| Specification combo | lists reload from `assign-reqs`, state preserved |
| Close (Cancel / Close) | `arqTcaseId → 0`, `arqSpecs → []`, 0 options, modal hidden |
| **Switching test case** | no cross-test-case leakage in either direction |
| Spec-less project | "no specification" hint shown, no `assign-reqs` request |
| Guard | **no HTTP request issued**, localised toast shown |
| Sibling screens | `tcView.html` modal still works and agrees with testSpec's state |
| i18n | key present once in all 10 bundles, all valid JSON |
| Console | no errors or warnings |
| `events` table | no new Error/Warning rows; `audit_reqv_assigned_tcv` / `audit_reqv_assignment_removed_tcv` confirm both mutations are audited |

## 5. Follow-up filed

While testing, a **separate latent defect** was found and filed on its own issue, not fixed here
(per the "never fix new bugs silently" rule):

* **#1656** — `requirement_spec_mgr::get_all_in_testproject()` and `::get_by_title()` raise
  `1054 Unknown column 'RSPEC.scope'` against the 2.0.1 `req_specs` schema (the table has only
  `id`, `testproject_id`, `doc_id`), and the caller passes an undefined `REQ_SPEC` alias. Only
  the legacy Requirement Specification **reorder** screen is affected; the modernized screens
  never call these methods, so #1598 is not impacted.
