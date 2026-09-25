# Bug fix — Issue #1597: one `codetrackers` row with an unknown type 500s the whole Code Trackers grid (empty body)

## Symptom

The modern **Code Trackers** screen (`gui/templates/codetracker/codetrackerView.html`) went blank
whenever **a single** row in `codetrackers` had a `type` outside
`{1 = stash/rest, 200 = github/rest}` (`lib/functions/tlCodeTracker.class.php:28-29`):

- `GET /api/codetracker/index.php` → **HTTP 500 with a 0-byte body** (not even a JSON error),
- the grid rendered its 6 column headers with **no rows and no footer**,
- 6 `log_level = 2` rows landed in the Event Viewer per page load,
- the fatal happened inside `getAll()`, i.e. **after** the view/manage gate, so it hit **every**
  role — a `codetracker_view`-only user included.

The state is reachable through an import/migration, a hand-edited DB, or an implementation removed
in a later release. The modern BFF itself cannot create it: `POST`/`PUT` validate the type against
`getSystems(['status' => 'enabled'])` (`api/codetracker/index.php:308`,
`rejectInvalidTrackerType()`), and the stored-token routes re-check at `:463` and in
`githubInterfaceFor()` `:370`.

## Environment and fixtures

- TestLink 2.0.1, PHP 8.3 built-in server on `http://localhost:8082` (docroot = repository root),
  stderr → `tmp/php_server.log`; MariaDB `127.0.0.1:3306`, db/user/password `testlink`.
- Freshly imported DB — `codetrackers` was **empty** (0 rows) at session start, so the fixtures
  were recreated:

```sql
INSERT INTO codetrackers (name,type,cfg) VALUES ('Bad Type Tracker',5,'<codetracker></codetracker>');
INSERT INTO codetrackers (name,type,cfg) VALUES ('Stash Valid',1,'<codetracker><uribase>http://127.0.0.1:7999/</uribase></codetracker>');
INSERT INTO codetrackers (name,type,cfg) VALUES ('GitHub Valid',200,'<codetracker><uribase>https://api.github.com</uribase><repository>o/r</repository><branch>main</branch><token>secret123</token></codetracker>');
```

- Login `admin` / `admin`; browser checks in headless Chrome, API checks with `curl` +
  `X-Requested-With: XMLHttpRequest` and a session cookie.

## Root cause

One missing `isset()` guard, amplified by an unguarded static call:

```
codetrackers.type = 5  (not a key of tlCodeTracker::$systems = {1,200})
 └─ api/codetracker/index.php:254       getAll(['output'=>'add_link_count','checkEnv'=>true])
    └─ tlCodeTracker.class.php:568      $impl = getImplementationForType($item['type'])
       └─ tlCodeTracker.class.php:115   $spec = $this->systems[$codeTrackerType];   ← NO isset GUARD
          :116  E_WARNING Undefined array key 5
          :120  E_WARNING Trying to access array offset on null
          :124  E_WARNING Trying to access array offset on null  (×2)
          returns the literal string "Interface"
       └─ tlCodeTracker.class.php:569   $impl::checkEnv()
          └─ autoloader lib/functions/common.php:122  include_once('Interface.class.php')
             → 2 × E_WARNING "Failed opening …class.php"
          → Uncaught Error: Class "Interface" not found  → script aborts before out()
```

Measured pre-fix, `tmp/php_server.log`:

```
PHP Fatal error:  Uncaught Error: Class "Interface" not found in …/lib/functions/tlCodeTracker.class.php:569
Stack trace:
#0 …/api/codetracker/index.php(254): tlCodeTracker->getAll()
[500]: GET /api/codetracker/index.php
```

`checkEnv => true` is not incidental: it is the option that makes the **Environment** column work —
requested by the legacy list (`lib/codetrackers/codeTrackerView.php:23`) and re-added to the BFF by
issue #973 ("restore the Environment column"). So the very option that fixed the column walked into
the fatal. `php -l` cannot see this: it is a runtime class-resolution error.

### Blast radius of the un-guarded call

| Call site | Reachable with an out-of-map type? | Before the fix |
|---|---|---|
| `tlCodeTracker::getAll():568` (list, `checkEnv`) | yes, from DB rows | **fatal 500, empty body** (this issue) |
| `tlCodeTracker::getByID():358` (detail / edit modal) | yes, from DB rows | 4 warnings + `implementation: "Interface"` in the JSON |
| `lib/codetrackers/codeTrackerView.php:23` (legacy view) | yes, from DB rows | same fatal on the legacy page (upstream origin) |
| `lib/ajax/getcodetrackercfgtemplate.php:24` | no — pre-guarded (`isset($ctt[$type])`) | `codetracker_invalid_type` |
| `lib/codetrackers/codeTrackerCommands.class.php:265` | only from the legacy edit controller, which validates first | pre-existing, unreachable with a bogus type |
| `api/codetracker/index.php:308,370,375,463` | no — all pre-validate | 400 with a clear message |

## The fix

### 1. `lib/functions/tlCodeTracker.class.php` — guard in the library, degrade per row

`getImplementationForType()` now returns `null` for a type that is not a key of `$systems`, **before**
`$spec` is dereferenced (`:115-127`). One guard covers both unguarded call sites, including the legacy
view; `getByID():358` then yields `implementation: null`, which `trackerToJSON()`'s existing
`$item['implementation'] ?? ''` maps to `""`.

`getAll()`'s `checkEnv` block (`:578-599`) no longer calls the implementation blindly:

```php
if( is_null($impl) || !@class_exists($impl) || !method_exists($impl, 'checkEnv') )
{
  $item['env_check_ok'] = false;
  $item['env_check_msg'] = '';
}
else
{
  $dummy = $impl::checkEnv();
  ...
}
```

so one bad row degrades **only itself** and every other row keeps being listed. `class_exists()` is
`@`-silenced on purpose: the autoloader `include_once()`s `<class>.class.php`
(`lib/functions/common.php:122`) and would otherwise re-log the two `Failed opening …class.php`
warnings for a *known* type whose file is missing — the same reasoning already applied to the
requirement-manager list route (`api/reqmgrsystems/index.php:107`, issue #1593).

### 2. `api/codetracker/index.php` — `typeKnown` (one field)

`trackerToJSON()` adds `'typeKnown' => $typeLabel !== ''`. `$typeLabel` is built from
`$mgr->systems[$item['type']]` and is non-empty for every known type (`"stash (Interface: rest)"`),
so it doubles as the "is this type in the map" flag: no extra lookup, no string sniffing in JS.

### 3. `gui/templates/codetracker/codetrackerView.html` — make the cause visible

The issue complained that "the UI shows nothing about it". `renderTable()` now renders
`TLi18n.t('ct.msg.invalidType', {type: t.type})` in a red `badge-env-ko` badge in the **Type** cell
when `typeKnown === false`, and the **Environment** cell keeps its `ct.envKo` fallback (the degraded
row arrives with an empty `env_check_msg`).

**No new i18n key was introduced** — `ct.msg.invalidType` already exists in all 10 locale bundles
(`en, de, es, fr, it, ja, pt, ro, ru, zh`) with its `{type}` placeholder. The interpolated string goes
through `esc()` like every other cell, preserving the #1581 escaping contract. The stale
`tlCodeTracker.class.php:566-572` line reference in the neighbouring comment was updated to
`:578-599`.

## Alternatives rejected

- **Guard only in the BFF** (probe `checkEnv` in the route the way
  `api/reqmgrsystems/index.php:97-113` does): leaves 4 offset warnings per row per load (Event-Viewer
  noise) and leaves the legacy view and `getByID()` broken. The guard belongs in the library, once.
- **Throw / return a dummy class** for an unknown type: still a 500, just with a nicer message — the
  grid would still be lost for every viewer.
- **Delete or auto-heal the row from the read path**: a read route must never write (the invariant the
  #970 write gate established), and a row can legitimately be unknown after an implementation is
  dropped in a future version.

## Verification (fresh DB, fixtures above)

| # | Case | Expected | Measured | Verdict |
|---|---|---|---|---|
| 1 | row `type=5` | 200, row listed, `env_check_ok=false`, `typeKnown=false`, no new events | `API HTTP 200 len=314`; `rows:3`, footer `Showing 1 to 3 of 3 entries`, Type cell `Code Tracker type 5 is unknown.`, Environment cell `Environment check failed`; `events WHERE log_level=2` = 6 (unchanged) | PASS |
| 2 | row `type=1` | green OK | `stash (Interface: rest)` + `OK` | PASS |
| 3 | row `type=200` | green OK | `github (Interface: rest)` + `OK` | PASS |
| 4 | `GET /api/codetracker/index.php/1` | 200, `implementation: ""` | `HTTP 200`, `implementation === ""` | PASS |
| 5 | `POST /api/codetracker/index.php/1/test_connection` | 400 | `{"status":"error","message":"Unknown code tracker type"}` | PASS |
| 6 | screen in the browser | row visible with 2 diagnostics, no console error | console: no messages | PASS |
| 7 | manager repair path | the row is fixable from the UI | edit modal → type `stash` → Save → DB `type` 5 → **1**; row then green | PASS |
| 8 | static gates + log | clean | `php -l` ×2, all 6 `<script>` blocks parse, `git diff --check` clean, no new WARNING/fatal | PASS |

Screenshots: `screenshots/issue-1597-codetracker-unknown-type-before.png` (headers, 0 rows, no
footer) and `screenshots/issue-1597-codetracker-unknown-type-after.png` (3 rows, both diagnostics on
the bad one).

Regression suite: `tmp/TLU_Test_Cases.md` → *"Regression — Issue #1597"*, **7/7 PASS**
(Test 1 records the pre-fix failure, Tests 2-7 the post-fix behaviour).

## Out of scope (noted, not changed)

`tlCodeTracker::checkConnection()` and `getInterfaceFromDB()` still do
`new $xx['implementation'](...)` without a type check. Nothing in the modernized screen or the BFF
reaches them (the modern wrench icon calls the guarded `/{id}/test_connection` route), and they were
equally fatal before the fix — with the string `"Interface"` instead of `null` — so this is not a
regression. Hardening them is a candidate for a future task.

## Files changed

| File | Purpose |
|---|---|
| `lib/functions/tlCodeTracker.class.php` | `isset` guard in `getImplementationForType()` + per-row `checkEnv` degradation in `getAll()` — the root-cause fix |
| `api/codetracker/index.php` | `typeKnown` in `trackerToJSON()` |
| `gui/templates/codetracker/codetrackerView.html` | Type cell renders the localized "type N is unknown" badge; updated line reference |
| `CHANGELOG` | 2.0.1 `[KEY BUGFIX]` entry |
| `tmp/TLU_Test_Cases.md` | regression suite (7 tests) |
| `docs/screenshots/issue-1597-codetracker-unknown-type-{before,after}.png` | UI evidence |
