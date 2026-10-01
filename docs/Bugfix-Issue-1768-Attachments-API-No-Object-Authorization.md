# Bugfix-Issue-1768-Attachments-API-No-Object-Authorization (#1768)

## Symptom

`api/attachments/index.php` — the BFF behind the modern **Attachments Upload/Download**
screen — gated `list`, `upload` and `download` on nothing but a session.
`checkFk()` (`api/attachments/index.php:256-274`) only proves that `table` is one of the
whitelisted tables TestLink stores attachments for and that `id > 0`: an **input check,
not a permission**. There was not a single `hasRight()` call in the file.

Measured live on `http://localhost:8082` as `lowpriv` (`users.role_id = 3` = `<no rights>`,
**0** rows in `role_rights`, **no** `user_testproject_roles` row) against a **private** test
project it has no role on:

| request | answer |
|---|---|
| `GET ?action=list&table=testprojects&id=12` | `200` + full attachment metadata (title, file name, size, date, download URL) |
| `POST ?action=upload` `table=testprojects&id=12` + file | `200 {"uploaded":1}` — a row was **CREATED**, and audit-logged as a legitimate `audit_attachment_created` |
| `GET ?action=download&id=<attachment>` | `200` + the file bytes |
| `GET ?action=download&id=<attachment>&apikey=<lowpriv script_key>` | `200` + the file bytes (the 32-char key is a **user** key, `api/attachments/index.php:45`) |

So any authenticated account — even one with no rights at all — could enumerate, read and
**write** files on every object of the installation.

## Root cause

Modernization removed the legacy page gate. `lib/attachments/attachmentupload.php` /
`attachmentdownload.php` only re-checked `config_get('attachments')->enabled`, because the
**page** that opened them (`containerEdit.php`, `execSetResults.php`, `reqView.php`,
`planEdit.php`) had already decided whether the caller could see the object. The modern
screens call the BFF **directly**, so with the page gone the object gate went with it —
the same regression family as #1740 (`lib/ajax/dragdroptreenodes.php`).

Correction to the issue body: the helper `api/_attachauth.php::attAuthOwnerAllowed()` did
**not** already exist — #1647 is still open, and `delete` is therefore still ungated too
(measured in the same session: `POST action=delete …file_id=9010` → `200 deleted_id=9010`).
That part stays tracked by #1647.

## The fix

### `api/_attachauth.php` (new, shared)

`attAuthResolveContext($db, $fkTable, $fkId)` resolves the object that **owns** the
attachment set — never the session context, so a forged `table`/`id` pair can never be
judged against a project the caller happens to have open:

| `fk_table` | resolution |
|---|---|
| `testprojects` | the project itself (row must exist) |
| `testplans` | `testplans.testproject_id` (+ plan id) |
| `builds` | `builds.testproject_id` |
| `executions` | `executions.testplan_id` → `testplans.testproject_id` |
| `execution_tcsteps` | `execution_tcsteps.execution_id` → `executions.testplan_id` (attachments of the **previous run** of a step, `lib/functions/exec.inc.php:226`) |
| `nodes_hierarchy`, `testsuites`, `testcases` | walk up `nodes_hierarchy` to the `testproject` node; requirement nodes (6/7/8) route to the requirement right set |
| `tcversions`, `tcsteps` | 2.0.1 node tree: the version/step **node id is the row id**, then walk up |
| `req_specs`, `requirement_specs` | `req_specs.testproject_id` |
| `requirements` | `requirements.srs_id` → `req_specs.testproject_id` |
| `req_versions` | node (`requirement_version`) → requirement → req spec → project |

Node-type ids come from the `node_types` table with the ids shipped in
`install/sql/mysql/testlink_create_default_data.sql:12-20` as fallback (pattern of
`api/suitemove/index.php:83-100`).

`attAuthOwnerAllowed($db, $user, $ctx, $forWrite)` requires the right the legacy screen
showing those attachments already required:

| owner domain | read (`list`, `download`) | write (`upload`) |
|---|---|---|
| execution | `testplan_execute`, `exec_ro_access`, `exec_edit_notes` | `testplan_execute`, `exec_edit_notes` |
| plan / build | `testplan_execute`, `testplan_planning`, `testplan_create_build`, `testplan_metrics` | `testplan_planning`, `testplan_create_build` |
| test project | `mgt_view_tc`, `mgt_modify_tc`, `mgt_view_req`, `mgt_modify_req`, `mgt_modify_product`, `testproject_metrics_dashboard` | the three `mgt_modify_*` |
| design-time (suite / tc / version / step) | `mgt_view_tc`, `mgt_modify_tc` | `mgt_modify_tc` |
| requirement | `mgt_view_req`, `mgt_modify_req`, `req_tcase_link_management`, `monitor_requirement` | `mgt_modify_req` |

`tlUser::hasRight()` is reused unchanged, so its private-project rule (#1763) and the legacy
admin exception apply. **Fail closed**: an unknown table, a deleted object or a broken
hierarchy is denied, never allowed.

### `api/attachments/index.php`

- `list` — gate between `checkFk()` and the `SELECT`.
- `upload` — gate with `$forWrite = true` **before** `insertAttachment()`.
- `download` — gate in the `else` of the existing `$isAnonFromKey` branch, on the
  attachment's own `fk_table`/`fk_id`.
- `delete` — **untouched** (#1647).

## Alternatives rejected

- **Gate on the session test project** — a forged `table`/`id` pair would be judged against
  whatever project the session holds, the exact confusion that made `api/suitemove` hand a
  private project to a user without a role (#1763).
- **Treat `checkFk()`'s whitelist as the permission** — it says *where* attachments may live,
  never *who* may read them.
- **A new `(role, fk_table)` table** — the right that guards an attachment is the right that
  guards its object; deriving it keeps the modern screens' own gates and the attachment gate
  in agreement by construction.

## Public share links (#1541) keep working

The 64-char **object** key path is untouched — it is still bound to the owning entity by
`bffAttachBindObjectKey()`. Measured before vs after (same 8 requests, original file restored
from git for the "pre" column):

| request | pre-fix | post-fix |
|---|---|---|
| 64-char object key of the owning plan / project | 200 bytes | 200 bytes |
| 64-char object key of another project / bogus key | 403 | 403 |
| 32-char user key `admin` | 200 bytes | 200 bytes |
| 32-char user key `lowpriv` | **200 bytes** | **403 no rights** ← the hole |
| no key | 401 | 401 |

## Verification

`tmp/verify_1768.sh` — **59/59 PASS** (fixtures `tmp/reset_1768.sql`,
`tmp/fixtures_1768.sql`, `tmp/fixtures_1768b_execstep.sql`, probe `tmp/repro_1768.sh`):
9 `fk_table`s refused for the no-rights user (list/download/upload), admin unaffected on all
9, `execution_tcsteps` resolved, read-vs-write split with a custom `exec_ro_access`-only role,
non-admin role parity, 8 share-link/key cases, input validation and the CSRF guard unchanged,
`php -l` clean on both files, Event Viewer 0 new `log_level IN (1,2)` rows.
Suite recorded in `tmp/TLU_Test_Cases.md` ("Regression — Issue #1768").

## Found while testing (filed, not fixed here)

**#1769** — `tlUser::hasRight()` raises `E_WARNING Undefined array key "tplan"`
(`lib/functions/tlUser.class.php:962`) and then returns `false` for every caller that passes
a plan id **without** the 5th `$getAccess` argument — including `admin`. Measured during the
code review of this fix; the attachment gate therefore passes `$getAccess = true`, exactly
like `api/execute/index.php:379` and `api/execsetresults/index.php:102`.

## Files

| file | purpose |
|---|---|
| `api/_attachauth.php` | new shared owner resolution + rights gate (8 helpers) |
| `api/attachments/index.php` | wires the gate into `list`, `upload`, `download` |
| `CHANGELOG` | one-line summary under 2.0.1 → KEY BUGFIX - SECURITY |
| `tmp/TLU_Test_Cases.md` | regression suite (not committed; `tmp/` is gitignored) |