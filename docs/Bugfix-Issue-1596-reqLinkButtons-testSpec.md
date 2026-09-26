# Bugfix — Issue #1596: the requirement-linking buttons never render in Test Specification

**Screen:** `gui/templates/testcases/testSpec.html` (modern *Test Specification*, Dashio)
**Fixed by:** `d270ea7dd` — 2 lines, no backend change, no new endpoint, no new i18n key
**Verified:** 2026-09-26 — browser (headless Chrome 154) on a fresh database, signed in as `admin`; issue closed after that independent re-verification

---

## 1. Symptom

Two requirement-linking entry points on the Test Specification screen were **never rendered**,
with no error, no console message and no log entry:

* the **"Assign Requirements"** button in the **test case** view (single test case → requirement picker), and
* the **"Requirements Bulk Assignment"** button in the **test suite** view (added with #1595).

They stayed invisible even for an **Administrator** in a test project with
`option_reqs` (requirements) enabled, i.e. exactly the user who should see them.

## 2. Root cause

`ctx` is built in `init()` (`gui/templates/testcases/testSpec.html:542-554`) and enriched
from the context API response:

```js
ctx = { tproject_id: … };
ctx.options      = r.options || {};   // -> { requirementsEnabled, automationEnabled, testPriorityEnabled }
ctx.hasTestPlans = !!r.hasTestPlans;
```

Both gates read a property that **is not assigned in the current tree**:

```js
// testSpec.html:747  (showSuiteView)  and  testSpec.html:890  (renderTcView)
if (ctx.reqEnabled && !!grants['req_tcase_link_management']) { … }
```

`ctx.reqEnabled` is `undefined` → `undefined && …` is always falsy → the button HTML is
never appended. The API never sends such a key either; the flag the screen actually
receives is `options.requirementsEnabled` (`api/testcases/index.php:588`,
`tprojectOpt($opt, 'requirementsEnabled')`).

### 2.1 Why it breaks NOW — the regression source is a *merge*, not a missing line

The property **was** assigned when the feature was introduced. `d327ece3a`
(2026-09-15, *“feat(testspec): assign requirements button+modal in Test Specification
editor and Test Case Viewer”*) had both halves:

```js
// gui/templates/testcases/testSpec.html:257 in d327ece3a
ctx.reqEnabled = !!(r.options && r.options.requirementsEnabled);
…
// gui/templates/testcases/testSpec.html:620 in d327ece3a
if (ctx.reqEnabled && !!grants['req_tcase_link_management']) { … }
```

`d327ece3a` is an ancestor of `4673dd4f5` (parent 2 of the merge `8038f9670`,
2026-09-15 *“Merge branch 'origin/sebiboga' into sebiboga”*). Measured on the three trees:

| tree | `ctx.reqEnabled =` | `if (ctx.reqEnabled` |
|---|---|---|
| parent 1 `492b8280c` | 0 | 0 |
| parent 2 `4673dd4f5` (has `d327ece3a`) | 1 | 1 |
| **merge result `8038f9670`** | **0** | **1** |

The merge resolved the conflict in favour of the side **without** the feature, so it kept
the gate and dropped the assignment: the button was therefore working between `d327ece3a`
and `8038f9670`, and has been silently missing ever since. #1595 then added the second
gate (`testSpec.html:890`, suite view) copying the already-broken pattern, so the new
screen inherited the dead gate on its first day.

The failure was **silent by construction**: no undefined-property warning (reading a
missing key of an existing object is legal JS), no failed request, so nothing landed in
the Event Viewer.

## 3. The fix

```diff
--- a/gui/templates/testcases/testSpec.html
+++ b/gui/templates/testcases/testSpec.html
@@ -744,7 +744,7 @@ function showSuiteView() {
-  if (ctx.reqEnabled && !!grants['req_tcase_link_management']) {
+  if (ctx.options && ctx.options.requirementsEnabled && !!grants['req_tcase_link_management']) {
     h += '<button class="btn-dark btn-sm" onclick="openReqBulkAssign()">… ' + t('rtcb.title') + '</button>';
   }
@@ -887,7 +887,7 @@ function renderTcView(r) {
-  if (ctx.reqEnabled && !!grants['req_tcase_link_management']) {
+  if (ctx.options && ctx.options.requirementsEnabled && !!grants['req_tcase_link_management']) {
     h += '<div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">' +
       '<button class="btn-dark btn-sm" onclick="openAssignReqs(' + tc.id + ')">… ' + t('tspec.assignRequirements') + '</button>' +
```

**Why this shape**

* **Minimal** — two tokens per line, no refactor, no behavioural change beyond the gate.
* **Null-safe** — `ctx.options &&` is evaluated *first*, so the screen still renders if
  `context` ever answers without `options` (the same defensive shape used by
  `ctx.options.automationEnabled` at `testSpec.html:811`, `:1155`, `:1395`).
* **Consistent with the file's own conventions** — the surrounding code already reads the
  sibling flags as `ctx.options && ctx.options.<flag>`; the two `reqEnabled` reads were
  the only outliers.
* **Right-hand side untouched** — the `grants['req_tcase_link_management']` right check
  (and the surrounding Dashio `btn-dark` markup / i18n keys `rtcb.title`,
  `tspec.assignRequirements`) is preserved, so the button still disappears for users
  without the right and for projects with requirements disabled.

Rejected alternatives: hard-coding the button (would bypass both the project option and
the role right — the very parity #1595 restored); adding `ctx.reqEnabled = …` next to
`init()`'s other assignments (fixes only this screen's symptom while leaving the wrong
property name in place for the next reader); making the context API emit a `reqEnabled`
alias (an API change for a purely cosmetic reason, and the screen already had the right
flag under its own name).

## 4. How the fix was verified (before / after)

All ids, event ids and counters below come from **one ephemeral run on a freshly imported
database**; they are evidence of that run, not stable values.

`tmp/fixtures_1596.php` → test project **REQ1596** (id 13, requirements ENABLED),
specification `RS1596` (id 14) with 2 requirements, test suite **Suite A** (id 20),
test case **REQ1596 TC 01** (id 21). Signed in as `admin` / `admin`
(Administrator → `req_tcase_link_management` granted).

The *before* state was obtained by reverting the two gated lines **in the working copy
only** (never committed) and hard-reloading the screen; the working copy was then
restored from git and confirmed clean.

| Probe in the page console | Before (bug) | After (fixed) |
|---|---|---|
| `ctx.reqEnabled` | `undefined` | `undefined` (the property is simply unused now) |
| `ctx.options.requirementsEnabled` | `true` | `true` |
| `grants['req_tcase_link_management']` | `true` | `true` |
| `#suiteView` buttons | 6 buttons, **no** *Requirements Bulk Assignment* | 7 buttons, **with** *Requirements Bulk Assignment* (`onclick="openReqBulkAssign()"`) |
| `#tcView` buttons | 7 buttons, **no** *Assign Requirements* | 8 buttons, **with** *Assign Requirements* (`onclick="openAssignReqs(21)"`) |
| `window.open` target of the suite button | – | `/gui/templates/requirements/reqTcBulkAssign.html?tproject_id=13&tsuite_id=20` |
| `GET /api/requirements/index.php/assign-reqspecs` | – | `200 {"status":"ok","items":[{"id":14,"name":"[RS1596] - RS1596 Specification"}]}` |

The **suite-level** restored entry point is functional, not merely visible
(the per-test-case modal opens but is dead inside — see §6 / #1598):

* **Suite → Requirements Bulk Assignment** — the grid lists both requirements with
  `not linked to any test case of this suite (1)`; check-uncheck-all + **Assign to all
  test cases of the suite** + confirm
  (`Assign 2 selected requirement(s) to 1 test case(s) of Suite A (#20)?`) writes the
  links; both rows then read `1/1 test cases of this suite already linked` and the audit
  event `events.id=9 · log_level=16 · object_type=testsuites · object_id=20` is written.
* **Test case → Assign Requirements** — the modal opens with the project's
  specification combo, FREE / ASSIGNED lists and the Assign / Unassign / Cancel / Close
  actions.
* **Event Viewer** — `select log_level, count(*) from events group by log_level` →
  `16 → 9` rows, i.e. **0 ERROR and 0 WARNING** (levels 1 and 2); console clean on both screens.

### 4.1 Residual edge worth recording (pre-existing, not introduced here)

`tprojectOpt()` returns `false` when a project's serialized options blob lacks the
`requirementsEnabled` key (`api/testcases/index.php:52-60`, with a missing blob becoming
`new stdClass()` at `:480-481`), whereas the write route's `projReqsEnabled()`
(`api/requirements/index.php:1813-1824`) falls back to **default-true**. A legacy project
with a missing/partial options blob therefore still sees no button, even though the BFF
would accept the write. No false positive is possible (the BFF re-checks both the right
at `api/requirements/index.php:2181` and the project option at
`api/reqtcbassign/index.php:141`), and the fix strictly improves on "always hidden" —
recorded for the next reader, not fixed here.

## 5. Regression test suite

`tmp/TLU_Test_Cases.md`, section *“Regression — Issue #1596”*, **6/6 PASS**:
pre-fix reproduction of both missing buttons, post-fix rendering + handler arguments of
both buttons, end-to-end bulk assignment with DB/audit evidence, Event Viewer + console
hygiene.

Screenshots: `docs/screenshots/issue-1596-prefix-tcview.png` (before),
`docs/screenshots/issue-1596-postfix-assign-requirements.png` (after),
`docs/screenshots/issue-1596-postfix-bulk-assign-button.png` (suite toolbar).

## 6. Follow-up found while testing — **#1598** (separate root cause, not fixed here)

The restored **"Assign Requirements"** modal always shows **empty** FREE / ASSIGNED lists.
Cause: `openAssignReqs(tcaseId)` (`testSpec.html:357`) never stores the id in the
module-level `arqTcaseId` (`testSpec.html:433 var arqTcaseId = 0;`), so `arqLoadReqs()`
returns at `testSpec.html:383` before its `GET /assign-reqs` call and the Assign /
Unassign actions would post `tcase_id=0`. The backend is healthy —
`GET /assign-reqs?req_spec_id=14&tcase_id=21` answers `200` with
`{"status":"ok","all":[…],"assigned":[],"unassigned":[{"id":16,"doc_id":"REQ-001",…},{"id":18,"doc_id":"REQ-002",…}]}`
(each element is an object from `arReqRowToJSON()`, `api/requirements/index.php:2291-2298`;
the route also returns `all`, which the quote above omits for brevity).

That is a different property, a different code path and a different symptom (button
*present but dead* vs button *missing*), so it was **filed as its own `bug` issue #1598**
instead of being folded into this one; the one-line remedy is recorded there. Consequence
to keep in mind: until #1598 is fixed, the single-test-case linking has no working path
in 2.0.1 — the suite-level grid of #1595 remains the working alternative.

## 7. Files

| File | Role in this bugfix |
|---|---|
| `gui/templates/testcases/testSpec.html` | the only changed file: lines `747` (suite view) and `890` (test case view) |
| `api/testcases/index.php` | untouched — read only, to confirm `options.requirementsEnabled` (`:588`) is the flag the screen receives |
| `tmp/fixtures_1596.php` | re-runnable fixture for the before/after verification (project, spec, 2 requirements, suite, test case) |
| `tmp/TLU_Test_Cases.md` | regression suite *“Regression — Issue #1596”*, 6/6 PASS |
| `docs/screenshots/issue-1596-*.png` | before / after evidence |
| `CHANGELOG` | the `Key bugfix #1596` line under the 2.0.1 section |

Refs #1596 · follow-up #1598 · related #1595
