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
