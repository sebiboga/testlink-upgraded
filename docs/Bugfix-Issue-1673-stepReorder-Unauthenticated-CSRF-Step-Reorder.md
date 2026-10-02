# Bugfix #1673 — `lib/ajax/stepReorder.php`: unauthenticated, CSRF-able step re-ordering endpoint (1.9.20 legacy)

**Refs #1673** (found while modernizing Reorder Test Case Steps, Refs #1671) · status: DONE
File: `lib/ajax/stepReorder.php` · replacement write path: `api/tcstepsreorder/index.php`
Regression suite: `tmp/verify_1673.php` — **29/29 PASS** (pre-fix: 22 PASS / 7 FAIL)

## The defect

`lib/ajax/stepReorder.php` renumbered `tcsteps.step_number` for a test case version over
plain HTTP with **no authorization at all** — the author's own comment said
`// No authorization checks`. In 1.9.20 it was the server side of the TableDnD
drag-and-drop of `gui/templates/tl-classic/testcases/steps_horizontal.inc.tpl`,
a template that was never part of the shipped UI. Being an orphan, it had no URL,
no dialog, no right, and no reviewer.

Four hops, all in `db4d056ba`:

| hop | file:line | defect |
|---|---|---|
| 1 | `lib/ajax/stepReorder.php:16` | `testlinkInitPage($db)` → `checkSessionValid()` (`lib/functions/common.php:554-556`): a **session** was required, nothing more |
| 2 | `lib/ajax/stepReorder.php:20` | **no** `mgt_modify_tc`, **no** `mgt_view_tc`, no role check at all |
| 3 | `lib/ajax/stepReorder.php:49` | `init_args()` reads `$_REQUEST["stepSeq"]` → a plain **GET mutated state**, CSRF-able from an `<img src>`, no token |
| 4 | `lib/ajax/stepReorder.php:22-27` | step ids taken verbatim — never `intval()`-ed, never proven to belong to the addressed version, never checked against the owning project |

Hop 4 feeds `lib/functions/testcase.class.php:6065`:

```php
$sql = "/* $debugMsg */ UPDATE {$this->tables['tcsteps']} " .
       " SET step_number = {$value} WHERE id = {$step_id} ";
```

`$step_id` comes straight out of `$_REQUEST`, **unquoted and unescaped** — so this was
not only an authorization hole but a **SQL-injection sink**. The `SELECT
NH_STEPS.parent_id` at lines 32-35 is built and never used: dead code that made the
handler look as if it validated something.

## Measured reproduction (pre-fix)

A `<no rights>` account (`role_id = 3`, no `user_testproject_roles` row) plus a plain GET:

```
GET /lib/ajax/stepReorder.php?tproject_id=<A>&tcase_id=<tc>&tcversion_id=<ver>&stepSeq=<id2>%26<id1>%26<id3>
HTTP/1.1 200 OK
{"8":1,"7":2,"6":3}
```

```
mysql> SELECT id, step_number FROM tcsteps;      ->  6=3  7=2  8=1   (was 6=1 7=2 8=3)
mysql> SELECT log_level, description FROM events ORDER BY id DESC LIMIT 1;
1 | DATABASE | ... /* Class:testcase - Method: set_step_number */
              UPDATE tcsteps  SET step_number = 1 WHERE id = 2,1,3,4
```

The caller-supplied string lands in the SQL verbatim — that row is the proof of the
injection, and it also proves the write really executed rather than being refused.

## The fix — retire the endpoint, do not guard it

`lib/ajax/stepReorder.php` is now a shim (`1ee7c403c`, `Refs #1671`):

| request | answer |
|---|---|
| anonymous `GET` | `200` + `top.location.href='…/login.php?note=expired'` (session check) |
| authenticated `GET` / `HEAD` | `302` → `/gui/templates/testcases/tcStepReorder.html` (+ `tproject_id` / `tcversion_id` only) |
| `POST` / `PUT` / `PATCH` / `DELETE` | `405 {"status":"error","code":"method_not_allowed"}` + a `WARNING` row in the Event Viewer as the trail |

`stepSeq` is deliberately **not** replayed by the redirect: the mutation it described
was never authorized, and a `302` that silently performed it would be worse than
refusing it.

The replacement write path is `POST /api/tcstepsreorder/index.php?action=move|reorder|normalize`,
which is guarded by `bffSameOriginGuard()` (CSRF) + `bffEnforceSession()` (idle timeout)
+ `mgt_modify_tc` on the **owning** project, and proves every submitted id to be a
`testcase_step` of the addressed version (`tsroRequireOwnSteps()`,
`api/tcstepsreorder/index.php:448`).

## Why retirement instead of a fix

Nothing calls the endpoint. Re-adding guards would re-introduce the unvalidated
`stepSeq` parse and the raw SQL interpolation, and the modern screen already covers
the use case. Retiring removes all four hops at once.

## Blast radius

* **Callers:** 1 — the dead `steps_horizontal.inc.tpl`. No live reference in `gui/templates/`.
* **Data reachable pre-fix:** `tcsteps.step_number` of any `testcase_step` node in any project,
  including ids belonging to a different version (mixing them renumbers both, silently). No
  execution-history or version-immutability rule was consulted.
* **Shared sink:** `testcase::set_step_number()` is also used by the modern screen, but the modern
  caller proves ownership first, so it is not affected.

## Corrections to the original report

1. **The endpoint was NOT reachable anonymously.** The issue title says "unauthenticated"; the
   measured pre-fix answer for an anonymous GET is the session-expired bounce. The defect is a
   missing **authorization** check, not a missing session check.
2. **There is no `tLog(... DEBUG_MODE ...)` tail.** Line 38 is
   `file_put_contents('/var/testlink/logs/stepReorder.log', …)` — a hardcoded absolute path that a
   standard install does not have. The file is still worse than the report described, just
   differently.
3. **The replacement BFF answers an opaque `404`, not `403`, to a `<no rights>` caller.** That is
   deliberate, hardened in #1762: the `tcversion_id` is caller-supplied, so a `403` would confirm
   the id exists (`api/tcstepsreorder/index.php:551-583`). Suite #1761 covers that side (95/95 PASS);
   suite #1673 covers the retired endpoint.

## Regression suite — `tmp/verify_1673.php` (29 cases)

Verb matrix · anonymous · `<no rights>` · SQL-injection probes on both parameters ·
an `M1` case that asserts `tcsteps.step_number` **in the database** after the hostile
requests (an HTTP assertion cannot prove this: pre-fix the endpoint answers with a
plausible JSON map) · the replacement BFF's 401/403/opaque-404 contract · Event Viewer
cleanliness. The reversing request is issued **last** so a working attack cannot be
masked by a trailing benign write.

Negative control (revert `lib/ajax/stepReorder.php` to `db4d056ba`, run, restore):
`22 passed, 7 failed`, with the decisive line

```
FAIL  M1 tcsteps.step_number unchanged   before=[6=1,7=2,8=3] after=[6=3,7=2,8=1]
```

### Known, harmless ordering consequence (recorded, not changed)

`checkSessionValid()` runs *before* the `405` gate, so an **anonymous** `POST` receives the
legacy `200` + login bounce rather than the shim's JSON `405`. The JSON contract is only
observable for an authenticated caller. Nothing is written either way — no CSRF, no authz
exposure — and reordering would change the legacy bounce that every other retired shim relies on.
