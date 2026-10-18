# Test Plan Management (planView) — Modernized Screen 🗓️

Modernization of **Test Plan Management** (`lib/plan/planView.php`) — GitHub issue [#576](https://github.com/sebiboga/testlink-upgraded/issues/576).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/plans/planView.html`) backed by a plain-PHP REST BFF
(`api/plans/index.php`), following the same pattern as the other modernized
screens. The aside menu entry *Test Plan → Test Plan Management* now points to
the new HTML screen.

## What the screen does (legacy parity, nothing dropped)

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| List test plans of current project | `mgt_testplan_create` required | `GET /api/plans/?tproject_id=N` (403 otherwise) |
| Test case / build counts per plan | columns with tooltips | same counts + tooltips from BFF |
| Platforms column | only drawn when project has platforms | dynamic column (CSS class toggle) |
| Active/Inactive toggle per plan | `do_action=setActive/setInactive` | `PUT /api/plans/{id}/active` |
| Bulk Activate / Inactivate via checkboxes | `setActiveBulk/setInactiveBulk` | `POST /api/plans/bulk-active` |
| Delete plan (cascade) | `do_action=do_delete` + audit event | `DELETE /api/plans/{id}` + identical audit event |
| Create / Edit / Export / Import / Assign roles / Execute | links to their own controllers | served root-absolute by BFF `actions` map |
| Per-row "assign roles" icon | shown only with `testplan_user_role_assignment` | same right resolution: tplan role → tproject role → global role |
| Custom field columns | scaffolded in controller but dead code (`getCustomFieldsValues` undefined) | **dynamic CF columns** from `cfield_testplan_design_values` — refs [#584](https://github.com/sebiboga/testlink-upgraded/issues/584) |

## Screenshots

See [GitHub Wiki](https://github.com/sebiboga/testlink-upgraded/wiki/Test-Plan-Management) for screenshots.

## API summary (`api/plans/index.php`)

- `GET /?tproject_id=N` — plans + counts + rights + action URLs
- `PUT /{id}/active` — body `{tproject_id, active}`
- `POST /bulk-active` — body `{tproject_id, ids[], active}`
- `DELETE /{id}?tproject_id=N`

All mutations re-check `mgt_testplan_create` server-side and verify that the
target plan belongs to the context project.

## Bugs found & fixed during testing

1. **404 on Create/Edit/etc.** — BFF returned relative legacy URLs which
   resolved against `/gui/templates/plans/`; now root-absolute.
2. **Table never rendered** — unquoted jQuery attribute selectors
   (`th[data-i18n=pv.tcQty]`) threw a syntax error before `render()`.
3. **DataTables column-count alert** — hidden Platforms `<th>` vs 8-cell rows;
   visibility now handled by a stable CSS class with cells always populated.

## Testing

Full suite recorded in `tmp/TLU_Test_Cases.md`, suite 54 (16 checks PASS):
buttons, toggles, bulk ops, delete modal cancel/confirm, permission paths
(admin vs guest 403), i18n switch, Event Viewer clean.

## Create/Edit Test Plan modal — Refs #750

The "Create" and "Edit" actions were the last legacy leaves of this screen:
they used to redirect to `lib/plan/planEdit.php`. Both are now Bootstrap modals
built into `planView.html`, backed by dedicated BFF endpoints:

- `POST /api/plans/` — create plan `{tproject_id, name, notes, active, is_public}`;
  gates on `mgt_testplan_create`, rejects duplicate names (422 `DUPLICATE_NAME`),
  emits the same `CREATED` audit event as legacy `do_create`.
- `GET /api/plans/{id}?tproject_id=N` — single plan payload for modal pre-fill.
- `PUT /api/plans/{id}` — update plan with the same name-uniqueness check and
  `SAVE` audit event as legacy `do_update`.
- Private plans get the creator's effective role assigned (same as legacy).

Modal validation on the client mirrors the legacy form: name required, duplicate
name surfaced inline, Active/Public toggles. On success the DataTable refreshes
and a toast confirms `Test plan created` / `Test plan updated`.

![Test Plan Management with plan list](planView-create-modal-full.png)

![Create Test Plan modal](planEdit-create-modal.png)

![Edit Test Plan modal pre-filled](planEdit-edit-modal.png)

i18n keys `pv.modal.*` / `pv.msg.created|updated|duplicateName` are present in all
10 locale bundles. Test suite 49 (12 checks PASS) covers create/edit validation,
duplicate-name handling, and Event Viewer cleanliness — zero ERROR/WARNING.

## Create-from-existing Test Plan (copy) — Refs #1117

The **Create Test Plan** modal now restores the legacy
*"Create from an existing Test Plan?"* capability
(`gui/templates/dashio/plan/inc_controls_planEdit.tpl`) that the modern create
flow had dropped:

- A **Create from an existing test plan** dropdown lists every plan of the
  current test project (plus the default *"- no copy, create an empty test
  plan -"*).
- Picking a source reveals the full legacy option set: **Copy user roles**,
  **Copy attachments**, **Copy test cases** (radio *Latest version* / *Current
  version*), **Copy test case priorities**, **Copy builds**, **Copy tester
  assignments**, **Copy platform links** (checked & disabled — forced when test
  cases are copied, exactly like `testplan::copy_as()`), and **Copy milestones**.
- Unchecking *Copy test cases* hides the version/priority sub-options and
  re-enables the platform-links box; unchecking *Copy builds* hides *Copy tester
  assignments*.
- The copy block is shown only in create mode (editing an existing plan doesn't
  offer it), matching the legacy screen.

Server side, `POST /api/plans/` now accepts `copy_from_tplan_id`,
`copy_options{...}`, `copy_assigned_to` and `tcversion_type` and delegates to
`testplan::copy_as()` (same logic as the full-screen editor `api/planedit`). It
validates that the source plan exists (404 `COPY_SOURCE_NOT_FOUND`) and belongs
to the same project (400 `COPY_SOURCE_MISMATCH`).

All labels reuse the existing `pe.copy.*` / `pe.copySection` / `pe.copyNone`
i18n keys, present in all 10 locale bundles.

Screenshot: [GitHub Wiki](https://github.com/sebiboga/testlink-upgraded/wiki/Test-Plan-Management) (`1882-planview-copy-from.png`).

### Bug fixed en route: `testplan::copy_builds()`

`copy_builds()` (`lib/functions/testplan.class.php`) inserted a non-existent
`builds.testplan_id` column, so **any** plan copy with the default *Copy builds*
option fataled with `1054 Unknown column 'testplan_id'` — this also broke the
#1882 full-screen create/copy and the legacy project-copy path
(`testproject::copy_as` → `testplan::copy_as`). It now writes the project-scoped
`builds.testproject_id` and, when a build with the same name already exists in
the destination project (same-project plan copy), reuses that row instead of
failing on the `(testproject_id,name)` unique key.

## API Key display in the Edit modal — Refs #1118

The legacy edit form (`gui/templates/dashio/plan/planEdit.tpl:123-130`) showed the
plan's `api_key` read-only — only when the key was non-empty
(`{if $gui->api_key != ''}`). The modern inline **Edit Test Plan** modal
(`gui/templates/plans/planView.html`) had dropped it, so the key that uniquely
identifies a test plan for the REST/XML-RPC API could no longer be read or copied
from the UI.

Restored, mirroring the project API key row in `projectsView.html`:

- `GET /api/plans/{id}` now returns `api_key` alongside `id/name/notes/active/is_public`.
- The edit modal renders a read-only monospace row (`#apiKeyVal`,
  `role=textbox aria-readonly=true`, `user-select:all`) with a **Copy** button
  (`navigator.clipboard` with an `execCommand` fallback) and a short hint.
- The row is shown **only in edit mode and only when the key is non-empty**,
  exactly like legacy; the create modal never shows it.
- Switching between plans (or edit → create → edit) resets the value and the copy
  button label — the read-only `<div>` is not a form control, so it is cleared
  explicitly on every modal open.

i18n keys `pv.apiKey`, `pv.apiKeyCopy`, `pv.apiKeyCopied`, `pv.apiKeyCopyFailed`,
`pv.apiKeyHint` exist in all 10 locale bundles (`bash ai/verify_i18n_coverage.sh`
passes). The key is never editable: it is generated server-side by
`testplan::create()`.

## Attachments in the Edit modal — Refs #1119

The legacy edit form (`lib/plan/planEdit.php` `do_viewAttachments`/`do_fileHandling`,
`gui/templates/dashio/plan/planEdit.tpl:192-196` → `attachments.inc.tpl`) let a test
plan carry supporting artifacts: an upload form (file + optional title) and a list of
the existing attachments with download and delete. The modern **inline Edit Test Plan**
modal (`gui/templates/plans/planView.html`) had dropped that section — only the
full-screen editor (`planEdit.html`, Refs #1882) exposed it.

The modal now renders the full legacy capability (edit mode only — a plan must exist
before it can own attachments), backed by the same `api/attachments` BFF the
full-screen editor uses (`action=list|upload|delete|download`, `fk_table=testplans`),
so no new BFF routes were needed and the DB format stays legacy-compatible:

- **List** — title / filename / size / date columns with a **Download** link and a
  per-row **Delete** button (confirm before delete), empty-state "No attachments yet.",
  max-file-size hint from the BFF.
- **Upload** — optional *Title* + *File*, single-file upload via
  `POST /api/attachments` (`uploadedFile[]` FormData), inline feedback messages.
- State is reset on every modal open (`resetPlanAttachments`), so a previous plan's
  list/uploads cannot leak into the next plan; the section is hidden in create mode.

All labels reuse the existing `pe.att*` / `pe.msg.upload*|delete*` i18n keys, already
present in all 10 locale bundles (`bash ai/verify_i18n_coverage.sh` passes).

Screenshot: [GitHub Wiki](https://github.com/sebiboga/testlink-upgraded/wiki/Test-Plan-Management) (`1119-planview-attachments.png`).

## Accessibility: label/field associations — Issue #1939

Chrome DevTools raised two Issues entries on this screen (Refs #1939, found
while verifying the navBar fix #1938): **`No label associated with a form
field (count: 4)`** and **`A form field element should have an id or name
attribute`** (count 1 on an empty table, +1 per rendered plan row).

**Root cause.** Four distinct defects in `gui/templates/plans/planView.html`:

1. The Dashio port kept four *visual* `<label>`s that have neither `for=` nor a
   wrapping control: `:134` Name (→ `#planName`), `:140` Description
   (→ `#planNotes`), `:157` API Key (→ `#apiKeyVal`, a **`<div
   role=textbox>`** — not a labelable element, so `for=` could not legally
   target it), `:172` Attachments (a section caption over a list + Title input
   + File input + Upload button — no single control to point at).
2. The row checkbox built in `render()` (`:454`) carried neither `id` nor
   `name` — one audit hit **per row**.
3. The always-present hit behind `count: 1` on an **empty** table was neither
   of the above: it was **DataTables 1.13.7's own generated filter input**
   (`#tplanTable_filter input[type=search]`), which ships with neither `id`
   nor `name` (the generated `tplanTable_filter` id sits on the wrapping
   `<div>`). The issue's initial suspicion (row checkbox only) accounted for
   just half the symptom — measured by DOM audit before/after creating the
   first plan (count 1 → 2).

**Fix (minimal — `planView.html` only).** `for="planName"` / `for="planNotes"`
on the two labels whose controls already exist; the API-Key and Attachments
captions converted to `<span>` (API-Key caption got `class="api-key-label"`
and the CSS rule `.api-key-row > label` was retargeted to `.api-key-row >
.api-key-label`, so styling is byte-identical); row checkbox got
`id="rowChk-<planId>"` (unique per row, mirrors `#chkAll`); after every
DataTable init in `render()` the filter input receives
`id="tplanTableSearch"`/`name="tplanTableSearch"` (re-applied each cycle
because `render()` destroys and re-creates the table). Alternatives rejected:
`aria-label` duplicates translatable text that can drift (rejected in #1938
too); `for="apiKeyVal"` is invalid (div is not labelable) and the audit would
still fire; wrapping the Attachments section inside the label puts block
content in a `<label>` and ambiguously labels 3 controls; forking DataTables
config — 1.13.7 has no option for the filter input's id. **No i18n keys were
added or changed** — all four captions keep their existing `data-i18n` keys
(`pv.modal.name`, `pv.modal.description`, `pv.apiKey`, `pe.attTitle`) and
`i18n.apply` writes text tag-agnostically.

**Verified** (headless Chrome, chrome-devtools MCP, fixtures project 1
`A11yProj` + plan 2 `SmokePlan`): Issues log on a fresh load
**`<no console messages found>`** (pre-fix: both entries, counts 4 and 1→2);
DOM audit `labelsNoFor=[]`, `fieldsNoIdName=[]` with 0, 1 and 2 plans and in
the ro locale; label-click focuses `#planName`/`#planNotes`; a11y names
`textbox "Name"`/`"Description"`; create plan, edit plan (API-key row +
Copy→`copied`→revert), delete plan (confirm dialog), select-all, bulk
activate/inactivate/empty-selection guard, DataTables search filter
(0↔1 rows), locale switch en→ro→en (converted spans translate: `Nume`,
`Cheie API`, `Atasamente`) all PASS; Event Viewer clean (8 rows, all
`log_level 16` audit, zero Error/Warning). Regression suite **Regression —
Issue #1939** 11/11 PASS. Code review: APPROVE, no findings.

**Files:** `gui/templates/plans/planView.html` (4 label/caption edits + row
checkbox id + filter-input wiring), `CHANGELOG`,
`docs/Test-Plan-Management.md` mirror. Same-class defects on **other**
screens (DataTables filter input on ~80 screens using `DataTable(`, `row-chk`
in `planUpdateTC.html`/`cfieldsAssignView.html`) are out of this issue's
scope and filed as **#1941**.

PlanView edit modal after the fix — Name/Description labels associated,
API-Key/Attachments captions converted to spans, row checkbox + search input
carry ids:

Screenshot: [GitHub Wiki](https://github.com/sebiboga/testlink-upgraded/wiki/Test-Plan-Management) (`issue-1939-planview-a11y-labels.png`).
