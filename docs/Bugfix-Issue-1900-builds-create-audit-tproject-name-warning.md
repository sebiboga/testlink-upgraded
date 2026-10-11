# Bugfix-Issue-1900-builds-create-audit-tproject-name-warning (#1900)

## Symptom

Creating a build from the modern **Builds & Releases** screen (`buildsView.html` inline modal →
`POST /api/builds/`) wrote an `E_WARNING` into the Event Viewer on **every** successful create.
The build *was* created; the warning was only in the audit-log call. The audit row itself was
malformed: the "Test Plan" slot of `audit_build_created` was **empty**.

## Reproducing it by hand

Fixture: `php tmp/fixtures_1900.php` (from repo root) creates the isolated project
`T1900` (id 1) + plan `T1900-PLAN` (id 2). Log in `admin/admin`, then:

```
curl -b cookie.txt -X POST http://localhost:8082/api/builds/ \
  -H "Content-Type: application/json" -H "Origin: http://localhost:8082" \
  -d '{"tplan_id":2,"name":"B1900-REPRO","active":1,"open":1}'
→ {"status":"ok","id":1,"cfields_written":0}     HTTP 200
```

`SELECT id,log_level,description FROM events ORDER BY id DESC` then shows:

```
3  2  E_WARNING\nUndefined array key "tproject_name" - in .../api/builds/index.php - Line 889
4  16 O:18:"tlMetaStringHelper":...label:audit_build_created...params:{i:0;s:27:"T1900 project - issue #1900";i:1;N;i:2;s:11:"B1900-REPRO";}
```

`i:1;N` = the plan-name parameter is `null`.

## Root cause

`api/builds/index.php:804` — the `POST /` create — builds its context from
`resolveTplanGated()` → `resolveTplan()`, whose return array (`:134-146`) is:

```php
[ 'tplan_mgr' => ..., 'tplan_id' => ..., 'tplan_name' => ..., 'tproject_id' => ... ]
```

It contains **no** `tproject_name`. The audit call at `:888-889` then read the missing key:

```php
logAuditEvent(TLS('audit_build_created',
    tprojectName($tp, $ctx['tproject_id']), $ctx['tproject_name'], $name),  // <-- undefined key
    'CREATE', $buildID, 'builds');
```

The `audit_build_created` string (`locale/en_US/strings.txt:3391`) is
`Test Project '{%1}' - Test Plan '{%2}' - Build '{%3}' was created` — so `%2` must be the **plan**
name, i.e. `$ctx['tplan_name']`, which the same context already carries.

### Why it broke NOW — the regression source

`git log -S"\$ctx['tproject_name']" -- api/builds/index.php`:

- `be4bc24e39` (2026-08-22, the original BFF) shipped the POST line with the **correct**
  `$ctx['tplan_name']`.
- `883e38b29d` — *"fix(opencode): Build Create/Edit (#1787)"* (2026-10-02) — fixed a **real**
  `Undefined array key "tplan_name"` that review had found on the **save (PUT)** path
  (`resolveBuild()` returns `tproject_name`, not `tplan_name`). The patch substituted
  `tplan_name` → `tproject_name` on **both** the PUT line **and** the POST line. The PUT
  substitution was correct; re-applying it to the POST line was **over-application** and turned
  a working line into this warning. (The POST context is plan-addressed; the PUT context is
  build-addressed, so the two routes need *different* keys.)

### Blast radius

`grep -n "\$ctx['tproject_name']" api/builds/index.php` → 3 call sites:

| line | route | `$ctx` source | has `tproject_name`? | status |
|---|---|---|---|---|
| `:889` | `POST /` create | `resolveTplanGated()` → `resolveTplan()` | **no** | **buggy (this issue)** |
| `:1081` | `PUT /{id}` save | `resolveBuild()` | yes | silent |
| `:1122` | `DELETE /{id}` | `resolveBuild()` | yes | silent |

Measured: the PUT and DELETE audit rows emit **no** `log_level=2` event, so they are not affected
by the warning. (Both do carry the *project* name in the plan-slot `%2` as a fallback — a
cosmetic side effect of builds being project-scoped since #503, where no plan name is intrinsic
to a build row. No warning, separate topic, left unchanged.)

## The fix

One line, `api/builds/index.php:889` — revert the over-applied substitution:

```diff
   logAuditEvent(TLS('audit_build_created',
-         tprojectName($tp, $ctx['tproject_id']), $ctx['tproject_name'], $name),
+         tprojectName($tp, $ctx['tproject_id']), $ctx['tplan_name'], $name),
           'CREATE', $buildID, 'builds');
```

`$ctx['tplan_name']` is guaranteed by `resolveTplan()` (`:144`). Alternatives considered:
adding `tproject_name` to `resolveTplan()` would silence the warning but **not** fill `%2` with
the plan name (it would repeat the project's name in the plan slot — the very mess PUT/DELETE
already have); changing the TLS signature would be a wider i18n change for zero user-facing
gain. The one-liner is the minimal correct fix.

## Verification

| # | Check | Result |
|---|---|---|
| 1 | `POST /` create (was the failing symptom) | **PASS** — HTTP 200; audit `%2` = `T1900-PLAN`; **0** new `log_level=2` |
| 2 | `POST /` duplicate name in the same plan | **PASS** — HTTP 409 `warning_duplicate_build`, no warning |
| 3 | `POST /` with `copy_to_all_tplans:true` (2 plans present) | **PASS** — HTTP 200, no warning, audit names correct |
| 4 | `PUT /{id}` save | **PASS** — HTTP 200, no `log_level=2` (unchanged) |
| 5 | `POST /{id}/flags` toggle | **PASS** — HTTP 200, no `log_level=2` |
| 6 | `DELETE /{id}` | **PASS** — HTTP 200, no `log_level=2` (unchanged) |
| 7 | Browser `buildsView.html?tplan_id=2` → Create Build `B1900-BROWSER` | **PASS** — row appears, 0 JS console errors, plan-name audit row, no new ERROR/WARNING |
| 8 | `SELECT * FROM events WHERE log_level=2` after the whole pass | **PASS** — only the two pre-fix repro rows remain |

`php -l api/builds/index.php` clean. Regression suite: `## Regression — Issue #1900` in
`tmp/TLU_Test_Cases.md` (10/10 PASS). Screenshot: `docs/screenshots/issue-1900-buildsview-fixed.png`.

## Files changed

| file | change |
|---|---|
| `api/builds/index.php` | `:889` revert `$ctx['tproject_name']` → `$ctx['tplan_name']` on the `POST /` create audit |
| `tmp/fixtures_1900.php` | isolated project + plan fixture (git-ignored `tmp/`) |
| `tmp/TLU_Test_Cases.md` | `## Regression — Issue #1900` suite, 10 cases |
| `CHANGELOG` | `[KEY BUGFIX] - #1900` |