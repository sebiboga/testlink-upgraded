# Bug fix — Issue #1617: an `issuetrackers` row with an unknown `type` returned a blank HTTP 500

## Symptom

The legacy Dashio **Issue Trackers** management screen
(`lib/issuetrackers/issueTrackerView.php`) returned **HTTP 500 with an empty body**
and wrote **seven** PHP 8 `E_WARNING` rows into the `events` table (the Event Viewer
source) on every render, whenever a single `issuetrackers` row carried a `type` that
is not a key of `tlIssueTracker::$systems`.

One bad row took down the **whole** screen: the fatal happened inside the
`foreach` loop of `getAll()`, so healthy trackers in the same table never rendered
either — the manager was left with a blank page and no way to find or fix the row.

Three separate routes reached the same defect:

| Route | Pre-fix |
|---|---|
| `issueTrackerView.php?tproject_id=<p>` (the grid) | `500`, 0 bytes, 7 event rows |
| `issueTrackerView.php?tproject_id=<p>&id=<bad row>` ("check BTS connection") | `500`, 0 bytes, 12 event rows |
| `POST issueTrackerEdit.php` with `doAction=checkConnection&type=<invalid>` | `500`, 0 bytes, 5 event rows |

Entry point: `http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=1`.


## Environment and fixtures

- TestLink 2.0.1, PHP 8.3, MariaDB on the local CI instance.
- Pre-fix baseline commit `0715e8fa6`; fix commits `31d0ba6e9`, `d1f283b2f`, `8c26a997d`.
- Minimal reproduction (note: 2.0.1's `issuetrackers` table has **no
  `configurable` column** — only `id,name,type,cfg`):

  ```sql
  DELETE FROM issuetrackers;
  INSERT INTO issuetrackers (name,type,cfg) VALUES ('BadType',0,'');      -- invalid type
  INSERT INTO issuetrackers (name,type,cfg) VALUES ('GoodType',1,'<testlink/>');
  DELETE FROM events;
  ```

  `0` is not a key of `$systems`. The map has **26** keys, of which **17 are
  `enabled`** (the 9 disabled ones are 10,11,12,13,16,17,18,20,21) and spans
  `lib/functions/tlIssueTracker.class.php:31-87`; `1` = bugzilla/xmlrpc.

## Measured evidence (before)

Server log — the actual killer, note the class name:

```
PHP Fatal error:  Uncaught Error: Class "Interface" not found
  in .../lib/functions/tlIssueTracker.class.php:613
[127.0.0.1:57174 [500]: GET /lib/issuetrackers/issueTrackerView.php?tproject_id=1
  - Uncaught Error: Class "Interface" not found
```

Event Viewer (`events` table) after one render:

| id | log_level | description |
|---|---|---|
| 8 | 2 | `include_once(): Failed opening 'Interface.class.php' for inclusion` |
| 7 | 2 | `include_once(Interface.class.php): Failed to open stream … - lib/functions/common.php:122` |
| 6 | 2 | `Trying to access array offset on null - tlIssueTracker.class.php:171` |
| 5 | 2 | `Trying to access array offset on null - tlIssueTracker.class.php:171` |
| 4 | 2 | `Undefined array key 0 - tlIssueTracker.class.php:170` |
| 3 | 2 | `Undefined array key 0 - tlIssueTracker.class.php:605` |
| 2 | 2 | `Undefined array key 0 - tlIssueTracker.class.php:604` |

## Root cause

`tlIssueTracker` stores the supported tracker systems in a private map,
`$systems` (`lib/functions/tlIssueTracker.class.php:31`), and the `issuetrackers.type`
column holds a **key of that map**. Four reads treated the stored value as if it were
guaranteed to be such a key, and two of them then handed the derived class name to
PHP's runtime.

**Hop 1 — `getImplementationForType()` fell through instead of failing** (`:168-172`)

```php
$spec = $this->systems[$issueTrackerType];         // :170  E_WARNING Undefined array key 0
return $spec['type'] . $spec['api'] . 'Interface'; // :171  E_WARNING x2, returns "Interface"
```

`null . null . 'Interface'` concatenates happily in PHP 8, so the function returned
the literal garbage class name **`"Interface"`** instead of failing loudly.

**Hop 2 — `getAll()` instantiated that garbage name** (`:612-615`, pre-fix numbering)

```php
$impl = $this->getImplementationForType($item['type']); // :612 -> "Interface"
$dummy = $impl::checkEnv();                             // :613 -> uncaught Error -> HTTP 500
```

The autoloader first *tried* to include it (`lib/functions/common.php:122`,
`include_once("Interface.class.php")` — the two extra event rows) before PHP gave up.
**This is the 500**, and being inside `foreach($rs as &$item)` it aborted the entire
listing.

**Hop 3 — `getAll()` read the description map unguarded** (`:604-605`)

```php
$item['verbose']    = $item['name'] . " ( {$this->types[$item['type']]} )"; // :604
$item['type_descr'] = $this->types[$item['type']];                         // :605
```

`$this->types` is the *same* map projected to display strings by `getTypes()`
(`:150-159`), so an unknown key warned twice per bad row.

**Hop 4 — the same pattern in the two `checkConnection()` paths**

- `tlIssueTracker::checkConnection()` `:770` → `new $class2create(...)`, reached from
  `lib/issuetrackers/issueTrackerView.php:28` (the grid's "check BTS connection" wrench).
- `issueTrackerCommands::checkConnection()` `:279` → `new $class2create(...)`, reached
  from the "Test Connection" button on `issueTrackerEdit.tpl:227`. Nothing validates
  `type` on the write path (the class has no `checkCreate`/`checkUpdate`), so this also
  fired when a *create* form was submitted with an invalid type.

**Why it breaks now.** `tlCodeTracker` — the code-tracker twin of this class — was
hardened for exactly this defect by **#1597/#1577** (`lib/functions/tlCodeTracker.class.php:114-137`
plus the degradation at `:580-598`). `tlIssueTracker` holds **two independent copies**
of that logic — a separate class, a separate `$systems` map and a separate set of read
sites — and was never ported. `lib/ajax/getissuetrackercfgtemplate.php:26` *was*
already guarded with `isset($itt[$type])`, which is why only the legacy controller routes
blew up.

## The fix

Minimal, and a deliberate port of the shape proven in #1597. Diff: 2 files,
+83 / −5.

1. **`tlIssueTracker::getImplementationForType()`** — return `NULL` for a type that is
   not a key of `$systems`, so the garbage class name can never be composed.
2. **`tlIssueTracker::getAll()`** — read `$this->types[$item['type']]` through a guarded
   local, and degrade a single row to `env_check_ok = false` when its implementation is
   unknown or unloadable, instead of taking the whole listing down:
   ```php
   if( is_null($impl) || !@class_exists($impl) || !is_callable([$impl, 'checkEnv']) )
   ```
   `@class_exists()` because the autoloader `include_once()`s `<class>.class.php`
   (`common.php:122`) and would otherwise log two warnings per row per page load;
   `is_callable()` rather than `method_exists()` because only a **public static**
   `checkEnv` can satisfy `$impl::checkEnv()` — a private or non-static declaration
   would raise an `Error` and re-introduce the very fatal this guard prevents.
3. **`tlIssueTracker::getLinkedTo()`** — return `NULL` when the linked tracker's type is
   not a key of `$systems`, so `getInterfaceObject()` degrades down the path it **already**
   handles for "project has `issue_tracker_enabled=1` but no tracker linked" (`:677-679`).
   The guard deliberately tests **`$systems` only, never `$types`**: `getTypes()`
   (`:150-159`) populates `$this->types` for `enabled` systems *only*, while `$systems` has
   26 keys of which **9 are disabled** (10, 11, 12, 13, 16, 17, 18, 20, 21). Guarding on
   `$types` as well would wrongly reject a legitimate disabled type such as gforge/soap
   (10) — and `link()` (`:489-502`) chooses INSERT vs UPDATE from `is_null($statusQuo)`
   against a `PRIMARY KEY (testproject_id)`, so a spurious `NULL` turns a project save
   into a "Duplicate entry" DATABASE error page. Caught in code review and fixed; the
   display label is read separately (`verboseType = ''` for a disabled type).
   Regression-guarded by the `DISABLED type` assertions in `tmp/unit_1617.php`.
4. **`tlIssueTracker::checkConnection()`** — return `false` before the `new`; the caller
   maps that to `'ko'`, which `issueTrackerView.tpl:60` already renders with the
   existing localized `bts_check_ko` badge.
5. **`issueTrackerCommands::checkConnection()`** — re-render the edit form with
   `connectionStatus='ko'` and the **legacy string that already ships for the ajax
   sibling**, `$TLS_issuetracker_invalid_type` ("Issue Tracker type %s is unknown",
   `locale/*/strings.txt`, used at `lib/ajax/getissuetrackercfgtemplate.php:46`).
   The `@class_exists()` half also covers a *valid* `$systems` key whose interface file
   is absent (the Contour case from #1593).


**No new i18n key and no bundle edit** was needed — every message reuses a string that
already exists. `gui/templates/i18n/*.json` was therefore left untouched.

### Alternatives considered and rejected

- **Add write-path `type` validation** (the #1577 approach, suggested in the issue body):
  rejected as out of scope for a bug fix — it needs a new i18n key in **all** locale
  bundles, changes create/edit semantics, and would not help rows that already exist
  (imports, hand-edited DB, dropped implementations). The read-side guards fully
  neutralise the reported defect. Worth doing as a follow-up.
- **Skip unknown rows in `getAll()`** (`unset($rs[$id])`): rejected — it would make the
  bad tracker *invisible*, leaving the manager no way to see or fix it. A degraded but
  present row is what the issue asks for.
- **`@`-suppress the `checkEnv()` call**: rejected — it hides the fatal and still blanks
  the page. The explicit guard is the fix.
- **Auto-correct the type on read** (e.g. fall back to the first valid type): rejected as
  a silent data mutation.

## Result (after)

| Route | Pre-fix | Post-fix |
|---|---|---|
| grid | `500`, 0 B, 7 event rows | `200`, 12259 B, **0 event rows** |
| `?id=<bad row>` | `500`, 0 B, 12 event rows | `200`, 14188 B, **0 event rows** |
| `checkConnection&type=0` | `500`, 0 B, 5 event rows | `200` + "Issue Tracker type 0 is unknown", **0 event rows** |

Full regression matrix in `tmp/TLU_Test_Cases.md` ("Suite 1617"), re-runnable via
`bash tmp/verify_1617.sh` (22 HTTP assertions) and `php tmp/unit_1617.php` (13
class-level assertions). Both harnesses **assert and exit non-zero on failure** — proved
discriminating, not just self-reporting: against the pre-fix baseline
`verify_1617.sh` reports **12 FAIL and exits 1**, against the fix **22 PASS and exits 0**.
Coverage includes the two regression guards — the code-tracker screen
(`lib/codetrackers/codeTrackerView.php`, the #1597 twin) and
`lib/ajax/getissuetrackercfgtemplate.php?type=0` — plus a disabled-type guard for the
`getLinkedTo()` subtlety described above. Browser-verified: the grid renders
"Showing 1 to 3 of 3 entries" with the bad row listed and clickable, no console errors,
Event Viewer empty.

## Two unrelated defects found while testing — filed, not fixed

Both reproduce with a **valid** tracker type and neither is a regression from this diff
(it touches no interface class and no template):

- **#1618** — `issueTrackerView.php?id=<non-existent id>` auto-vivifies a phantom grid
  row and logs 8 `Undefined array key` warnings. Cause: `issueTrackerView.php:27-28`
  writes `$gui->items[$args->id]['connection_status']` even when that key does not
  exist, and `issueTrackerView.tpl:51` then renders the stub as a real row.
- **#1619** — `lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php:135` reads
  `$this->cfg->uribase` / `$this->cfg->apikey` inside its `catch` block to build a log
  string; when `createAPIClient()` throws before the cfg object is populated, that is
  `Undefined property: stdClass::$uribase` — the diagnostic truncating itself.

## Files changed

| File | Purpose |
|---|---|
| `lib/functions/tlIssueTracker.class.php` | guards in `getImplementationForType()`, `getAll()`, `getLinkedTo()`, `checkConnection()` |
| `lib/issuetrackers/issueTrackerCommands.class.php` | guard in `checkConnection()` (the "Test Connection" button) |
| `tmp/repro_1617.sh` | single-crash reproduction (HTTP + `events` table) |
| `tmp/verify_1617.sh` | regression matrix rows 1-4, 7, 8, 9 over HTTP |
| `tmp/unit_1617.php` | regression matrix rows 5, 5b, 6 at the class level |
| `tmp/TLU_Test_Cases.md` | "Suite 1617" test cases and results |
| `docs/screenshots/issue-1617-grid-before.png` | before: blank HTTP 500 page |
| `docs/screenshots/issue-1617-grid-after.png` | after: grid functional, bad row listed |
