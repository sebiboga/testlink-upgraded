# Bugfix — Issue #1514: reqEdit Create New Version aborts with HTTP 500 — nested SQL comment in `closeOpenTCVersionOnOpenLinks`

## Problem

Creating a new requirement version from the modern Requirement Editor
(`gui/templates/requirements/reqEdit.html` → **Create New Version**, BFF
`api/reqedit` `POST ?action=version`) **aborts with HTTP 500** on XHR (legacy
non-XHR paths die with a full HTML backtrace — CWE-200 path disclosure):

- browser console: `Failed to load resource: ... 500 (Internal Server Error)`
- the `events` table (Event Viewer) gains a `log_level=1` (ERROR) DATABASE row:
  ```
  1064 - You have an error in your SQL syntax; ... near '*/ UPDATE tcversions
  SET is_open = 0 WHERE id IN ( SELECT tcversion_id FROM req_
  ```
  triggered from `requirement_mgr.class.php(2511)` inside
  `closeOpenTCVersionOnOpenLinks()` ← `copy_version()` ← `create_new_version()`.

The crash happens **after** the row copy has already run, so the caller is left
with a half-finished new version: `log_message` NULL, source version **not**
frozen (`is_open=1`), TC link freeze never applied, and the frontend never
refreshes (shows the error, stays on the old version).

## Repro steps (measured)

Environment: http://localhost:8082 (PHP built-in server, docroot=repo root),
MariaDB `testlink`, PHP 8, login admin/admin. Fresh fixtures: testproject
`id=1` (option_reqs=1), req_spec `id=6`, requirement `id=8` (`REQ-1514`, v1 =
type `2`/Feature, expected_coverage `2`, scope `fixture scope v1`), custom field
linked + design value `HIGH` on version node 9.

1. Open `gui/templates/requirements/reqEdit.html?id=8&tproject_id=1` — the form
   correctly shows TYPE=Feature, EXPECTED COVERAGE=2, Version 1.
2. Click **Create New Version**, accept the prompt with any log message.
3. Network panel: `POST /api/reqedit/index.php?action=version&id=8` → **500**.
4. DB: the new `req_versions` row exists with copied type/coverage/scope/status,
   but `log_message` NULL and the source version still `is_open=1`.

## Root Cause

Chain (each hop backed by `file:line`):

1. `lib/functions/requirement_mgr.class.php:2498` in
   `closeOpenTCVersionOnOpenLinks()` builds a **self-delimited** SQL debug
   comment: `$debugMsg = '/* Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__ . ' */';`
2. `:2503` wraps it **again** in a SQL comment: `$sql = " /* $debugMsg */ UPDATE ..."`.
3. Result: `/* /* Class:requirement_mgr - Method: closeOpenTCVersionOnOpenLinks */ */ UPDATE tcversions ...` —
   MySQL/MariaDB do **not** support nested block comments. The parser closes the
   outer `/*` at the first `*/`, leaving a dangling ` */ UPDATE ...` → **SQL
   syntax error 1064**.
4. `lib/functions/database.class.php:206`: on an `XMLHttpRequest` request,
   `exec_query()` throws `Exception('Database error (query failed)')` instead of
   the legacy HTML backtrace, so the exception unwinds
   `closeOpenTCVersionOnOpenLinks → copy_version → create_new_version` to
   `api/reqedit/index.php:471`'s catch → HTTP 500. Legacy/non-XHR paths hit
   `database.class.php:223` `die()` + backtrace.

### Why it breaks now (regression source)

Commit `f24b6d28b` (Fixes #1513) changed line 2498 from the correct form
`'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;` — introduced by
`ac7f81cf2`, which pairs it with the `" /* $debugMsg */ "` wrapper in the SQL —
back to the legacy self-delimited `'/* Class:... */'` form. Legacy 1.9.20 built
the SQL as `" $debugMsg UPDATE ..."` (no extra `/* */` wrapper), so the
self-delimited debugMsg was fine there; the modern template double-wraps it.

### Blast radius

Single call site: `requirement_mgr.class.php:2482` inside `copy_version()`,
gated by `freezeLinkedTCases` (config defaults `reqTCLinks->freezeLinkOnNewREQVersion`
and `freezeBothEndsOnNewREQVersion` are TRUE, `config.inc.php:1415/1424`). Any
`create_new_version()` therefore crashes at this step:

- modern `api/reqedit` `POST ?action=version` (this issue),
- legacy `reqViewVersions` "create new version",
- `api/reqimport` `createFromMap/createFromXML` with `actionOnHit=create_new_version`.

Every other class method that self-delimits its debugMsg with `/* */` builds the
SQL as `" $debugMsg <verb> ..."` (no extra wrapper) — verified lines 2566, 2646,
2669, 2691, 2709, 2725, 3992 — so only `closeOpenTCVersionOnOpenLinks` was
affected.

## Fix

One line, `lib/functions/requirement_mgr.class.php:2498` — drop the SQL comment
delimiters from the debugMsg so the existing `" /* $debugMsg */ "` template
composes a single valid comment (the class-standard pattern used everywhere
else):

```php
-    $debugMsg = '/* Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__ . ' */';
+    $debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
```

Rationale for the minimal approach: the SQL template already owns the comment
syntax (` /* ... */ `), the debugMsg must stay plain text. Alternatives rejected:
(a) reverting `f24b6d28b` wholesale — unnecessary, only this line regressed; (b)
changing the template to `" $debugMsg "` — diverges from the rest of the class
and touches more code.

## Verification

Regression suite in `tmp/TLU_Test_Cases.md` — **11/11 PASS**. Key measured results:

- Browser **Create New Version** → `POST ?action=version&id=8` **HTTP 200**
  `{"status":"ok","version":2,"version_id":13,"new_version":2}`; form reloads to
  Version 2, info bar "New version created v2".
- New `req_versions` row: `type=2`, `expected_coverage=2`, `scope/status` copied;
  `log_message` persisted verbatim; CF design value `HIGH` copied to the new node
  (`copy_cfields`); source version frozen (`is_open=0`).
- With a real `req_coverage` link (link_status=1) to an open tcversion: no
  exception and the linked tcversion is closed (`is_open=0`) — the
  `freezeBothEndsOnNewREQVersion` freeze path now runs end-to-end.
- CLI harness `requirement_mgr::create_new_version(8,1)` (legacy non-XHR path)
  returns `{id,version,msg:'ok'}` with no die/backtrace.
- `events` table: 0 new `log_level=1` entries; browser console: 0 JS errors.
- `php -l lib/functions/requirement_mgr.class.php` clean; generated SQL is a
  single `/* Class:requirement_mgr - Method: closeOpenTCVersionOnOpenLinks */ UPDATE ...`.

Screenshot: `docs/screenshots/issue-1514-reqedit-create-new-version-fixed.png`
(modern Requirement Editor after Create New Version, Version 2 / Feature / 2).

## Files changed

- `lib/functions/requirement_mgr.class.php` — 1 line.
- `tmp/TLU_Test_Cases.md` — `Suite 1514` regression suite.
- `docs/Bugfix-Issue-1514-ReqEdit-NewVersion-NestedSqlComment.md` — this mirror +
  `docs/screenshots/issue-1514-reqedit-create-new-version-fixed.png`.
- `CHANGELOG` — KEY BUGFIX line (Fixes #1514).