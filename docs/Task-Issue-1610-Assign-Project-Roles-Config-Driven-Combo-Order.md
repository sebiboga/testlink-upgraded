# Task — Issue #1610: config-driven Test Project combo order in Assign Test Project Roles

**Status:** implemented and verified (closes #1610)
**Screen:** `gui/templates/usermanagement/usersAssignProject.html` — *Assign Test Project Roles*
**BFF:** `api/roles/index.php` — `GET /api/roles/index.php/meta/tproject-roles` (+ `meta/tplan-roles`)
**New shared include:** `api/_tprojectorder.php`
**Commits:** `7f47109f8` (helper) + `6629967d3` (wiring), branch `task/issue-1610`

## The gap

Every Test Project combo in TestLink 1.9.20 was built from **one config line**,
`$tlCfg->gui->tprojects_combo_order_by`, so an installer could re-order the whole
application (e.g. by project **prefix**) in a single place:

| Consumer | Legacy site |
|---|---|
| navBar project combo | `lib/general/navBar.php:106` |
| `initArgs()` / prjSet | `lib/functions/common.php:1681` |
| user forms | `lib/functions/users.inc.php:48` |
| requirements | `lib/requirements/reqView.php:276` |
| **Assign Test Project Roles** | **`lib/usermanagement/usersAssign.php:276-278`** |
| navBar BFF | `api/navbar/index.php:96` |
| requirements BFF | `api/requirements/index.php:384` |

`config.inc.php:789` ships `'ORDER BY TPROJ.prefix ASC'`; line 788 documents
`'ORDER_BY nodes_hierarchy.id DESC'` as the alternative (note: that documented
example has an `ORDER_BY` typo — see *Validation* below).

The 2.0.1 roles BFF instead **hardcoded** `'ORDER BY name ASC'` in two places
(`getAssignableProjects()` and the `meta/tplan-roles` read), so on an
installation whose prefixes are categorised (`P1-`, `P2-`, …) this one screen
silently re-sorted alphabetically while every other combo stayed prefix-ordered.

Legacy renders the option label as `{$f.name|escape}` **only**
(`gui/templates/dashio/usermanagement/usersAssign.tpl:177`) even though the legacy
row carried the prefix — so the port is *ordering only*, deliberately.

## Measured before the change

Fixture `tmp/fixtures_1610.sql` — three projects whose prefix order is the reverse
of their name order: `101 Alpha Project / ZZ-ALPHA`, `102 Bravo Private Project /
MM-BRAVO`, `103 Charlie Project / AA-CHARLIE`.

```
# GET /api/roles/meta/tproject-roles?tproject_id=101  (authenticated)
projects: [{101,"Alpha Project"},{102,"Bravo Private Project"},{103,"Charlie Project"}]
# #projectSelect in the rendered screen
["|-- select project --","101|Alpha Project","102|Bravo Private Project","103|Charlie Project"]
```

`projects` is a JSON **list**, so no client-side re-ordering can mask it — the
BFF order *is* the rendered order. Isolated proof the config is otherwise
honoured by the same manager call (`php tmp/probe_1610.php`):

```
cfg order_by: [ORDER BY TPROJ.prefix ASC]
config    : 103, 102, 101      <- prefix order (what legacy produced)
byname    : 101, 102, 103      <- what the screen showed
```

## The fix

### New — `api/_tprojectorder.php`

A shared include (same pattern as `api/_guard.php`) with two functions:

| Function | Contract |
|---|---|
| `tprojectsComboOrderBy($override = false)` | returns `config_get('gui')->tprojects_combo_order_by` **verbatim** when it is a safe `ORDER BY` clause, else the pre-#1610 `'ORDER BY name ASC'`. `$override` forces a value instead of reading config, which is what makes the validator unit-testable. |
| `tprojectsAccessibleOrdered(&$tprojectMgr, $userId, $output, $extraOpt = [])` | runs `get_accessible_for_user()` with that clause; retries **once** with the safe sort if the query throws (the BFF/XHR path makes `exec_query` throw instead of printing a backtrace, `database.class.php:204-209`) or matches nothing. `$extraOpt` is merged **first**, so no caller can smuggle an unvalidated `order_by` past the validator. |

It lives in its own file because `api/roles/index.php` enforces the session and
dispatches routes on include — a harness could otherwise never load the function
it needs to test, and would end up verifying a copy that can silently drift.

### Validation (why a BFF needs one and legacy did not)

`testproject.class.php:631` pastes the value verbatim into SQL
(`$sql .= str_replace('nodes_hierarchy','NHTPROJ',$opt['order_by'])`). Legacy
trusted a hand-edited config file; a BFF must not. Accepted grammar:

```
ORDER BY <col>|<alias.col> [ASC|DESC] [, …]
```

* Qualifiers limited to the aliases the query really defines — `TPROJ`, `NHTPROJ`,
  `U`, `UTR` — plus `nodes_hierarchy`, which the manager itself rewrites and which
  `config.inc.php:788` documents.
* Rejected → safe fallback: empty/blank, `ORDER_BY …` (**including the
  `config.inc.php:788` example verbatim, which is a documented typo**),
  `;`-separated statements, subqueries, `--` comments, `SELECT …`, unknown alias,
  more than two qualifiers.
* `ORDER BY TPROJ` (a table name) is a *bare identifier*, syntactically
  indistinguishable from a bare column — the validator cannot reject it, so the
  exception fallback in `tprojectsAccessibleOrdered()` catches it. Recorded, not
  papered over.

### Wiring — `api/roles/index.php`

| Change | Where | Purpose |
|---|---|---|
| `require_once(__DIR__.'/../_tprojectorder.php')` | next to the `_guard.php` include | load the helper |
| `tprojectsAccessibleOrdered($tprojectMgr, $userId, 'map_of_map_full')` | `getAssignableProjects()` | the literal the issue reported |
| `tprojectsAccessibleOrdered($tprojectMgr, $userId, 'map_of_map')` | `meta/tplan-roles` read | the second hardcoded literal |
| `'tprojectsComboOrderBy' => tprojectsComboOrderBy()` | `meta/tproject-roles` envelope | ship the effective clause, so "why is my combo ordered like this?" is answerable from one payload |
| comment corrections | `getAssignableProjects()`, envelope | the code no longer says "ordered by NAME" (issue #1613's rationale) and now records that the **label stays the bare name** (`usersAssign.tpl:177`) |

### Screen — no change needed, none made

`usersAssignProject.html:506-513` already appends `<option>`s in payload order
(`grep '\.sort(\|localeCompare'` on the screen = 0 hits), so fixing the BFF is
what corrects the combo. Adding the prefix to the label would have been a *new*
deviation from legacy.

### i18n

**No new key.** The port introduces no new user-facing string — option labels,
headings and notices are unchanged — so none of the 10 locale bundles was
modified (verified: the diff touches no `gui/templates/i18n/*.json`).

## Verification

Suite `tmp/TLU_Test_Cases.md` "Suite 1610" — **15/15 PASS**; harness
`tmp/verify_1610.php` — **24/24 internal checks**, exit 0.

| Case | Measured |
|---|---|
| live combo | `Charlie Project → Bravo Private Project → Alpha Project` (prefix `AA- < MM- < ZZ-`) |
| payload | `projects: [103,102,101]`, `tprojectsComboOrderBy: "ORDER BY TPROJ.prefix ASC"` |
| `meta/tplan-roles` | `status ok`, `projects: [103,102,101]` |
| regression | combo → `102` loads the grid (`admin` row) and the heading reads `Test Project Role (Bravo Private Project)` |
| `ORDER BY TPROJ.prefix DESC` | combo reverses |
| `ORDER BY nodes_hierarchy.id DESC` | accepted, rewritten to `NHTPROJ`, combo reverses |
| `ORDER BY prefix; DROP TABLE nodes_hierarchy` | fallback to `ORDER BY name ASC`, no error, full list |
| `ORDER BY TPROJ.no_such_column` / `ORDER BY TPROJ` | fallback, full list (no empty combo) |
| blank config | fallback, full list |
| `$extraOpt` smuggling attempt | ignored — validated clause still applied |
| hygiene | `php -l` clean ×2; no i18n bundle touched; console 0 error / 0 warning; `events` = 1 row (login audit only) |

The two negative-path checks (unknown column, bare table name) each log one
DATABASE ERROR row into `events` as the proof the fallback is real; those rows
were removed so the Event Viewer only reflects genuine screen usage.

## Out of scope

The legacy **plan** combo (`getTestPlanEffectiveRoles()`, `usersAssign.php:343-378`)
lists `get_all_testplans()` and applies **no** combo-order config, so
`usersAssignPlan.html` / `getAssignablePlans()` needed nothing here.

`config.inc.php` is the administrator-facing switch and is not exposed in the
modern Configuration Management screen; this task ports the *behaviour*, not a
new settings UI.

## Screenshot

Test Project combo in configured (prefix) order — `Charlie Project` (`AA-CHARLIE`),
`Bravo Private Project` (`MM-BRAVO`), `Alpha Project` (`ZZ-ALPHA`):

![combo in configured prefix order](https://github.com/sebiboga/testlink-upgraded/blob/main/docs/screenshots/issue-1610-tproject-combo-prefix-order.png)