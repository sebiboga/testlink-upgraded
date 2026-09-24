# Task 965 — Implement per-type configuration template loader (getCfgTemplate) in issuetrackerView (gap vs legacy)

**Issue:** [#965](https://github.com/sebiboga/testlink-upgraded/issues/965)
**Status:** IMPLEMENTED & VERIFIED (2026-09-24)

## The gap

Legacy edit form `gui/templates/dashio/issuetrackers/issueTrackerEdit.tpl:24-65`
(`displayCfgExample`) puts an **eye icon** (`fa-eye`, tooltip
`show_hide_configuration_example`) next to the Configuration field. Clicking it
AJAX-loads `lib/ajax/getissuetrackercfgtemplate.php?type=N` and INJECTS the
selected interface's `$iname::getCfgTemplate()` as a `<pre><xmp>` block into
`#cfg_example`, or the localized messages:

- `issuetracker_interface_not_implemented` — interface class file is missing;
- `issuetracker_invalid_type` — type unknown OR disabled (`getTestLinkType` /
  `getTypes()` maps ENABLED types only).

Browser probe (legacy, pre-fix): `?type=15` → redmine template; `?type=999` →
"Issue Tracker type 999 is unknown".

The modern screen dropped it — the Configuration field was a static textarea
placeholder with no way to load the per-type template.

## Investigation (measured)

- `api/issuetracker/index.php` had no route serving the per-type template.
- `issuetrackerView.html` trackerModal had only the Configuration textarea; DOM
  probe `#cfgExample` absent; no eye helper.
- Legacy `getissuetrackercfgtemplate.php` semantics reproduced: it maps the type
  through `tlIssueTracker::getTypes()` (enabled-only, `tlIssueTracker.class.php:150-160`),
  resolves the interface via `getImplementationForType()` and probes the class
  file with `stream_resolve_include_path()` BEFORE any include — a missing
  interface never triggers the autoloader's `include_once` (thus never logs an
  E_WARNING into the events table).

## Fix

**BFF** `api/issuetracker/index.php` — new route
`GET /api/issuetracker/index.php/cfg-template?type=N` mirroring the legacy ajax:

- `$mgr->getTypes()` gating (`isset($itt[$type])`) → disabled/unknown types get
  `{status:error, code:invalid_type, type:N}` (getTypes parity — disabled types
  are "invalid" like legacy).
- `stream_resolve_include_path($iname.'.class.php')` probe → missing interface
  returns `{status:error, code:interface_missing, iface:$iname}` WITHOUT ever
  reaching the autoloader (no E_WARNING rows, legacy parity).
- Present class → `require_once()` (only if `!class_exists($iname,false)`) then
  `{status:ok, type:N, template:$iname::getCfgTemplate()}`.

The JSON BFF has no `lang_get`, so error codes are structured and the client
localizes them via `TLi18n` interpolation (`{type}` / `{iface}`).

**HTML** `gui/templates/issuetracker/issuetrackerView.html`:

- Eye anchor `#btnCfgExample` (fa-eye, localized tooltip
  `it.showHideConfigExample`) beside the Configuration label.
- Hidden `#cfgExampleOuter` + `#cfgExample` `<pre>` block (max-height 220px,
  overflow auto, `white-space:pre-wrap`).
- JS `loadCfgTemplate(done)` — GETs `/cfg-template?type=N`, draws the raw
  template via `textContent` (xmp parity, no innerHTML — XSS-safe), or the
  localized `it.msg.interfaceMissing` / `it.msg.invalidType` / `it.msg.errorTemplate`.
- JS `toggleCfgTemplate()` — port of legacy `displayCfgExample` hide/show toggle.
- `#editType` change handler refreshes a SHOWN example (requested in #965: the
  cfg keys must follow the selected interface).
- `hidden.bs.modal` + `showCreateModal()` + `editTracker()` collapse the block
  on close/reset (legacy collapsed state parity).

**i18n** — new keys `it.showHideConfigExample`, `it.cfgExample`,
`it.msg.invalidType`, `it.msg.interfaceMissing`, `it.msg.errorTemplate` added to
ALL 10 locale bundles (en/ro/de/es/fr/it/ja/pt/ru/zh), each validated with
`python3 -m json.tool`; key sets identical across bundles. Note: the JSON BFF
`it.msg.invalidType`/`it.msg.interfaceMissing` German keys carry the legacy
"Traker" typo — corrected to "Tracker" in 2.0.1.

## Verification

- **Click eye (type=GitHub)**: `#cfgExampleOuter` shown with the github REST
  `getCfgTemplate()` block; second click hides it; re-open resets.
- **Type change (create modal)**: eye shown → switch Type 25→15 → example
  auto-refreshes to the redmine template.
- **Edit modal**: opening an existing tracker (Redmine Tracker, type 15) +
  eye click → redmine template.
- **Invalid type**: `parseInt('')`→0 → client renders "Issue Tracker type 0 is
  unknown" (i18n `it.msg.invalidType`).
- **Interface missing**: BFF probe `?type=26` with
  `trellorestInterface.class.php` temporarily moved → `200
  {"status":"error","code":"interface_missing","iface":"trellorestInterface"}`,
  client renders "Issue Tracker interface trellorestInterface is not
  implemented/available". class restored after test.
- **E_WARNING hygiene**: the `stream_resolve_include_path` probe produced NO
  `events` E_WARNING rows for the missing-file test (old `class_exists`-based
  attempt logged 2 rows — measured, deleted; clean re-test shows only the LOGIN
  audit row). Also exercised the built-in-server 500 edge (a require_once fatal
  surfaced once during mid-edit; after the final clean code state the route is
  stable across repeated calls).
- **Locale RO**: `?locale=ro` → modal title "Creaza Urmator Probleme", eye
  tooltip "Arată/Ascunde exemplu de configurare", example label "Exemplu de
  configurare", template loads normally.
- **BFF parity**: `?type=26` (file present) → `ok` + trello template;
  `?type=15` → ok + redmine template; `?type=10` (disabled) →
  `invalid_type`; `?type=999` → `invalid_type`. Regression: check-connection +
  meta/types (26) intact.
- Event Viewer / `events` table: no new Error/Warning; browser console clean
  (only the pre-existing a11y notice).
- Screenshots: `docs/screenshots/issue-965-cfg-template-redmine.png`,
  `docs/screenshots/issue-965-cfg-template-ro.png`,
  `docs/screenshots/issue-965-edit-modal-template.png`.

## Test cases

Appended to `tmp/TLU_Test_Cases.md` (Suite 965, executed PASS); CHANGELOG updated.

**Status:** DONE — closed in #965.