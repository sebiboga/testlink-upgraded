# Modernize: Custom Field Editor (`cfieldsEdit`)

**Refs #1812** (enhancement) · suite `Issue #1812` = **34/34 PASS**

## Why this screen

The TODO section of `docs/MODERNIZATION-STATUS.md` is empty — every ASIDE entry already maps to a
modern `.html` screen plus a BFF. The largest remaining legacy gap inside an already-modern area was
the standalone custom-field **definition** editor: `lib/cfields/cfieldsEdit.php` +
`gui/templates/dashio/cfields/cfieldsEdit.tpl`. The modern manager
(`cfieldsView.html`, Refs #957/#950/#955/#956) and assign (`cfieldsAssignView.html`, Refs #953)
screens only ported the *manager* half — create/edit/delete of the definitions themselves
(`do_create`/`do_update`/`do_delete` → `cfield_mgr::create()`/`update()`/`delete()`) existed nowhere
in 2.0.1.

## What was ported

Everything the legacy editor did, 1:1:

| Legacy feature | Modern screen |
|---|---|
| Name (short code), uniqueness hint, 25-char cap | Name field with maxlength + hint |
| Label, 50-char cap | Label field + hint |
| **Available on** node-type combo, filtered per type | Node-type select filtered by `configure_cf_attr()` parity |
| **Type** combo with the Possible-Values gate | Type select + Possible-Values textarea shown only for value-bearing types (`cfg_possible_values_display()` parity) |
| **Enable on** area + enable-implies-show propagation | Enable-on select + per-area *Display on* combos (`initShowOnExec()`/`configureCfAttr()` parity) |
| `is_used()` Type / Available-on **lock** | Read-only Type/Available-on + warning banner + `🔒` lock badge |
| `do_create` + **Add and assign** flow | Add / Add and assign buttons (assign = create + link, disabled without an addressed project) |
| `do_update` | Save (stays on the form, toast + re-render) |
| `do_delete` behind `warning_delete_cf` | Delete → Bootstrap confirm modal naming the CF |
| name-uniqueness probe | server-side, 409 `name_exists` |

## BFF

`api/cfieldsedit/index.php` — `GET ?action=init&do_action=create|edit&cfield_id=N[&tproject_id=P]`,
`POST ?action=create|update|delete`, session auth + `bffSameOriginGuard` + `bffEnforceSession`,
`cfield_management` on the **addressed** test project. Stable machine codes mapped to i18n keys:

`400 empty_name|empty_label|name_too_long|label_too_long|possible_values_too_long|unknown_type|
unknown_node_type|area_not_allowed_for_node_type|type_locked|node_type_locked|no_tproject_selected|
missing_cfield_id|missing_action|unknown_do_action` · `401` · `403 no_right|no_right_on_project` ·
`404 cfield_not_found|tproject_not_found` · `405 method_not_allowed` · `409 name_exists` ·
create/update/delete failure codes stay visible on the re-rendered form.

Legacy semantics preserved: a bare or missing `do_action` starts a **clean create form** even when
`cfield_id` is present (legacy `$query['do_action']` default); edit mode requires
`do_action=edit&cfield_id=N`. Used fields refuse a Type or node-type change (`type_locked` /
`node_type_locked`); a requirement-spec-node field refuses the execution area
(`area_not_allowed_for_node_type`).

Legacy `lib/cfields/cfieldsEdit.php` is a session-guarded shim: **302** to the modern screen for a
browser GET (anon → `login.php?note=expired`), **405** with an explanatory body for anything that
looks like a write (the legacy `do_delete` was a GET form).

## Security vs legacy

- The legacy editor authorized on the **global** `cfield_management` right via the session; the BFF
  re-verifies `cfield_management` on the addressed project.
- The addressed-project check runs **before** the project is resolved
  (`cfpaRequireManage()` convention): a caller with only the *global* right cannot tell a
  foreign/unreachable project (403 `no_right_on_project`) from a non-existent id, and a denial
  never leaks the project name. Admin keeps the honest 404 for a genuinely missing id.
- Writes are **POST-only** behind the same-origin CSRF proof (legacy wrote on GET); `PUT`/`DELETE`
  return 405 (method whitelist, code review M4).
- Every request-derived `(string)`/`intval` cast is guarded by `cfeScalar()`/`cfeInt()`
  (code review m1): a crafted `{"name":["x"],...}` body raises no PHP 8 `Array to string`
  E_WARNING and an array `"id"` never `intval()`'s to `1` (wrong-target write).
- The name-uniqueness probe stays server-side (legacy `name_is_unique`), never trusted to the client.

## Wiring

`$actions->cfieldsEdit` in `lib/functions/common.php` (`common.php:1954`) + Edit/Create buttons on
`cfieldsView.html` via `EDITOR_URL`. i18n `cfed.*` (68 keys) + `footers.cfieldsEdit` in all 10
bundles (`de en es fr it ja pt ro ru zh`); key-set gate `bash ai/verify_i18n_coverage.sh` PASS
(6959 keys / 0 missing per bundle).

## Verification

- API contract re-run on fresh fixtures (`php tmp/fixtures_1812.php`: projects CFE Main/CFE Other;
  cfields CFE1812A string, CFE1812USE checkbox→used/locked, CFE1812REQ req-spec): 401 anon, 403
  role-3 `norights` user, 404 unknown cfield, 405 bad verb+action, 409 duplicate name, 400
  `type_locked` (used) + `area_not_allowed_for_node_type` (req-spec on execution), 200 create/delete.
- Shim branches: authed GET → 302 modern, POST → 405, anon → 302 login.
- Browser (chrome-devtools): create → toast → redirect to `cfieldsView` with the new row; edit
  persists to DB; used-field lock banner + read-only combos; delete confirm modal → row gone;
  **`ro` locale switch renders header, dynamic form and footer in Romanian — no raw keys**;
  0 console errors.
- Event Viewer: no new ERROR/WARNING rows (only AUDIT `log_level=16`; the serialized
  `tlMetaStringHelper` in `events.description` is the standard logger format on read).
- Suite `Issue #1812` — **39/39 PASS** in `tmp/TLU_Test_Cases.md` (rows 1-34 original, 35-39 code
  review follow-up: method whitelist, array payloads, uniform 403/no-name-leak on the project
  scope, single show-on combo, per-code `{max}` errors); gate
  `TLU_REQUIRE_SUITE="Issue #1812" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL, 42 → 43 suites,
  none lost.

## Screenshots

- `docs/screenshots/issue-1812-cfieldsedit-01-create.png` — create mode, clean form.
- `docs/screenshots/issue-1812-cfieldsedit-02-edit-used-locked.png` — used field, Type/Available-on
  locked with warning banner.
- `docs/screenshots/issue-1812-cfieldsedit-03-delete-confirm.png` — delete confirmation modal.

## Commits

`b52126cab` (BFF) → `7e5173eb9` (screen + i18n ×10 + shim + wiring + screenshots) →
`006c72e1a` (suite 1812) → `67479fb62` (CHANGELOG) → `a2620abfa` (ledger) →
`374d4775b` (docs mirror) → `9f53b2240` (code review M1/M2/m1/m2/m3/m4, EOS).