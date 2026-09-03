# Issue 828 — Residual i18n: project-scoped build duplicate/hint messages still said "test plan"

## Issue #828

Sub-task of enhancement
[#503](https://github.com/sebiboga/testlink-upgraded/issues/503) — *"builds
should be scoped to the Test Project, not to a single Test Plan"*. The core
`build.class.php` move to project scope was implemented and verified in commit
`29a10a8f1` (`feat(builds): scope build manager to test project ...`, Refs #828).
This fix addresses a **residual UI wording bug**: the modernized Builds screen
still told the user that build names must be unique *"within the test plan"*,
even though uniqueness is now enforced **per test project**.

## The problem (root cause)

After #828 the build manager operates project-wide:

- `lib/functions/build.class.php` — `checkNameExistence()` filters on
  `testproject_id` (`:676-677`), and `create()`/`createFromObject()` use it
  (`:144`).
- `lib/functions/testplan.class.php` — `check_build_name_existence()` resolves
  the plan to its project (`:2478-2505`) and `get_builds()` lists all builds in
  the plan's project (`:2291`).

Consequently a build name can be **rejected even though no build of that name
exists on the current test plan** — it exists on a *different* plan of the same
project. Two user-facing strings in the modernized Builds screen
(`gui/templates/plans/buildsView.html`) were **not re-worded** for this:

- inline hint: i18n key `bv.nameHint` — *"must be unique within the test plan"*
  (`buildsView.html:106`)
- duplicate toast: i18n key `bv.msg.duplicateName` — *"A build with that name
  already exists in this test plan"* (`buildsView.html:243,254-255`)

Both describe the wrong (plan) scope and mislead the user should a name be
rejected because of a build on another plan.

> Note: milestone messages (`ms.*` keys, `planMilestones.html`) were
> intentionally **not** changed — milestones remain plan-scoped.

## The fix (HOW)

Chosen method: **wording-only i18n update**. The machine error code
(`warning_duplicate_build`, `api/builds/index.php:268,470`) and the backend
exception text in `build.class.php` were already correct from `29a10a8f1`;
only the front-end localized strings were stale.

1. Changed the two keys from *"test plan"* to *"test project"* in **all 10**
   locale bundles under `gui/templates/i18n/`:
   - `bv.msg.duplicateName` → "…already exists in this **test project**"
   - `bv.nameHint` → "must be unique within the **test project**"
   (and equivalent translations in de, es, fr, it, ja, pt, ro, ru, zh).
2. Updated the HTML fallback text at `buildsView.html:106` for consistency
   (`data-i18n` overwrites it at runtime anyway).
3. Left `ms.*` (milestone) keys untouched — milestones stay plan-scoped.

### Why this method (alternatives rejected)

- **Changing the server message** — rejected: `api/builds/index.php` returns the
  machine code `warning_duplicate_build`, which the screen maps to a localized
  key; the server text is internal (`'warning_duplicate_build'`), not user-facing.
- **Rewording the milestone strings too** — rejected: not in scope, milestones
  are genuinely plan-scoped.

## Files changed

* `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json` — line 163
  `bv.msg.duplicateName`, line 168 `bv.nameHint`: "test plan" → "test project".
* `gui/templates/plans/buildsView.html:106` — HTML fallback for `bv.nameHint`.
* `docs/screenshots/issue-828-misleading-duplicate-message.png` — before fix.
* `docs/screenshots/issue-828-fixed-duplicate-toast-project-scope.png` — after.

Commit: `1cb4ad533` (`fix(builds): re-word project-scoped duplicate/hint i18n
from 'test plan' to 'test project' (Refs #828)`).

## Verification

Ran against the running app (admin/admin, project **M828** id 2100, plans
**P-A** 2101 / **P-B** 2102, builds `v2.0`/`v2.1` in project 2100):

* Create-Build modal hint reads "must be unique within the **test project**".
* Attempting create of `v2.0` on plan **P-B** (name exists on the OTHER plan
  **P-A**) → rejected with toast "…already exists in this **test project**:
  v2.0".
* Unique name `unique-build-828` → created then deleted cleanly.
* `builds` table unchanged (no phantom rows); `events` clean (only INFO-level
  login/build audits, no Error/Warning); browser console only the expected
  `409 Conflict` from the intentional duplicate attempt.
* `python3 -m json.tool` valid for all 10 bundles.
* Code-review subagent: minimal, correct, safe.

## Regression matrix

* (a) cross-plan duplicate rejected + wording = "test project" → PASS.
* (b) unique-name creation works → PASS.
* (c) milestone `ms.*` messages unchanged ("test plan") → unchanged.
* (d) all 10 bundles valid JSON → PASS.
* (e) Event Viewer / `events` — no new Error/Warning → PASS.

Test suite recorded in `tmp/TLU_Test_Cases.md` — **Regression — Issue #828**
(TC-828.1–828.8), **8/8 PASS**.
