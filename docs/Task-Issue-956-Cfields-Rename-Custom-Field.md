# Task 956 — Allow renaming a custom field (name) in the modern cfields edit modals

**Issue:** [#956](https://github.com/sebiboga/testlink-upgraded/issues/956)
**Status:** IMPLEMENTED & VERIFIED (2026-09-23) — branch `task/issue-956`

## The gap

The legacy **custom field edit** form lets the user change the field **name**
even when editing an existing field:

- `gui/templates/dashio/cfields/cfieldsEdit.tpl:83-89` — `cf_name` is a plain
  editable text box (`<input ... name="cf_name" ... value="{$gui->cfield.name|escape}">`),
  no read-only attribute in edit mode.
- `lib/cfields/cfieldsEdit.php:367-381` (`doUpdate`) — before saving it
  re-checks uniqueness with `name_is_unique($id, $name)`; a rename that
  collides with an existing name is rejected with
  `lang_get('cf_name_exists')` ("Custom field name already exists. Please
  choose a new one"). Create has the same guarded check
  (`cfieldsEdit.php:311-334`).

The modern screens **blocked** renaming entirely:

- `gui/templates/cfields/cfieldsView.html:318` — `$('#editName').val(cf.name)
  .prop('readonly', true)` forced the Name input read-only in the edit modal.
- `gui/templates/cfields/cfieldsAssignView.html:251` — the same
  `.prop('readonly', true)` in the Assign screen's edit modal (reachable from
  the Assigned/Available name links).

Meanwhile the modern BFF `PUT /api/cfields/{id}` (`api/cfields/index.php:268-274`)
already implemented the uniqueness guard and supported a changed name — the
front-end gate was the only thing in the way.

**Measured gap (browser DOM before the fix):** editing the "Deployment" field
returned `{"modalVisible":true,"readonly":true,"name":"Deployment",...}` —
`#editName.readOnly === true`. Screenshot:
`docs/screenshots/issue-956-edit-modal-readonly-before.png`.

## Implementation

### BFF — `api/cfields/index.php` (Refs #956)

- **PUT /{id} (update)** — the duplicate-name 400 response now carries the
  machine-readable `code: 'cf_name_exists'` plus the legacy localized message
  `lang_get('cf_name_exists', assignLocale())` instead of the raw English
  string (`:268-278`). This mirrors the pre-existing `warning_no_type_change`
  pattern so the front-ends can map the code onto the localized bundle text.
- **POST / (create)** — the same duplicate-name 400 now also returns
  `code: 'cf_name_exists'` + localized message (legacy `doCreate` surfaces
  `lang_get('cf_name_exists')` too, `cfieldsEdit.php:311-334`).

### Screens — `cfieldsView.html` + `cfieldsAssignView.html`

- **cfieldsView.html `editCf()`** (:318) — removed the
  `.prop('readonly', true)` on `#editName`; the Name input is now a plain
  editable text box in edit mode, parity with the legacy
  `cfieldsEdit.tpl:83-89`.
- **cfieldsAssignView.html `editCf()`** (:251) — same removal: the Assign
  screen's edit modal can now rename fields too.
- **Both `saveCf()`** — the error handler maps `resp.code === 'cf_name_exists'`
  onto `TLi18n.t('cf.msg.nameExists')` (fallback `resp.message` /
  `cf.msg.errorSave` remain).

### i18n

`cf.msg.nameExists` ("Custom field name already exists. Please choose a new
one") added to all 10 bundles (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`).
Translations ported from the legacy `$TLS_cf_name_exists` in
`locale/*/strings.txt`. All bundles validated with `python3 -m json.tool`.

## Verification evidence (browser + API, fixture project id=1 + fields)

- **Rename via cfieldsView edit modal**: "Deployment" → "DeploymentPrime"
  editable, Save persisted the new name in `custom_fields` (label unchanged).
- **Rename collision (cfieldsView)**: rename "Tier" → "DeploymentPrime"
  rejected, modal shows *"Custom field name already exists. Please choose a new
  one"*; switch to `?locale=ro` shows *"Numele campului personalizat există deja.
  Alegeți alt nume, vă rugăm."* — localization works.
- **Create collision (cfieldsView)**: Create with an existing name shows the
  same localized message.
- **Assign screen**: modal opened from the Assigned-table name link is editable
  (`readonly:false`); "Tier" → "TierY" persists to the DB and the table
  re-renders; collision rename shows the localized rejection and keeps the
  modal open.
- Browser console clean; inline JS `node --check` clean; `php -l` clean; all
  10 bundles valid JSON.
- Event Viewer / `events`: only audit entries (`log_level=16`), **0 new
  Error/Warning** rows.

Suite 956 in `tmp/TLU_Test_Cases.md` — 5/5 PASS.

Screenshots: `docs/screenshots/issue-956-edit-modal-readonly-before.png`
(before), `docs/screenshots/issue-956-edit-modal-editable.png` (edit modal,
Name editable), `docs/screenshots/issue-956-rename-collision-rejected.png`
(collision rejection), `docs/screenshots/issue-956-rename-support-after.png`
(after, list view).

Refs #956.