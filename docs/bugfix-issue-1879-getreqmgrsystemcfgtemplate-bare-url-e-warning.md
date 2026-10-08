# Bug fix — Issue #1879: `lib/ajax/getreqmgrsystemcfgtemplate.php` raised `E_WARNING Undefined array key "type"` on a bare request and wrote an Event Viewer row

| | |
|---|---|
| **Issue** | [#1879](https://github.com/sebiboga/testlink-upgraded/issues/1879) |
| **Type** | bug (legacy PHP 8 diagnostic / Event Viewer noise) |
| **Fix commit** | `6299f8209` — `lib/ajax/getreqmgrsystemcfgtemplate.php` (1 line) |
| **Severity** | minor — one spurious Event Viewer row per bare request; the HTTP answer is already sane |
| **Status** | **FIXED**, verified error-free |

## 1. Symptom

A request to `lib/ajax/getreqmgrsystemcfgtemplate.php` **without** a `type`
parameter answered `200 {"sucess":true,"cfg":"…"}` — a perfectly sane body — but
*also* logged:

```
log_level 2
E_WARNING
Undefined array key "type" - in .../lib/ajax/getreqmgrsystemcfgtemplate.php - Line 23
```

into the `events` table, i.e. into the Event Viewer. Every bare request repeated
it. Discovered while regression-testing #1716 (which does **not** cause it — that
issue's diff touches only `locale/*/strings.txt`).

Pre-fix measurement on this run (fresh DB, baseline `MAX(events.id) = 3`):

```
$ curl -s -b cookies "http://localhost:8082/lib/ajax/getreqmgrsystemcfgtemplate.php"
{"sucess":true,"cfg":"LOCALIZE: reqmgrsystem_invalid_type"}        [200]
$ mysql ... -e "SELECT id,log_level,description FROM events WHERE id > 3;"
4  2  E_WARNING\nUndefined array key "type" - in .../getreqmgrsystemcfgtemplate.php - Line 23
```

## 2. Environment and repro

- TestLink 2.0.1, PHP built-in server at `http://localhost:8082`, MariaDB
  `127.0.0.1:3306` (`testlink`/`testlink`), database freshly imported, login
  `admin`/`admin`.
- Branch `fix/issue-1879-bare-url-type-warning` off `a6973d54b`.
- Login via the HTML form (`tl_login` / `tl_password` — the 2.0.1 field names),
  then:

```bash
curl -s -c c.txt -b c.txt -X POST http://localhost:8082/login.php \
     -d "tl_login=admin&tl_password=admin&submit=Login"
mysql ... -e "SELECT COALESCE(MAX(id),0) FROM events;"                  # 3
curl -s -b c.txt "http://localhost:8082/lib/ajax/getreqmgrsystemcfgtemplate.php"
mysql ... -e "SELECT id,log_level,description FROM events WHERE id > 3;" # the E_WARNING row
```

## 3. Root cause chain

| # | hop | location |
|---|---|---|
| 1 | `$type = intval($_REQUEST['type']);` reads a superglobal key that need not exist | `lib/ajax/getreqmgrsystemcfgtemplate.php:23` |
| 2 | on PHP 8 an absent key is `E_WARNING "Undefined array key"`, not a silent `null` | PHP 8 semantics |
| 3 | `config.inc.php` + `common.php` (loaded at `:18-19`) install TestLink's error handler before line 23 runs, so the warning is captured | `testlinkInitPage($db)` `:20` |
| 4 | the handler writes it to `events` (`log_level 2`) — the visible symptom | `watchPHPErrors` |
| 5 | `intval(null) === 0`, which is not a key of `tlReqMgrSystem::getTypes()` (single entry `1`, `lib/functions/tlReqMgrSystem.class.php:33`), so the `else` branch already answers the correct "invalid type" message | `:26`, `:44-47` |

**The behaviour was always correct; only the diagnostic was spurious.** That is
why the response body is byte-identical before and after the fix.

**Why it is a missing port, not a regression:** the two BFF twins written for
#1625/#1626 already guard the same read — `api/reqmgrsystems/index.php:166`
(`$_GET['type'] ?? 0`) and `api/reqmgrsystemedit/index.php:292`
(`bffQueryInt('type')`) — and the other `lib/ajax/` readers guard the same way
(`getreqlog.php:38-42`, `getreqspeclog.php:38-42` `isset()` ternaries;
`gettestcasesummary.php:120-143` `isset() &&` short-circuits). This legacy
1.9.6 line (`@since 1.9.6`) was the only one left unguarded.

**Blast radius:** `grep -rn "\$_REQUEST\['type'\]" lib/` → **exactly 1 hit**
(this file). Its only callers are the two **retired**
`gui/templates/{dashio,tl-classic}/reqmgrsystems/reqMgrSystemEdit.tpl:42`
`displayCfgExample()` calls — since #1727 `reqMgrSystemEdit.php` is a redirect
shim and the modern editor goes through `api/reqmgrsystemedit/index.php?action=cfg_template`
— and those callers always send `type`, so normal UI traffic never triggered the
warning; it came from bare/manual/edge requests. Hence *minor*.

## 4. The fix

```diff
--- a/lib/ajax/getreqmgrsystemcfgtemplate.php
+++ b/lib/ajax/getreqmgrsystemcfgtemplate.php
@@ -20,7 +20,7 @@
 testlinkInitPage($db);

 $info = array('sucess' => true, 'cfg' => '');
-$type = intval($_REQUEST['type']);
+$type = intval($_REQUEST['type'] ?? 0);
 $mgr = new tlReqMgrSystem($db);
```

`0` is exactly the value the endpoint already produced for a missing key
(`intval(null)`), so every downstream branch, `sprintf` and response body stays
byte-identical — only the diagnostic disappears.

**Alternatives rejected**

* `isset()` ternary (`isset($_REQUEST['type']) ? intval($_REQUEST['type']) : 0`) —
  identical semantics, more characters, and the house style for this exact guard
  is the `?? 0` already used by the BFF twin at `api/reqmgrsystems/index.php:166`.
* `@intval(...)` / raising `error_reporting` — hides every future diagnostic in
  the same code, including real bugs (explicitly rejected in #1626's docs too).
* Answering `400` for a missing `type` (what the BFF does) — would change the
  legacy contract the `.tpl` callers rely on; out of scope for a minimal fix.

## 5. Verification

`php -l` clean. Five-request matrix post-fix, events baselined at `id = 5`:

| # | Request | Body | HTTP | New `events` rows |
|---|---|---|---|---|
| 1 | bare (no `type`) | `LOCALIZE: reqmgrsystem_invalid_type` | 200 | **0** |
| 2 | `?type=` | same as pre-fix | 200 | **0** |
| 3 | `?type=99` | same as pre-fix | 200 | **0** |
| 4 | `?type=1` | `LOCALIZE: reqmgrsystem_interface_not_implemented` | 200 | **0** (`log_level 32` LOCALIZE row only = #1716) |
| 5 | `?type=abc` | `LOCALIZE: reqmgrsystem_invalid_type` | 200 | **0** |

Bodies are **byte-identical** to the pre-fix capture for every variant; the only
difference in the whole matrix is the absence of the `E_WARNING` row.

Browser regression (fresh fixture `reqmgrsystems` row `id=1 'Contour Demo' type=1`):
logged in, opened `gui/templates/reqmgrsystems/reqMgrSystemEdit.html`, clicked
**show configuration example** → the BFF rendered the documented #1625/#1626
degradation `Interface contoursoapInterface not implemented`, console clean
(only the pre-existing "No label associated with a form field" a11y lint),
`events` gained **0** Error/Warning rows.

Event Viewer at the end of the run: `log_level` counts `1 → 0`, `2 → 2` (both
rows are **pre-fix** baselines `id=2`/`id=4`), `16 → 2` (audit logins),
`32 → 3` (#1716 LOCALIZE rows). No new Error/Warning attributable to the fix.

Regression suite: `Regression — Issue #1879` in `tmp/TLU_Test_Cases.md`,
**5/5 PASS**; gate `TLU_REQUIRE_SUITE="Issue #1879" bash ai/verify_test_suites.sh`
→ **7 PASS / 0 FAIL**, exit 0.

## 6. Files changed

* `lib/ajax/getreqmgrsystemcfgtemplate.php` — the one guarded read.
* `CHANGELOG` — one dated 2.0.1 line.
* `tmp/TLU_Test_Cases.md` — the numbered regression suite (git-ignored, appended).

No i18n bundle, no template, no BFF file, no DB change.

## 7. Known sibling, NOT fixed here

* **#1716** (open) — both `lang_get()` keys used by this endpoint
  (`reqmgrsystem_interface_not_implemented`, `reqmgrsystem_invalid_type`) exist
  in **no** locale file, so the endpoint answers the literal
  `LOCALIZE: <key>` marker and adds a `log_level 32` row per request. Visible in
  every row of the table above; deliberately left alone (one bug per run).

## 8. How to re-test

```bash
php -l lib/ajax/getreqmgrsystemcfgtemplate.php
# login, then:
curl -s -b c.txt "http://localhost:8082/lib/ajax/getreqmgrsystemcfgtemplate.php"
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "SELECT id,log_level,description FROM events WHERE id > <baseline>; "
# -> no log_level 2 row, and the JSON body is unchanged
```

Browser: log in `admin/admin` → **Requirement Management System** list → edit a
row → **show configuration example** → no new Event Viewer Error/Warning.
