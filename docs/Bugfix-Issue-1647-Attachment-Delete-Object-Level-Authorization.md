# Bugfix — Issue #1647: no object-level authorization on attachment delete

**Status:** fixed and verified (branch `fix/issue-1647`, commit `1236efa45`)
**Component:** BFF attachment endpoints — `api/attachmentsdelete/index.php`, `api/attachments/index.php`
**Class:** broken access control / missing authorization (destructive action)

## Symptom

Any authenticated user could delete any attachment of any object — including a
user holding the built-in `<no rights>` role with an empty global right set.

```
POST /api/attachmentsdelete/index.php?action=delete&id=<A>&table=<fk_table>&fk_id=<fk_id>
X-Requested-With: XMLHttpRequest
Cookie: PHPSESSID=<any logged-in session>
-> 200 {"status":"ok","deleted_id":<A>}
```

`(table, fk_id)` come for free from `GET /api/attachments/index.php?action=list`,
which is itself ungated, and attachment ids are small sequential integers — no
guessing oracle is needed beyond reading the list of an object the user can see.

`GET …?action=init` leaked the same metadata (title, size, owner label) to the
same account.

## Root cause

Ownership was proven; authorization was never asked.

| hop | file:line | fact |
|---|---|---|
| 1 | `api/attachmentsdelete/index.php:84` | `$currentUser = tlUser::getByID($db, $userId)` — fetched, never used in any decision |
| 2 | `api/attachmentsdelete/index.php:95-98` | the only "rights" check is `config_get('attachments')->enabled`, an install-wide flag |
| 3 | `api/attachmentsdelete/index.php:104-115` | `bffAdInSessionAllowList()` — `$_SESSION['s_lastAttachmentInfos']` is the "have I just listed it" cache, not a grant |
| 4 | `api/attachmentsdelete/index.php:261-264` | the explicit `(table, fk_id)` branch compares **caller supplied** values against the row — a match, not a permission |
| 5 | `api/attachmentsdelete/index.php:323` | `deleteAttachment()` is then called unconditionally |
| 6 | `api/attachments/index.php:385-393` | the sibling `?action=delete` stops at the same proof and deletes with no rights decision |

The port of the legacy page kept the legacy gate verbatim (global config flag +
`checkAttachmentID()` allow-list) and layered the `(table, fk_id)` match on top.
All three are consistency checks. Legacy had the same hole *for attachments* but
not for its siblings — e.g. `lib/testcases/containerEdit.php:123` requires
`testcase_mgmt` — so the net effect of the port was a weakening of a
destructive path.

## The fix

New shared gate **`api/_attachauth.php`**, required by both endpoints:

1. `attAuthTbl()` — prefixed physical table name. Deliberately **not**
   `tlObject::getDBTables()`: that method **throws** for any name outside its own
   list (`lib/functions/object.class.php:328`) and the list has no
   `latest_req_version` / `node_types`. One unguarded call produced a `500` with
   an empty body (captured in `tmp/php_server.log`).
2. `attAuthNodeProjectId()` — walk `nodes_hierarchy.parent_id` to the
   `testproject` root (same algorithm as `arOwnerProjectId()` of
   `api/requirements/index.php:2249`).
3. `attAuthOwnerProjectId($db, $table, $fkId)` — owner → test project for every
   `fk_table` the endpoints accept: `nodes_hierarchy` (walk), `testprojects`,
   `testplans`, `builds`, `executions` (→ test plan), `execution_tcsteps`,
   `req_specs` / `requirement_specs`, `requirements` (→ `srs_id`), `req_versions`
   (→ `latest_req_version.req_id`), `keywords`, `cfields` / `custom_fields`
   (→ `cfield_testprojects`), `tcversions` (→ `testplan_tcversions`). Returns `0`
   when not derivable. A requirement **version** is resolved through the plain
   parent walk, not through `latest_req_version` — that is a `GROUP BY max()`
   view, so it only knows the newest version id of each requirement, while
   `reqView`'s version selector can attach to older ones.
4. `attAuthOwnerRights()` + `attAuthOwnerAllowed()` — map the owner to this
   fork's right names and require the caller to hold one through
   `tlUser::hasRight()`. For `nodes_hierarchy` the **node type** decides, because
   that table holds every container kind (1 testproject, 2 testsuite, 3 testcase,
   5 testplan, 6 requirement_spec, 12 build …).

The gate is placed **before** the ownership proof (so `ATTACHMENT_NOT_ALLOWED`
can no longer tell a caller which object owns a guessed attachment id — same
ordering as the `tcSummary` existence-oracle fix, `2c766babe`) and always uses
the values read **from the row**, never the caller supplied ones. `api/attachments/index.php`
gets the same check; `gui/templates/attachments/attachmentDelete.html` maps the
new `NO_RIGHT` code onto the existing translated "Not allowed" state
(`adel.stNotAllowed` / `adel.errNotAllowed`) — no new i18n keys, no translation gap.

### Fail closed

* project derivable → the right is judged on that project (global + project role
  rights merged, private-project rule enforced since #1763);
* project **not** derivable → **denied**, except for the two owners that are
  project-less by design (node type 13 platform, 14 user), which are judged on
  the global right set. This is not cosmetic: `tlUser::hasRight($db, $right, 0)`
  skips the private-project guard (`if ($testprojectID > 0)`,
  `tlUser.class.php:886`), so a global fallback would hand a globally privileged
  account (built-in roles 4/6/7/9 all hold `mgt_view_req` / `mgt_view_tc`)
  attachments of a **private** project it has no role on — a live bypass of the
  #1763 rule, caught in code review;
* **unknown** `fk_table` → empty right list → denied.

An unknown owner therefore never means "allowed", and an admin (global role 8,
every right) keeps working on any owner.

### Alternatives rejected

* **The right names from the issue's snippet** (`testcase_view`, `testplan_view`,
  `mgt_view_build`) do not exist in this schema. Transcribed literally,
  `hasRight()` is falsy for *every* user including admin — the feature would be
  bricked.
* **The snippet's `$tprojectOf > 0 && …`** is fail-open: an unresolvable owner
  would skip the check and delete anyway.
* **A manage right** (`testcase_mgmt`, `req_tcase_link_management`) instead of a
  visibility right is stronger, but every screen that offers the popup is already
  gated on the manage right, so it adds no reachable protection while risking the
  refusal of legitimate flows.
* **Gating `?action=list` / `?action=download`** would close the id oracle too, but
  those are read paths shared with the public share-link feature (#1541) — a
  separate change with a bigger blast radius.

## Verification

Measured against the live app (`http://localhost:8082`, cookie-jar login,
`X-Requested-With: XMLHttpRequest`), fixture: test project 10, test case node 12,
accounts `lowpriv` (`role_id=3` = `<no rights>`, **0** `role_rights` rows) and
`designer` (`role_id=4`, global `mgt_view_tc`):

| case | result |
|---|---|
| `lowpriv` delete, owner `testprojects`/10 | 403 `NO_RIGHT`, row survives (pre-fix: `200` + deleted) |
| `lowpriv` init (metadata oracle) | 403 `NO_RIGHT` (pre-fix: `200` + `owner_label`) |
| `lowpriv` → `api/attachments?action=delete`, owner `testprojects`/10 | 403 `NO_RIGHT`, row survives (pre-fix: `200` + deleted) |
| `lowpriv` delete, owner `nodes_hierarchy`/12 (test case) | 403 `NO_RIGHT`, row survives |
| **admin** delete, both owner kinds | `200` + `deleted_id`, row gone — no regression |
| `designer` on a **public** project without a project role | `200` — legacy visibility preserved, no over-blocking |
| `designer` on a **private** project without a project role | 403 `NO_RIGHT` — private-project rule (#1763) |
| Event Viewer / `events` table | only `log_level 16` (audit INFO); `log_level in (12,14,15,17)` → 0 rows |

10/10 cases PASS — full suite in `tmp/TLU_Test_Cases.md`, suite
"Regression — Issue #1647".

## Files

| file | purpose |
|---|---|
| `api/_attachauth.php` | **new** — owner → test project derivation + right gate, shared by both endpoints |
| `api/attachmentsdelete/index.php` | gate inside the shared `bffAdLoad()` (covers `init` **and** `delete`) |
| `api/attachments/index.php` | same gate on `?action=delete` |
| `gui/templates/attachments/attachmentDelete.html` | render `NO_RIGHT` as the existing translated "Not allowed" state |

## Known limitation

`?action=list`, `?action=upload` and `?action=download` remain ungated. They
were **measured** during this run (a no-rights account got `200` on all three and
the upload created a row) and are tracked in **#1768**. `download` is the legacy
public share-link route of #1541 and deliberately binds the 64-char object key
to the owning entity, so closing it needs its own blast-radius analysis. With
`delete` gated, no attachment can be destroyed without a right.
