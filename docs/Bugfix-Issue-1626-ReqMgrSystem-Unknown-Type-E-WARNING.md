# Bug fix — Issue #1626: `reqMgrSystemView.php` logged 5 `E_WARNING` rows per load for a system whose `type` is not in `$systems`

## Symptom

Every load of the **Req. Management System** list wrote **5 new `E_WARNING` rows**
into the `events` table (the Event Viewer source) for each `reqmgrsystems` row whose
`type` is **not** a key of `tlReqMgrSystem::$systems`. The page itself kept answering
`HTTP 200` and still rendered the row, so this is pure Event-Viewer noise — but it
repeats on **every single request**.

| Route | Pre-fix | Post-fix |
|---|---|---|
| `lib/reqmgrsystems/reqMgrSystemView.php` (legacy grid) | `200` + **5** event rows per load | `200` + **0** |
| `lib/reqmgrsystems/reqMgrSystemView.php?id=<bad row>` | `200` + **8** event rows (5 + 3 from `getByID()`) | `200` + **0** |
| `GET /api/reqmgrsystems/index.php` (**modern BFF screen**) | `200` + **5** event rows per load | `200` + **0** |

Entry point: `http://localhost:8082/lib/reqmgrsystems/reqMgrSystemView.php`, or the
modern screen `http://localhost:8082/gui/templates/reqmgrsystems/reqMgrSystemView.html`.
Note that the **modern** BFF screen was noisy too — the issue only named the legacy URL,
but both go through `getAll()`.

This is the *noise* half of the #1625 family, filed as a sibling by the #1625 run
("same degradation as the two twins already hardened … the reqmgr twin has neither
guard"). **No i18n key, no locale bundle and no template was touched** by this fix.

## Environment and fixtures

- TestLink 2.0.1, **PHP 8.3.35**, PHP built-in server, MariaDB `testlink` on
  `127.0.0.1:3306` (`testlink`/`testlink`), database freshly imported, login
  `admin`/`admin`.
- Branch `fix/issue-1626` off `origin/sebiboga`; fix commit `826473beb`,
  suite/docs commit `c15e24904`.
- Gotcha when scripting the login in 2.0.1: the **form** fields are
  `tl_login` / `tl_password` / `tl_login_btn` (not `user` / `password`). The BFF
  `POST /api/auth/login` instead needs same-origin proof, so a `curl` session must
  either drive the HTML form or send `Origin` + `Referer`.
- Minimal reproduction (`$systems` ships exactly one entry, so any other code is "unknown"):

  ```sql
  INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('Ghost Type',99,'{}');   -- unknown type
  INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('Good Contour',1,'{}');  -- contour/soap, the known type
  ```

  `$systems` (`lib/functions/tlReqMgrSystem.class.php:32`):

  ```php
  var $systems = array( 1 => array('type' => 'contour', 'api' => 'soap', 'enabled' => true, 'order' => -1));
  ```

## Measured evidence (before)

```
view=200
select id,log_level,description from events where activity='PHP' order by id;

id  log_level  description
 2  2  E_WARNING  Undefined array key 99                 - tlReqMgrSystem.class.php - Line 522
 3  2  E_WARNING  Undefined array key 99                 - tlReqMgrSystem.class.php - Line 523
 4  2  E_WARNING  Undefined array key 99                 - tlReqMgrSystem.class.php - Line 113
 5  2  E_WARNING  Trying to access array offset on null  - tlReqMgrSystem.class.php - Line 114
 6  2  E_WARNING  Trying to access array offset on null  - tlReqMgrSystem.class.php - Line 114
```

and with `?id=1` three more (the second `getImplementationForType()` call inside
`getByID()`), and the same 5 from `GET /api/reqmgrsystems/index.php`.

**Correction to the original report.** The issue said "6 `E_WARNING` rows per load".
On PHP 8.3.35 the measured figure is **5** for the plain list: line 114 is one
expression, `return $spec['type'] . $spec['api'] . 'Interface';`, with exactly two
array reads on the NULL `$spec`, so 2 (not 3) `array offset on null`. The count
differs from the reporter's environment; the cause, the lines and the per-load
repetition are exactly as filed.

## Root cause

`tlReqMgrSystem::$types` is **projected** from `$systems` inside `getTypes()`
(`lib/functions/tlReqMgrSystem.class.php:99`):

```php
$this->types[$code] = $spec['type'] . " (Interface: {$spec['api']})";
```

so an unknown `type` has **no** `types` entry either — `types` is a strict subset of
`systems`'s keys (the file has exactly **one** writer and **four** readers). Three of
those readers had no `isset()`:

| Site | Diagnostics per bad row |
|---|---|
| `getAll()` — `$this->types[$item['type']]` read **twice** (`:522-523`) | 2× `Undefined array key <type>` |
| `getImplementationForType()` — `$spec = $this->systems[$system]` (`:113`) | 1× `Undefined array key <type>` |
| `getImplementationForType()` — `$spec['type']` / `$spec['api']` on the NULL (`:114`) | 2× `Trying to access array offset on null` |
| `getLinkedTo()` — `$ret['verboseType']` (`:595`) | 1× `Undefined array key <type>` |

`getImplementationForType()` also returned the **garbage class name** `"Interface"`
(`null . null . 'Interface'`) — a *valid-looking* name any caller may then instantiate.

### Why it did not fatal — and why that is not a defence

#1625 had already wrapped `getAll()`'s env probe in

```php
is_null($impl) || !@class_exists($impl) || !is_callable([$impl, 'checkEnv'])
```

so the garbage name was discarded, the page kept answering `200` and the row kept
rendering. That guard stops the **fatal**; the two `$this->types` reads and the two
reads inside `getImplementationForType()` happen **before/outside** it. That is
precisely why #1625 left this noise behind.

## Fix

This is a **missing port, not a new regression**: the two sibling classes were already
hardened for the exact same defect by #1597 and #1617.

| File | Change |
|---|---|
| `lib/functions/tlReqMgrSystem.class.php` `getImplementationForType()` | `if( !isset($this->systems[$system]) ) { return null; }` before the read — verbatim the #1597 / #1617 shape (`tlCodeTracker.class.php:114-137`, `tlIssueTracker.class.php:168-190`) |
| `lib/functions/tlReqMgrSystem.class.php` `getAll()` | `$this->types[$item['type']]` read once into a guarded `$typeDescr` local — verbatim `tlCodeTracker.class.php:570-571` / `tlIssueTracker.class.php:622-623` |
| `lib/functions/tlReqMgrSystem.class.php` `getLinkedTo()` | same guarded local for `$ret['verboseType']` |
| `api/reqmgrsystems/index.php:105` | `!is_null($impl) && @class_exists($impl) && …` — `class_exists(NULL)` is `E_DEPRECATED` on PHP 8.1+ and `method_exists(NULL, …)` is a **TypeError**; the `&&` short-circuit is what makes the list route safe by construction |
| `api/reqmgrsystems/index.php:294` | a NULL implementation answers `Interface for type <n> not implemented` instead of `Interface  not implemented` (empty class name) |

An unknown type now degrades to an **empty type description**, so the row stays visible
and **repairable** — the issue's own "Expected" and both twins' behaviour.

### Caller audit (all 5 `getImplementationForType()` call sites)

3 needed **no** change:

- `getByID()` (`:321`) is already NULL-tolerant, because `checkConnection()` guards with
  `!isset($xx['implementation'])` at `:655` and `isset(NULL)` is `false`;
- `getAll()` (`:530`) already has #1625's `is_null($impl)`;
- `lib/ajax/getreqmgrsystemcfgtemplate.php:29` and `api/reqmgrsystems/index.php:159`
  are both preceded by an `isset($types[$type])` gate, and since `types` is projected
  from `systems` the key is guaranteed present.

### Alternatives rejected

- **`@` / `error_reporting()`** — hides every *future* diagnostic in the same code,
  including real bugs, and diverges from the two classes that already solved this.
- **Dropping the unusable row from the listing** — the manager then cannot *see* the
  bad `type` to repair it.
- **Guarding only the three legacy call sites** — the modern BFF list, `getLinkedTo()`
  and the `?id=` route would keep logging.

## Verification

**Library-level A/B** — the pre-fix class
(`git show origin/sebiboga:lib/functions/tlReqMgrSystem.class.php`) and the fixed one
loaded in two PHP processes against the same database, with a `set_error_handler`
counting every diagnostic:

```
########## PRE-FIX ##########
  !! E[2] Undefined array key 99                 (orig.class.php:113)
  !! E[2] Trying to access array offset on null  (orig.class.php:114)   x2
impl(99)  = 'Interface'
  !! E[2] Undefined array key 99                 (orig.class.php:522)
  !! E[2] Undefined array key 99                 (orig.class.php:523)
  !! E[2] Undefined array key 99                 (orig.class.php:595)
                                             => 12 diagnostics

########## POST-FIX ##########
impl(1)   = 'contoursoapInterface'
impl(99)  = NULL
impl(0)   = NULL
descr(bad)  = ''      verbose(bad)  = 'Ghost Type (  )'
descr(good) = 'contour (Interface: soap)'
getLinkedTo(1).verboseType = ''
                                             => 0 diagnostics
```

**Web matrix** (9 requests, `events` baselined right before):

```
list http=200 | id=1 http=200 | id=2 http=200 | bff list http=200
meta/types http=200 | cfg_tpl 1 http=200 | cfg_tpl 99 http=400
ajax type=1 http=200 | ajax type=99 http=200

select count(*) as new_events from events where id > 21;
new_events
0
```

The **BFF list JSON is byte-identical** to the pre-fix capture (only a missing
trailing newline in the captured file differs), i.e. the fix changes nothing a client
can observe except the absence of diagnostics and the `null` → `''` normalisation the
BFF already performed on its own side.

**In-browser A/B** by swapping only the class file back to `origin/sebiboga` in the
running app: 5 events per load; `git checkout --` restores the fix and the next load
adds 0. Screenshots: `docs/screenshots/issue-1626-reqmgr-system-list-before-fix.png`,
`issue-1626-reqmgr-system-list-after-fix.png`,
`issue-1626-event-viewer-after-fix.png`.

**Regression suite:** `bash tmp/verify_1626.sh` → **21 PASS / 0 FAIL, exit 0**;
with only the class file reverted → **15 PASS / 6 FAIL, exit 1**, so the harness is
provably discriminating. Numbered suite in `tmp/TLU_Test_Cases.md`
("Regression — Issue #1626"). `php -l` clean on both files.

## Sibling defect found while testing (filed, not fixed)

**#1714** — `requirement_spec_mgr::get_by_id()` interpolates an **empty**
`RSPEC_REV.id = ` into a WHERE clause when `get_last_active_version()` returns
`false` (1064 syntax error + `E_WARNING` at
`requirement_spec_mgr.class.php:186`); same family as #1708, different file, needs
its own regression matrix. Out of scope for this issue.

## Related

- #1597 — `tlCodeTracker` (the twin this fix mirrors)
- #1617 — `tlIssueTracker` (the other twin)
- #1625 — `reqMgrSystemView.php?id=` empty HTTP 500; the guard whose *scope* left this
  warning noise behind
