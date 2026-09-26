# Bug fix — Issue #1592: `reqMgrSystemView.php` guarded one superglobal and read the other

## Symptom

Every visit to the legacy **Req. Management System** list screen that carried a
`?tproject_id=` query string wrote one `E_WARNING` row into the `events` table
(the Event Viewer's source):

```
E_WARNING Undefined array key "tproject_id" - in
/home/runner/work/testlink-upgraded/testlink-upgraded/lib/reqmgrsystems/reqMgrSystemView.php - Line 58
```

The screen itself is **not** broken by this defect — the list renders, the rows are correct, and
`HTTP 200` comes back. This is pure Event Viewer noise, which matters because the Event Viewer is
the project's primary signal for real defects: a warning that is always present trains everyone to
ignore the channel.

> **Scope caveat, measured during verification:** the 200 above holds for the plain list load. The
> *Check Connection* path (`?id=<n>`) is separately broken and returns `HTTP 500` with a 0-byte body
> — a **pre-existing, independent** defect (the `contoursoapInterface` class is absent from the repo),
> filed as **#1625**. It is not touched by this fix; see Verification case 12 and Gotcha 7.

Entry point:
`http://localhost:8082/lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1`.

Two corrections to the original report, both established by measurement:

- It is **not** "on every load". The warning fires only when the URL carries a
  `tproject_id` parameter. With no query string the `isset()` is false, the branch
  yields `0`, and **zero** rows are written.
- The reported root cause was **wrong**. It guessed a missing `isset()` guard on a
  `$_REQUEST` read. The guard *was* present, and the read was from `$_SESSION`.

## Environment and fixtures

- TestLink 2.0.1, PHP 8.3, MariaDB on the local CI instance. Pre-fix baseline
  `ea1aa68e6`; the fix is `25d387f5c`.
- The database is freshly imported on every run, so `reqmgrsystems`, `testprojects`
  and `events` all start **empty**:

```sql
-- a public test project, so a login auto-selects one into $_SESSION['testprojectID']
-- (is_public=1 => setUserSession() picks key($arrProducts), lib/functions/users.inc.php:59-66)
INSERT INTO testprojects (id,prefix,notes,is_public,active)
VALUES (7,'TP-SEVEN','session-precedence fixture for #1592',1,1);

-- one ReqMgrSystem row. NOT required to reproduce the defect -- see Gotcha 1.
INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('Jira Demo',1,'{}');

-- non-manager account for the rights-gate case (password = md5('viewer1592')).
-- rights 33 reqmgrsystem_management + 34 reqmgrsystem_view are granted to role 8 (admin) ONLY.
INSERT INTO users (login,password,role_id,email,first,last,locale,active,cookie_string,auth_method)
VALUES ('viewer1592',MD5('viewer1592'),1,'v1592@x.y','View','Er1592','en_GB',1,
        'ck1592viewer000000000000000000a','local');
```

## Root cause

`lib/reqmgrsystems/reqMgrSystemView.php:58`, inside `init_args()`:

```php
$args->tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;  // :54

if( $args->tproject_id == 0 )                                                                    // :56
{
  $args->tproject_id = isset($_REQUEST['tproject_id']) ? intval($_SESSION['tproject_id']) : 0;   // :58  <-- BUG
}
```

The chain, hop by hop:

1. `:23` — `init_args()` is called, `:24` — `$mgr->getAll()` has not run yet, so no
   ReqMgrSystem row is involved.
2. `:54` — on a fresh DB, and with no project selected in the UI,
   `$_SESSION['testprojectID']` is **unset**, so `$args->tproject_id` becomes `0`.
3. `:56` — the guard passes and the branch is entered.
4. `:58` — the ternary tests `$_REQUEST['tproject_id']` (present, because the URL
   carries it) but reads `$_SESSION['tproject_id']`.

**The guard and the read name different superglobals, so the `isset()` can only ever
*enable* the undefined read, never protect it.** Two facts close the case:

- `$_SESSION['tproject_id']` is never assigned anywhere in the repository:

  ```
  $ grep -rn "SESSION\['tproject_id'\]" --include=*.php .
  ./lib/reqmgrsystems/reqMgrSystemView.php:58:  ...intval($_SESSION['tproject_id'])...
  1 site, and that site is the *read*. There is no writer.
  ```

- The real session key for the current test project is `$_SESSION['testprojectID']`,
  written at `lib/functions/common.php:459` and already read correctly four lines
  above at `:54`.

Provenance: a copy/paste slip from `:54`, which legitimately reads the SESSION key. The
author correctly guarded the REQUEST parameter (that is the convention the legacy
callers use), then reflexively copied the SESSION read from the line above instead of
`$_REQUEST['tproject_id']`.

## Blast radius

- **1 code site in the whole repository.** Nothing else reads the phantom key, so the
  fix cannot regress any other screen.
- `$args->tproject_id` is **write-only** within the file: after `init_args()` returns,
  the property is never read by the controller (`:28-35` use only `$args->id`,
  `$args->currentUser`, `$args->user_feedback`) nor by either selectable Smarty template —
  `gui/templates/dashio/reqmgrsystems/reqMgrSystemView.tpl` **and**
  `gui/templates/tl-classic/reqmgrsystems/reqMgrSystemView.tpl` (chosen by
  `templateConfiguration()`, `lib/functions/common.php:909-926`) have **zero** `tproject_id`
  occurrences. `$args` itself is a local that is never assigned to `$gui` nor passed to Smarty
  (`:34` assigns `$gui` only). The only observable contract to preserve is *"no warning"*.
- No other file, template or API endpoint is involved.
- The modernized screen `gui/templates/reqmgrsystems/reqMgrSystemView.html` — which is
  what `lib/functions/common.php:1939` points the ASIDE menu at — is a different code
  path and is **not** affected. The defect is confined to the legacy PHP entry point,
  which stays reachable by bookmark or direct URL.

## The fix

One line, one identifier: make the read name the superglobal the guard tests.

```diff
--- a/lib/reqmgrsystems/reqMgrSystemView.php
+++ b/lib/reqmgrsystems/reqMgrSystemView.php
@@ -55,7 +55,7 @@ function init_args()

   if( $args->tproject_id == 0 )
   {
-    $args->tproject_id = isset($_REQUEST['tproject_id']) ? intval($_SESSION['tproject_id']) : 0;
+    $args->tproject_id = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
   }
   $args->currentUser = $_SESSION['currentUser'];
```

Why this method: it is the minimal correct fix — no refactor, no new helper, no
signature change, no other file touched — and it is the only way the `isset()` can
actually do its job. It also preserves the *intended* semantics ("if the URL carries a
test project id, use it") and keeps the `intval()` sanitisation, so a non-numeric
`?tproject_id=abc` still yields `0` and no new injection surface appears.

### Alternatives rejected

1. **Add an `isset()` guard around the SESSION read.** Silences the warning but keeps
   reading a key that is never written — the function would always return `0` and the
   code would stay dead and misleading. Treats the symptom, not the cause.
2. **Delete the branch / the whole `$args->tproject_id` computation as dead code.**
   Tempting, since the property is never consumed, but it deletes behaviour rather
   than correcting it, widens the blast radius past the reported line, and would leave
   a future consumer with nothing. Out of scope for a minimal bugfix.
3. **`@`-suppress the line.** Hides the error; violates the project convention.
4. **Mirror the `testprojectID` fallback** by adding `isset($_SESSION['testprojectID'])`
   as a third source. Scope creep, and the value is unused.

## Verification

`events` truncated before each request so counts are attributable, not inherited.

| # | Case | Expected | Measured | Result |
|---|---|---|---|---|
| 1 | **Primary:** admin, `?tproject_id=1`, no project in session | 200, 0 warnings | `http=200 tproject_id_warnings=0 total_events=0` | PASS |
| 2 | Control, no query string | 200, 0 warnings | `http=200 tproject_id_warnings=0 total_events=0` | PASS |
| 3 | `?tproject_id=abc` | 200, 0 warnings, coerced to `0` | `http=200 tproject_id_warnings=0 total_events=0` | PASS |
| 4 | `?tproject_id=` | 200, 0 warnings | `http=200 tproject_id_warnings=0 total_events=0` | PASS |
| 5 | **Session precedence:** `TP-SEVEN` in session, different `?tproject_id=999` | session wins, branch never entered, no warning | `http=200`, 0 warnings | PASS |
| 6 | **Rights gate:** `viewer1592` (role 1) | refused by `checkRights()`, no data leaked | `http=200 bytes=204`, `Jira Demo` count 0, 0 warnings | PASS |
| 7 | Full-list render, live browser | row renders with type/environment columns | DOM: `"Req. Management System Type Environment delete Jira Demo contour (Interface: soap)"` | PASS |
| 8 | `php -l` | no syntax errors | `No syntax errors detected` | PASS |
| 9 | Sibling: modernized `reqMgrSystemView.html?tproject_id=1` | 200, unaffected | `http=200 tproj_warn=0` | PASS |
| 10 | Sibling: legacy `issueTrackerView.php` / `codeTrackerView.php` | 200, unaffected | `http=200 tproj_warn=0` (both) | PASS |
| 11 | Event Viewer after the whole matrix | no Error/Warning from this screen | 0 rows attributable to `reqMgrSystemView.php` | PASS |
| 12 | **`?id=` branch of the edited function** — `if($args->id > 0)` at `:28-31` lives inside the same function that was changed, so it must be exercised | the `tproject_id` fix must not affect it, whatever it does | `?id=1&tproject_id=1` → `http=500 bytes=0 tproj_warn=0`; `?id=1` → `500`; `?id=999` (nonexistent) → `500`. **Pre-existing, unrelated** — see Gotcha 7 / #1625 | PASS (fix neutral) |

**12/12 PASS.** Note on reading the table: `total_events=0` in cases 1-5 is a **fixture** artefact —
those cases ran against an *empty* `reqmgrsystems` table, where the page produces no events at all.
With a ReqMgrSystem row present every load also writes the two `contoursoapInterface` rows from
#1593, so the meaningful assertion in all cases is the `tproject_id_warnings` / `tproj_warn` column,
never `total_events`.

Before / after on the identical primary request:

```
BEFORE:  | 2 | 2 | E_WARNING Undefined array key "tproject_id" - .../reqMgrSystemView.php - Line 58 |
AFTER:   SELECT count(*) FROM events WHERE description LIKE '%tproject_id%';  ->  0
```

The full suite, with the `RESUME` one-liner, is in `tmp/TLU_Test_Cases.md`
(suite *"Regression — Issue #1592"*, 11 cases).

## Gotchas recorded for future repros

1. **A ReqMgrSystem row is NOT required to reproduce.** `init_args()` runs at `:23`,
   before `$mgr->getAll()` at `:24`, so the warning fires on a completely empty
   `reqmgrsystems` table. The original issue listed it as repro step 1 — it is a red
   herring.
2. **The trigger is narrower than reported** — only requests carrying
   `?tproject_id=` (see Symptom).
3. **The issue's root-cause hypothesis was wrong** — the guard was present, and the
   read was from `$_SESSION`.
4. **Case 5 needs the `is_public=1` fixture.** On a fresh DB `$_SESSION['testprojectID']`
   is unset, so the branch is *always* taken and the session-precedence path is
   unreachable.
5. **The `contoursoapInterface.class.php` warnings are issue #1593, not a regression.**
   They appear once a ReqMgrSystem row exists, because `getAll(['checkEnv' => true])` at
   `:24` pulls that class in. Different file, count unchanged by this fix. The two
   defects are cleanly separable: **#1592 fires on an empty table, #1593 on a populated
   one.**
6. **Sibling defect found during the sweep, filed as #1624 (not fixed here).**
   `lib/reqmgrsystems/reqMgrSystemEdit.php` returns **HTTP 500** when `doAction` is
   absent or unknown — `reqMgrSystemEdit.php:126` throws an uncaught `Exception`, and
   the whitelist from `reqMgrSystemCommands.class.php:38-39` has no key for the empty
   value. The legitimate entry points `?doAction=edit&id=1` and `?doAction=create`
   return 200 with 0 error rows. Independent of #1592.
7. **The `?id=` (Check Connection) path is a hard 500 — found by code review, filed as #1625.**
   Case 12 exercises `if($args->id > 0)` at `reqMgrSystemView.php:28-31`, which sits **inside the
   function that was edited**, so leaving it untested would have overstated the coverage claim. It
   returns `HTTP 500` with a 0-byte body for *any* `id` — including a nonexistent one — because
   `checkConnection()` (`:30`) reaches `tlReqMgrSystem.class.php:616-621`, which does
   `new contoursoapInterface(...)` and that class is **absent from the repository**:

   ```
   $ git cat-file -e ea1aa68e6:lib/reqmgrsystems/contoursoapInterface.class.php
   fatal: path 'lib/reqmgrsystems/contoursoapInterface.class.php' does not exist in 'ea1aa68e6'
   ```

   Proven pre-existing and unreachable from this fix: the file is missing at the baseline, and the
   `tproject_id` value assigned at `:58` is never consumed, so the fix cannot influence `:30`. Same
   **missing file** as #1593 but a more severe symptom (fatal 500 vs Event Viewer noise), which is
   why it got its own issue rather than being folded into #1593.

## Files changed

| File | Change |
|---|---|
| `lib/reqmgrsystems/reqMgrSystemView.php` | +1 / −1 — read `$_REQUEST['tproject_id']` instead of `$_SESSION['tproject_id']` at `:58` |

No template, no API endpoint, no i18n bundle, no rights logic and no locale string was
touched — the fix is invisible to users by design, it only removes a warning.

## RESUME

```bash
# one-liner: does the defect still exist on any branch?
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "delete from events;"
curl -s -b <cookie> "http://localhost:8082/lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1" -o /dev/null
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "select count(*) from events where description like '%tproject_id%';"   # 0 == fixed, 1 == regressed
```

Browser re-test: log in `admin`/`admin` at `http://localhost:8082/login.php`, open
`http://localhost:8082/lib/reqmgrsystems/reqMgrSystemView.php?tproject_id=1`, then check the
Event Viewer — no new Warning row.
