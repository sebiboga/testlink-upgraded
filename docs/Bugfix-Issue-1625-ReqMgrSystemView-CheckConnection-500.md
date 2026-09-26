# Bug fix — Issue #1625: `reqMgrSystemView.php?id=<n>` returned a blank HTTP 500 on the "Check connection" path

## Symptom

The legacy Dashio **Req. Management System** list
(`lib/reqmgrsystems/reqMgrSystemView.php`) returned **HTTP 500 with a 0-byte body**
whenever an `id` parameter was present, and wrote **4** PHP 8 `E_WARNING` rows into
the `events` table (the Event Viewer source) on every such request (**5** when the `id`
does not exist — one extra `array offset on null`).

| Route | Pre-fix |
|---|---|
| `reqMgrSystemView.php?id=<existing row>` ("check connection" wrench) | `500`, 0 bytes, 4 event rows |
| `reqMgrSystemView.php?id=999` (id that does not exist) | `500`, 0 bytes, 5 event rows (4 + the `:619` one) |
| `reqMgrSystemView.php` (no `id`) | `200`, but still 2 event rows per load |

Entry point: `http://localhost:8082/lib/reqmgrsystems/reqMgrSystemView.php?id=1`
(the wrench icon of the first column in
`gui/templates/dashio/reqmgrsystems/reqMgrSystemView.tpl:47-49` links exactly there).

## Environment and fixtures

- TestLink 2.0.1, PHP 8.3 built-in server, MariaDB `testlink` on the local CI instance (`127.0.0.1:3306`,
  throwaway container credentials), login `admin`/`admin`.
- Pre-fix baseline commit `6d110083a`; fix commit `b4c325c33`.
- Minimal reproduction:

  ```sql
  INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('Jira Demo',1,'{}');
  ```

  `type=1` is the **only** entry of `tlReqMgrSystem::$systems`
  (`lib/functions/tlReqMgrSystem.class.php:32-33`: `1 => contour/soap`), i.e. **100 % of
  the rows the shipped UI can create** are affected.

- Gotcha when scripting the login in 2.0.1: authentication goes through the BFF
  `POST /api/auth/login`, which requires same-origin proof. Without
  `-H "Origin: http://localhost:8082" -H "Referer: .../login.php"` the call answers
  `{"status":"error","message":"Forbidden: missing or mismatched same-origin proof (CSRF protection)"}`
  and every later request returns `200` with a *login-redirect* body — which silently
  invalidates HTTP-only measurements.

## Measured evidence (before)

HTTP:

```
?id=1                  http=500 bytes=0
?id=999                http=500 bytes=0
?id=1&tproject_id=1    http=500 bytes=0
```

PHP level, isolated harness (`config.inc.php` + `common.php` + `doDBConnect`, then the
same two methods the controller calls):

```
getAll OK rows=1
checkConnection(1)   THROWS Error: Class "contoursoapInterface" not found
checkConnection(999) THROWS Error: Class name must be a valid object or a string
```

Event Viewer (`events` table), all `log_level=2`, `activity=PHP`. 4 rows for a real
row; the 5th below appears only for `?id=999`:

| description | source |
|---|---|
| `include_once(contoursoapInterface.class.php): Failed to open stream: No such file or directory` | `lib/functions/common.php:122` |
| `include_once(): Failed opening 'contoursoapInterface.class.php' for inclusion (include_path=…)` | `lib/functions/common.php:122` |
| the same pair **again** (the autoloader is entered once from `getAll()`, once from `new`) | `lib/functions/common.php:122` |
| `Trying to access array offset on null` *(only for `?id=999`)* | `lib/functions/tlReqMgrSystem.class.php:619` |

Browser: the document loads with **no** body at all (the page is blank inside the frame).

## Root cause

`tlReqMgrSystem` derives the class name of a requirement-management integration from the
row's `type`, and `checkConnection()` instantiated that name **without ever checking that
it can be loaded**. The chain has two independent endings, both fatal.

**Hop 1 — `getImplementationForType()` composes a name that can never resolve**
(`lib/functions/tlReqMgrSystem.class.php:111-115`)

```php
$spec = $this->systems[$system];              // :112
return $spec['type'] . $spec['api'] . 'Interface';   // :114
```

For the one shipped type this returns `contoursoapInterface`. The directory on the
`include_path` (`lib/reqmgrsystemintegration/`, added in `cfg/const.inc.php:43-47`)
contains **only** the abstract base `reqMgrSystemInterface.class.php` — the concrete
`contoursoapInterface` **was never in this repository**:
`git log --all -- '*contour*'` returns no commit at all. So the name is right and the
*file* is absent, permanently.

**Hop 2 — `checkConnection()` instantiated it anyway** (`:616-622`, pre-fix `:616-621`)

```php
$xx = $this->getByID($systemID);        // :618  -> NULL when the id does not exist
$class2create = $xx['implementation'];   // :619  E_WARNING "array offset on null"
$system = new $class2create(...);        // :620  uncaught Error -> HTTP 500
```

* `?id=<real row>` → `Error: Class "contoursoapInterface" not found`
* `?id=999`       → `getByID()` is `NULL` → `Error: Class name must be a valid object or a string`

Both are `Error`, **not** `Exception`, so the `try { … } catch (Exception $e)` in the
sibling `getInterfaceObject()` (`:588-608`) never applies. PHP 8 aborts the request
*before* a single byte is flushed (`reqMgrSystemView.php:33-35` never reached), which is
precisely why the symptom is a bare 500 with an empty body.

**Hop 3 — the same screen also probes the class on every listing load** (`:528-537`)

```php
$impl = $this->getImplementationForType($item['type']);
if( method_exists($impl,'checkEnv') ) { … }
```

`method_exists()` on an unknown class name fires the autoloader, whose `include_once()`
(`lib/functions/common.php:122`) fails and logs **two** events per row per page load —
the Event Viewer noise tracked in **#1593**, for this screen.

**Why the 500 is *empty*.** The `Error` is raised while the page is still being built, so
nothing has been written yet, and with `display_errors=Off` (the default for both FPM and
the built-in server) PHP adds nothing either — hence a 0-byte body rather than a stack
trace. On the PHP 5.x that TestLink 1.9.20 targeted the same line was an uncatchable
`E_ERROR` *with* a message when `display_errors` was on, which is why this was never a
blank page back then. The defect itself is pre-existing since 1.9.6, and `?id=<n>` was
reachable only by hand-clicking the wrench icon.

**The two twins of this code were already hardened for the very same defect and this one
was not:**

| Class | Guard | Issue |
|---|---|---|
| `tlIssueTracker::checkConnection()` | returns `false` before the `new` | #1617 |
| `tlIssueTracker::getAll()` | `is_null($impl) \|\| !@class_exists($impl) \|\| !is_callable([$impl,'checkEnv'])` | #1617 |
| `tlCodeTracker::getAll()` | same shape | #1597 |
| `tlReqMgrSystem` | **none** | #1625 |

## The fix

Minimal, and a deliberate port of the shape already proven twice. Diff: 2 files,
+59 / −3.

1. **`tlReqMgrSystem::checkConnection()`** — two early `return false` guards before the
   `new`:
   ```php
   if( is_null($xx) || !isset($xx['implementation']) ) { return false; }
   $class2create = $xx['implementation'];
   if( !is_string($class2create) || !@class_exists($class2create) ) { return false; }
   ```
   `false` is the right verdict because the whole chain already understands it:
   `reqMgrSystemView.php:36` maps it to `'ko'` and `reqMgrSystemView.tpl:52-53` draws the
   **existing localized** `reqmgrsystem_check_ko` badge.
2. **`reqMgrSystemView.php:28-37`** — the probe is stamped only on a row that exists
   (`$args->id > 0 && isset($gui->items[$args->id])`). `$gui->items` is keyed by row id, so
   without this the `?id=999` path would have degraded from a 500 into a **phantom empty
   grid row** (the same family as #1618 on the issue-tracker side).
3. **`tlReqMgrSystem::getAll()` `:528-551`** — the identical
   `is_null || !@class_exists || !is_callable` guard used by both twins, so a bad row
   degrades to `env_check_ok = false` instead of taking the listing down, and the two
   `include_once` events per row per load disappear (the #1593 noise for this screen).

`@class_exists()` is `@`-silenced **on purpose**: the autoloader `include_once()`s
`<class>.class.php` (`lib/functions/common.php:122`) and an un-silenced guard would trade
a fatal for two warnings per row. `is_callable()` rather than `method_exists()` because
only a **public static** `checkEnv` can satisfy `$impl::checkEnv()` — a private or
non-static declaration would raise an `Error` and re-introduce the very fatal the guard
prevents.

**No new i18n key and no bundle edit** was needed: every message reuses a string that
already exists, so `gui/templates/i18n/*.json` was left untouched.

### Alternatives considered and rejected

- **Write a `contoursoapInterface`.** Impossible as a *restoration*: the file has never
  existed in this repository. A SOAP stub could only ever return a fake verdict, which is
  worse than an honest "not connected".
- **Drop `contour` from `$systems` / `getTypes()`** (option (c) in the issue body):
  rejected — it changes the persisted type table that existing rows and the modernized
  screen's dropdown depend on, to neutralise a diagnostic defect. The read-side guards
  fully neutralise the reported fatal.
- **Catch `Throwable` in the controller.** Rejected: it hides the defect instead of
  degrading the single row, and would still emit the 5 events.
- **Guard on `$types` as well as `$systems`.** Not needed here and deliberately avoided
  (the #1617 code-review catch): `getTypes()` populates `$this->types` for enabled
  systems only, so an extra guard would reject legitimate disabled types.

## Result (after)

| Route | Pre-fix | Post-fix |
|---|---|---|
| `?id=<existing row>` | `500`, 0 B, 5 event rows | `200`, 10994 B, KO badge, **0 event rows** |
| `?id=999` | `500`, 0 B, 5 event rows | `200`, 10887 B, **no phantom row**, **0 event rows** |
| no `id` | `200`, 2 event rows | `200`, **0 event rows** |
| `reqMgrSystemEdit.php?doAction=checkConnection&id=<n>` | `500`, 0 B | `200` |
| `?id=abc` / `id=0` / `id=-1` | `200` | `200`, probe not run |

Full regression matrix in `tmp/TLU_Test_Cases.md` ("Suite 1625"), re-runnable via
`bash tmp/verify_1625.sh` (31 asserting HTTP/Event-Viewer/DB checks). The harness
**asserts and exits non-zero on failure** — proved discriminating, not self-reporting:
against the pre-fix baseline (`git checkout 6d110083a -- <the two files>`) it reports
**16 PASS / 11 FAIL, exit 1**; against the fix **31 PASS / 0 FAIL, exit 0**.

Coverage also includes the regression guards: the two hardened twins
(`lib/issuetrackers/issueTrackerView.php`, `lib/codetrackers/codeTrackerView.php` — the
#1617/#1597 siblings) still answer `200` with zero new warnings, and the modernized
2.0.1 BFF `api/reqmgrsystems/index.php` still returns the row
(`{"status":"ok","items":[{… "typeDescr":"contour (Interface: soap)" …}],"total":1}`).
Browser-verified for both `?id=1` and `?id=999`: the grid renders (header + `Jira Demo` +
`Create`), and the browser console reports `<no console messages found>`.

## The second copy of the same probe (found by code review, also fixed here)

`lib/reqmgrsystems/reqMgrSystemCommands.class.php::checkConnection()` — the "Check
connection" action dispatched from the **edit** screen — contained the identical
duplicated instantiation, unguarded, and was therefore a second, independent 500:

```
$ curl ... "reqMgrSystemEdit.php?doAction=checkConnection&id=1"     ->  http=500 bytes=0   (pre-fix)
$ curl ... "reqMgrSystemEdit.php?doAction=checkConnection&id=999"   ->  http=500 bytes=0   (pre-fix)
$ curl ... "reqMgrSystemEdit.php?doAction=checkConnection&id=1"     ->  http=200           (post-fix)
```

`checkConnection` is whitelisted in `reqMgrSystemCommands.class.php:38` and dispatched by
`reqMgrSystemEdit.php:22-26`, so the route is live and reachable by plain URL. Rather than
guarding a second copy, the duplicate is **deleted**: the method now delegates to the one
hardened implementation, `$this->mgr->checkConnection($argsObj->id)`, keeping its original
`'ok' : 'ko'` mapping. This is the same decision the issue-tracker twin took in #1617.

## Unrelated defects found while testing / reviewing — filed, not fixed

Both reproduce with **valid** data and neither is a regression from this diff:

- **#1626** — a `reqmgrsystems` row whose `type` is not a key of `$systems` still logs
  **6** `E_WARNING` rows per load: `Undefined array key` at `tlReqMgrSystem.class.php:522`
  and `:523` (`$this->types[$item['type']]`) and `:113`/`:114`
  (`$this->systems[$system]`). The page is `200` and usable, so it is noise only; both
  twins already have the `isset($this->types[…]) ? … : ''` hardening.
- **#1627** — `lib/reqmgrsystems/reqMgrSystemEdit.php` **without** (or with a
  non-whitelisted) `doAction` is a hard `500`: `$op` stays `null` and `renderGui()`
  dereferences `$opObj->template`. Reachable by plain URL.
- **#1628** — the `doAction=checkConnection` route that #1625 unblocked writes
  `E_WARNING Undefined array key "checkConnection"` (3 per pass):
  `reqMgrSystemCommands.class.php:72` reads `$obj->l18n[$caller]` for a key
  `init_labels()` was never given it; the issue-tracker twin carries the missing
  `'checkConnection' => 'btn_check_connection'` entry
  (`lib/issuetrackers/issueTrackerCommands.class.php:70-71`). **Revealed** by this fix
  (the 500 used to mask it), not caused by it.
- **#1629** — `tlReqMgrSystem::getInterfaceObject()` is the **third** unguarded `new` in
  the class, and its `catch (Exception $e)` cannot catch a PHP 8 `Error`; it is called from
  `lib/requirements/reqSpecCommands.class.php:44` for every project with
  `reqmgr_integration_enabled`. Filed as an **UNVERIFIED code-path reading** — the fresh CI
  database has no project linked to a ReqMgrSystem, so the 500 was not reproduced here.

## Files changed

| File | Purpose |
|---|---|
| `lib/functions/tlReqMgrSystem.class.php` | guards in `checkConnection()` and in the `getAll()` `checkEnv` block |
| `lib/reqmgrsystems/reqMgrSystemView.php` | probe only on a row that exists (no phantom row) |
| `lib/reqmgrsystems/reqMgrSystemCommands.class.php` | the edit screen's second copy of the probe now delegates instead of duplicating the unguarded `new` |
| `tmp/verify_1625.sh` | regression harness, 31 asserting checks (force-added: `tmp/` is gitignored) |
| `tmp/TLU_Test_Cases.md` | "Suite 1625" test cases and results |
| `docs/screenshots/issue-1625-reqMgrSystemView-id1-before.png` | before: blank HTTP 500 page |
| `docs/screenshots/issue-1625-reqMgrSystemView-id1-after.png` | after: grid rendered, row carries the KO badge |
