# Modernize: project-scoped Requirement Specification launcher (`projectReqSpecMgmt`)

- **Tracking issue:** [#1833](https://github.com/sebiboga/testlink-upgraded/issues/1833)
- **Legacy:** `lib/project/project_req_spec_mgmt.php` (46 lines) +
  `gui/templates/dashio/requirements/project_req_spec_mgmt.tpl`
- **Modern screen:** `gui/templates/requirements/projectReqSpecMgmt.html`
- **BFF:** `api/projectreqspecmgmt/index.php`
- **Related bug filed:** [#1834](https://github.com/sebiboga/testlink-upgraded/issues/1834) —
  pre-existing `requirement_mgr` `foreach()`-on-null E_WARNING

## How this screen was chosen

The `TODO` section of `docs/MODERNIZATION-STATUS.md` is **empty** (0 rows), so a full legacy
sweep was run instead.

**The first sweep was wrong and was reverted.** It looked for a modern twin at
`gui/templates/<area>/<basename>.html`. `lib/plan/planUrgency.php` has no such file — but it
is *already* modernized as `gui/templates/plans/testUrgency.html` + `api/plans`
(Refs **#605**, recorded DONE in the ledger) and is reached from the plan navigator as the
`urgency` action. A duplicate BFF, screen and 55 i18n keys were pushed under #1832 and then
reverted (`d59145776`, `490b37f14`).

**The corrected sweep** treats a legacy controller as done if *any* modern
`gui/templates/**/*.html` or `api/**/index.php` references `<name>.php`. Exactly five
controllers are unreferenced:

| Legacy controller | Verdict |
|---|---|
| `lib/events/eventviewer.php` | orphan early PHP+HTML duplicate of the modernized Event Viewer (#1556) |
| `lib/project/projectView.php` | already ported by `api/projects/index.php` |
| `lib/testcases/tcSearchForm.php` | **dead code** — `echo __FILE__; die();` before `$smarty->display()` |
| `lib/usermanagement/userInfo.php` | already `usermanagement/userInfo.html` |
| **`lib/project/project_req_spec_mgmt.php`** | **the one real one** |

## What the legacy screen did

A pure **gated launcher**. A run-on title
(`Test project › <name> › Requirement Specification`) plus four buttons that only navigate
somewhere else:

| Legacy button | Gate | Legacy target |
|---|---|---|
| New requirement specification | `mgt_modify_req` | `reqSpecEdit.php?doAction=create&tproject_id=` |
| Reorder requirement specifications | `mgt_modify_req` | `reqSpecEdit.php?doAction=reorder&tproject_id=` |
| Import | `mgt_modify_req` | `reqImport.php?scope=tree&tproject_id=` |
| Export all | always | `reqExport.php?scope=tree&tproject_id=` |

Page access: `pageAccessCheck` with `rightsOr = [mgt_view_req, mgt_modify_req]`.

This is the **project-scoped** entry point. The requirement-**module**-scoped sibling
(`lib/requirements/reqSpecMgmt.php`) is a different screen and was already modernized as
`requirements/reqSpecMgmt.html` — which is why 2.0.1 had no equivalent of this one.

Note `reqSpecEdit.html` / `reqSpecReorder.html` do not exist: in 2.0.1 **New** and **Reorder**
both live as modals inside `requirements/reqSpecMgmt.html`. Import/Export are
`reqImport.html` / `reqExport.html`.

![projectReqSpecMgmt](../screenshots/issue-1833-projectReqSpecMgmt.png)

## Rights — ported 1:1

`mgt_view_req` **OR** `mgt_modify_req` on the addressed project. Verified by flipping the
admin role's `role_rights` rows:

| Role rights | HTTP | Actions returned |
|---|---|---|
| both | 200 | New, Reorder, Import, Export |
| `mgt_view_req` only | 200 | **Export only** (3 cards locked) |
| neither | **403** `no_right` | — |

A forbidden card renders **locked** rather than vanishing, so the page does not change shape
with the role — while staying unclickable, exactly like the button the legacy template simply
did not render.

## What it adds

The legacy screen had nothing to show beyond the buttons:

- **Counter tiles** — specification and requirement counts scoped to the project.
- **Requirements-disabled warning** pointing at the project configuration.
- **Refresh**, and a readable context sub-line instead of the run-on title.

## BFF

`GET /api/projectreqspecmgmt/index.php?action=init[&tproject_id=N]`

Session auth (cheap session gate **before** the DB connect, so an anonymous call cannot leak
a `dbms_msg`), `bffEnforceSession`, then rights. Stable machine codes:
`400 bad_param` · `401 session_expired` · `403 no_right` · `404 tproject_not_found` ·
`405` · `500`.

Hardening over legacy:

- the addressed project is **proven to exist** (404 instead of an empty launcher);
- the four action URLs are built **server-side** from validated ids, so the page cannot be
  talked into linking to a foreign project;
- access denials are **audited**.

### The counters

`nodes_hierarchy` has no project column and specification specs nest arbitrarily deep, so a
single `GROUP BY node_type_id` cannot scope to a project. The BFF reads the hierarchy **once**
and walks **down** from the project root in PHP with a `$seen` loop guard — one query per node
would be an N+1 over every requirement in the installation. A `requirement_version` node is
never miscounted as a requirement.

## 2.0.1 schema traps hit along the way

| Trap | Consequence | Handling |
|---|---|---|
| `testprojects.option_reqs` is a **dead column** in 2.0.1 | first cut reported `requirements_enabled: false` for a project that demonstrably has requirements | live flags are the serialized `testprojects.options` blob via `testproject::getOptions()` |
| `testproject::create()` does **not** apply `$item->options` | the fixture's project silently stayed requirements-disabled | call `setOptions()` after (same order as `lib/project/projectEdit.php`) |
| the project name lives in `nodes_hierarchy` | `testprojects` has no `name` column | join through `nodes_hierarchy` |
| `logger.class.php` has no `SECURITY` level | `Undefined array key "SECURITY"` + a malformed event on **every** denial | audit as `AUDIT`, like every other BFF |
| `??` binds looser than `!==` | `if ($_SERVER['REQUEST_METHOD'] ?? 'GET' !== 'GET')` parses as `$_SERVER[...] ?? false`, so *every* request 405'd | parenthesised |

## Bugs found while testing

1. **Un-namespaced action labels.** The BFF returned bare label ids (`btnNewReqSpec`,
   `btnImport`, …) that match **no** bundle, so `TLi18n.t()` would have echoed the raw id into
   every action button. Caught by cross-checking the BFF's action labels against all 10
   bundles. Fixed — the BFF now emits `prsm.*`.
2. **`logLevel = 'SECURITY'`.** See the table above. Fixed — `AUDIT`.

Filed, **not** fixed (pre-existing legacy defect, out of scope for this screen):

- **#1834** — `requirement_mgr.class.php:643` and `:664` raise
  `foreach() argument must be of type array|object, null given` whenever a requirement has no
  versions, i.e. the normal state of a freshly built requirement tree. Both are one-line
  `(array)` guards.

## Wiring

- `$actions->projectReqSpecMgmt` in `lib/functions/common.php`, next to the existing
  (module-scoped) `$actions->reqSpecMgmt` so the two entry points sit side by side.
- `lib/project/project_req_spec_mgmt.php` reduced to the session-guarded 302 shim pattern of
  `eventinfo.php` (#1556); it honours an explicit `tproject_id` and otherwise the session
  project.
- 29 `prsm.*` keys + `footers.projectReqSpecMgmt` in **all 10** `gui/templates/i18n/*.json`
  bundles, `python3 -m json.tool` validated.

## Verification

Suite `Issue #1833` — **15/15 PASS** in `tmp/TLU_Test_Cases.md`.

```bash
php tmp/fixtures_1833.php
# http://localhost:8082/gui/templates/requirements/projectReqSpecMgmt.html?tproject_id=90507
```

Covered: anonymous 401, counters 2/3 (nested spec + deep requirement), all four targets
HTTP 200, read-only → 3 locked + Export, no rights → 403 with **no stale tiles left in the
DOM**, unknown project → 404, session fallback, Refresh, EN/RO/DE/JA locale switch, zero
console messages. The Event Viewer has **0 new Error/Warning** rows after the AUDIT fix.

**Mandatory code review (subagent): no BLOCKER, no security issue.** Its two MAJOR findings
(an unused `projectRootOf()` helper and an unused `$ntRev`) were dead code from an earlier
draft and are removed.
