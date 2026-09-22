# Task 955 — Expose Display-on-execution (show_on_execution) in cfieldsView table and create/edit form

**Issue:** [#955](https://github.com/sebiboga/testlink-upgraded/issues/955)
**Status:** IMPLEMENTED & VERIFIED (2026-09-22) — branch `task/issue-955-show-on-exec`

## The gap

The legacy **custom field screens** rendered the field's *Display on execution*
(`show_on_execution`) capability in two places:

- `gui/templates/dashio/cfields/cfieldsView.tpl:49,62` — a dedicated
  **"Display on execution"** column that renders the `displayOnExec` icon
  (`<i class="fa fa-desktop"></i>`, `lib/functions/tlsmarty.inc.php:546`) for
  every field whose `show_on_execution` is set;
- `gui/templates/dashio/cfields/cfieldsEdit.tpl:171-178` — a **"Display on test
  execution"** Yes/No combo (`cf_show_on_execution`) that lets the user control
  `show_on_execution` independently of the enable-on area. The combo is hidden
  (`cfieldCfg->cf_show_on.execution.style`) when it cannot apply, driven by
  `cfieldCfgIni`/`cfieldsEdit.php:70-96`.

The rules that guard the combo (must be preserved by any rewrite):

- `lib/cfields/cfieldsEdit.php:93-96` — in edit mode, `enable_on_execution`
  truthy ⇒ the combo is hidden (enable-on execution *implies* show-on).
- `gui/templates/dashio/cfields/cfieldsEditJS.tpl:251-269` — `initShowOnExec()`:
  switching the enable-on area to `execution` hides the combo **and forces**
  `show_on_execution=1`; selecting any other area re-shows it.
- `lib/cfields/cfieldsEdit.php:198-227` — `request2cf()` hard-codes
  `enable_on_<area> == 1` ⇒ `show_on_<area> = 1` server-side.
- `lib/functions/cfield_mgr.class.php:167-186` — `show_on_cfg['execution']` is
  set for build/testsuite/testplan/testcase but **0 for requirement_spec and
  requirement**, so requirement fields can never display on execution.

The modern screens dropped all of this:

- `gui/templates/cfields/cfieldsView.html:219-222` — the "Available On" column
  listed only the `enable_on_*` contexts; `show_on_execution` (e.g. the seeded
  `Tier` field, `show_on_execution=1`) rendered just "Design" with no display-on
  icon.
- Both edit modals (`cfieldsView.html`, `gui/templates/cfields/
  cfieldsAssignView.html`) had no "Display on test execution" control at all.
- `api/cfields/index.php:212` — `POST /api/cfields` hard-coded
  `show_on_execution => 0`, so even a field enabled on execution stored 0 until
  an edit re-saved it.

**Measured gap (fresh import, no fixtures):** `GET /api/cfields` returned
`show_on_execution` correctly (BFF `cfToJSON`, `index.php:96`) but the value was
never rendered, never editable, and `POST` always wrote 0.

## Implementation

### BFF — `api/cfields/index.php` (Refs #955)

- **POST / (create)** — `show_on_execution` is now read from the request body
  instead of being hard-coded to 0. The legacy rule is enforced server-side:
  `enable_on_execution == 1` ⇒ `show_on_execution = 1`, exactly like
  `request2cf()` (`cfieldsEdit.php:198-227`).
- **PUT /{id} (update)** — same enforcement: `enable_on_execution` forces
  `show_on_execution=1`; otherwise the submitted flag is honoured.
- **Requirement scrub** — for node types `requirement_spec` / `requirement`
  both flags are forced to 0 (parity with the legacy missing-keys defaults in
  `request2cf()`): a requirement field can never carry `show_on_execution` /
  `enable_on_execution`, regardless of what the client sends.

### Screens — `cfieldsView.html` + `cfieldsAssignView.html`

- **cfieldsView.html**: new **"Display on execution"** table column between
  Active and Available On. Renders the legacy `fa fa-desktop` icon (teal) with
  a `cf.showOnExec` tooltip when `cf.show_on_execution` is set, otherwise `-`.
  DataTables columns array extended to 7 columns.
- **Both modals**: new **"Display on test execution"** checkbox
  (`#editShowOnExecution`, i18n `cf.showOnExec`) with a hint line
  (`cf.showOnExecHint`).
- **`NO_EXEC_DISPLAY_NODES`** (`['requirement_spec','requirement']`) mirrors
  `show_on_cfg`; `applyShowOnExecVis()` implements the legacy combination:
  - group hidden + checkbox force-checked while `#editEnableExecution` is
    checked (initShowOnExec parity);
  - group hidden + checkbox force-cleared for requirement node types
    (a stale check after switching node type is scrubbed, just like legacy
    `request2cf` defaulted both flags to 0);
  - otherwise visible and freely editable — the independent toggle.
- Wired into `showCreateModal()` / `editCf()` / `saveCf()` in both screens; the
  assign screen's name links (Assigned + Available) share the same `editCf()`.

### i18n

`cf.displayOnExec` ("Display on execution"), `cf.showOnExec` ("Display on test
execution") and `cf.showOnExecHint` added to all 10 bundles
(`gui/templates/i18n/{en,ro,de,fr,es,it,pt,ja,zh,ru}.json`). Translations reuse
the legacy `$TLS_display_on_exec` / `$TLS_show_on_exec` wording from
`locale/*/strings.txt` where available; `ro` and `it` get fresh faithful
translations. All bundles validated with `python3 -m json.tool`.

## Verification evidence (browser + API, fixture project id=1 + fields tier/env)

- **Table column**: field `tier` (`show_on_execution=1`) renders the desktop
  icon; "Available On" lists "Design, Execution".
- **Enable-on ⇒ show-on (create)**: node `testcase` + "Enable On → Execution"
  checked hides the toggle and force-checks it; save stores
  `show_on_execution=1, enable_on_execution=1`.
- **Independent toggle (the gap)**: `env` created with only Design enabled and
  "Display on test execution" checked → stored `show_on_execution=1` while
  `enable_on_execution=0`.
- **Requirement nodes**: node `requirement` hides the group; switching from
  testcase to `requirement_spec` clears a previously checked toggle.
- **Assign screen**: modal opened from the Available-table name link shows the
  toggle; toggling off + Save → `PUT` persisted `show_on_execution=0`
  (`enable_on_execution=0`); re-enabled → 1.
- **BFF force rule (raw API)**: `POST {enable_on_execution:1, show_on_execution:0}`
  stores 1; `POST {enable_on_execution:0, show_on_execution:1}` stores 1
  (edge fields cleaned up after).
- Browser console clean; inline JS `node --check` clean; all 10 bundles valid.
- Event Viewer / `events`: only audit entries (`log_level=16`), **0 new
  Error/Warning** rows.

Suite 955 in `tmp/TLU_Test_Cases.md` — 9/9 PASS.

Screenshots: `docs/screenshots/issue-955-cfieldsView-table-showonexec.png`,
`docs/screenshots/issue-955-assign-modal-showonexec.png` (mirrored to wiki).

Refs #955.