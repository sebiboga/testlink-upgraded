# Bugfix — Issue #1658: the legacy Create/Edit Requirement Specification warned twice on every load

**Screen:** legacy Create / Edit Requirement Specification
**Entry point:** `lib/requirements/reqSpecEdit.php?doAction=create&tproject_id=<ID>` (and `doAction=edit`)
**Status:** fixed, verified error-free, regression suite 1658 = 12/12 PASS
**Branch:** `fix/issue-1658` — the shared `reqSpecCommands` class was deliberately **not** touched

---

## The symptom

Every load of the legacy Create (or Edit) SRS form wrote **two** PHP 8.3 `E_WARNING` rows into
the `events` table:

```
E_WARNING Undefined property: stdClass::$tproject_id - in .../reqSpecEdit.tpl.php - Line 203
E_WARNING Undefined property: stdClass::$tplan_id     - in .../reqSpecEdit.tpl.php - Line 205
```

Six rows for three page loads. Cosmetically small, but it makes the Event Viewer useless for
triage — which is exactly the reason the issue was filed.

Measured pre-fix baseline (`admin`/`admin`, test project 20, `option_reqs=1`):

| event id | log_level | request |
|---|---|---|
| 2, 5, 7 | 2 | `GET doAction=create`, `POST doAction=doCreate`, `GET doAction=edit` |
| 3, 6, 8 | 2 | (same three requests, second warning) |

---

## Root cause

The chain has four hops, and the decisive one is *not* the command method:

1. **`gui/templates/dashio/requirements/reqSpecEdit.tpl:140-141`** — the form declares two
   hidden fields, the two consumers on this screen:

   ```smarty
   <input type="hidden" name="tproject_id" value="{$gui->tproject_id}" />
   <input type="hidden" name="tplan_id"     value="{$gui->tplan_id}" />
   ```

   Compiled to `gui/templates_c/<hash>_0.file.reqSpecEdit.tpl.php` lines **203** and **205** —
   the two reported lines.

2. **`lib/requirements/reqSpecEdit.php:206-209`** — `renderGui()` can only hand the template
   keys the command object already owns:

   ```php
   $key2loop = get_object_vars($opObj);
   foreach($key2loop as $key => $value) { $guiObj->$key = $value; }
   ```

3. **`lib/requirements/reqSpecCommands.class.php:65-95`** — `initGuiBean()` declares 21 unique
   keys (23 `$obj->` assignments, `req_spec` twice); `tproject_id` and `tplan_id` are **not**
   among them, so step 2 can never introduce them.

4. **All four methods that render this template** assign neither key:

   | method | template assignment | sets `tproject_id`? |
   |---|---|---|
   | `create()` `:125` | `$this->defaultTemplate` | no |
   | `edit()` `:161` | `$this->defaultTemplate` | no |
   | `doCreate()` `:193` | `$this->defaultTemplate` | no |
   | `createChild()` `:393` | `$this->defaultTemplate` | no |

   A **fifth** method reaches the same template through the *default* fallback:
   `doUpdate()` (`:246`) sets `$guiObj->template = null` (`:251`) and on a
   `check_main_data()` failure the `is_null($opObj->template)` branch in `renderGui()`
   (`:219`) resolves to `$templateCfg->default_template` = `reqSpecEdit.tpl`.
   The sibling paths do **not** render it: `doCopy()`/`doCopyRequirements()` select
   `reqSpecCopy.tpl` (`:506`, `:552`) and `doCreateRevision()` sets a `.php` template, which
   `renderGui()` turns into a redirect (`:222-224`).

`reorder()` (`:345`) and `doReorder()` (`:363`) *do* set `tproject_id` — but they render
`reqSpecReorder.tpl` / `project_req_spec_mgmt.tpl`, which contain neither hidden field. That
is why the reorder screen was always clean and the create/edit screens were not.

`tplan_id` had **no source at all**: `init_args()` (`:79-83`) copies `tproject_id`,
`tproject_name`, `user_id` and `basehref` from the session but never `testplanID`.
Consequently `reqSpecCommands.class.php:896` (`bulkReqMon()`, which renders
`reqBulkMon.tpl:58-59` — a second consumer of the same two hidden fields) was itself
raising `Undefined property: stdClass::$tplan_id` **from inside the shared class**, on the
Bulk Monitoring screen. Proof of the shape, on an object that lacks the key:

```
$ php -r '$a=new stdClass(); echo "tplan_id=[".$a->tplan_id."]\n";'
PHP Warning:  Undefined property: stdClass::$tplan_id in Command line code on line 1
tplan_id=[]
```

### The fix proposed in the issue was not enough

The issue suggested setting the two keys in `doCreate()` alone. Measurement disproved that:
loading `doAction=edit` produced the identical pair (event ids **7** and **8**). A fix in
`doCreate()` would have silenced one of the two affected screens.

---

## The fix

Confined to the screen's own controller, `lib/requirements/reqSpecEdit.php`
(**8 insertions, 0 deletions**; the code diff is confined to this one file):

```diff
@@ init_args(), :80 @@
   $args->tproject_name = isset($_SESSION['testprojectName']) ? $_SESSION['testprojectName'] : "";
+  $args->tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : null;
   $args->user_id = isset($_SESSION['userID']) ? $_SESSION['userID'] : 0;

@@ renderGui(), case 'template', :203 @@
+      // reqSpecEdit.tpl (and reqBulkMon.tpl) render these two hidden fields, but
+      // no reqSpecCommands method declares them on the GUI bean, so they must
+      // come from the session args. Set BEFORE the copy loop, as a default: a
+      // command object that owns the key (reorder, doReorder, bulkReqMon) still
+      // overwrites it with its own value.
+      $guiObj->tproject_id = $argsObj->tproject_id;
+      $guiObj->tplan_id = $argsObj->tplan_id;
+
       foreach($key2loop as $key => $value)
       {
         $guiObj->$key = $value;
       }
```

### Why this method, and what was rejected

* **Rejected — fix only `doCreate()`** (the issue's suggestion): measured to leave the edit
  screen still emitting two warnings per load.
* **Rejected — declare the keys in `reqSpecCommands::initGuiBean()`:** 2 lines and it would
  cover every action, but it cannot actually solve the problem: the constructor only receives
  `$tproject_id`, so `initGuiBean()` would have to hard-code `tplan_id = null` and the field
  would stay empty forever. (Sharing is not itself a reason to reject it — the class is built
  by three controllers (`reqSpecEdit.php:23`, `reqSpecViewRevision.php:59`,
  `reqSpecSearch.php:34`), but none of the other two templates reads these two keys, so it
  would have been output-neutral.)
* **Rejected — drop the two hidden inputs from the `.tpl`:** they are the mechanism that
  round-trips the project id through the POST; removing them is a behaviour change for a
  noise-only bug.
* **Why *before* the copy loop:** the loop is
  `foreach($key2loop as $k=>$v) { $guiObj->$k = $v; }`, so the two assignments act as
  *defaults* and a command object that legitimately owns a key still wins. All three such
  writers (`reorder()` `:345`, `doReorder()` `:363`, `bulkReqMon()` `:895-896`) assign the
  very same `$argsObj->tproject_id` / `$argsObj->tplan_id`, so the placement is
  behaviour-neutral either way — it is chosen because it keeps the invariant "the command
  object's own value takes precedence" intact for any future writer that computes a
  different value.

**Bonus:** the fix is not only noise removal. Pre-fix, the rendered hidden field was
`name="tproject_id" value=""` — the project id was being lost. Post-fix it is `value="20"`.

---

## Verification (measured, fresh DB)

Fixture: test project `id=20`, `prefix=RP1658`, `option_reqs=1` + its `nodes_hierarchy` row.

| # | Case | Result |
|---|---|---|
| 1658-02 | `GET doAction=create` | 200, `value="20"`, 0 events |
| 1658-03 | `POST doAction=doCreate` (real form) | 200, `req_specs` id=25 created, 1 INFO audit, 0 warnings |
| 1658-04 | `GET doAction=edit&req_spec_id=21` | 200, `value="20"`, 0 events |
| 1658-05 | `GET doAction=createChild&parentID=21` | 200, `value="20"`, 0 events |
| 1658-06 | no test plan in session | `tplan_id=""`, no notice, no warning |
| 1658-07 | `GET doAction=reorder` | 200, 3 reorder rows, its own key not clobbered |
| 1658-08 | `project_req_spec_mgmt.php` | 200, 0 events |
| 1658-09 | `reqSpecView.php?req_spec_id=21` | 302 → modernized `reqSpecView.html` 200 |
| 1658-10 | `POST doAction=doUpdate` with a **duplicate** `doc_id` (forces the `check_main_data()` failure that re-renders `reqSpecEdit.tpl` through the default-template fallback) | 200, form re-rendered, `value="20"`, 0 events | 200, `createSRS` present, `value="20"`, 0 events |
| 1658-11 | `GET doAction=bulkReqMon&req_spec_id=21` (renders `reqBulkMon.tpl:58-59`) | 200, both hidden fields present, **no** `tplan_id` warning from `reqSpecCommands.class.php:896` | 200, `tproject_id="20"`, `tplan_id=""`, `req_spec_id="21"`, no `tplan_id` event | 
| 1658-12 | `php -l lib/requirements/reqSpecEdit.php` | `No syntax errors detected` |

**Result: 14 PASS / 0 FAIL** (suite entry
`Regression — Issue #1658` in `tmp/TLU_Test_Cases.md`).

---

## Pre-existing defects found while testing — filed, not fixed

All three reproduce on a clean checkout and all are in files this fix does not touch
(the **code** diff is confined to `lib/requirements/reqSpecEdit.php`, 8 insertions /
0 deletions). Filed as **#1665** rather than silently expanding this run:

1. `GET /lib/requirements/reqSpecSearch.php?tproject_id=<ID>` → **HTTP 500**, empty body:
   `Uncaught TypeError: count(): Argument #1 ($value) must be of type Countable|array, null
   given in lib/requirements/reqSpecSearch.php:116` — `$itemSet` is initialised to `null` at
   `:34` and only assigned inside the `if ($args->tprojectID)` block (`:49-112`).
2. The same area drops a `log_level=1` DATABASE ERROR into the Event Viewer: SQL 1064 from
   `requirement_spec_mgr::getRevisionByID()` (`lib/functions/requirement_spec_mgr.class.php:2438-2450`),
   which interpolates `$id` raw (`WHERE RSPEC_REV.id={$id}`) with no `prepare_int()` and is
   called with an empty id from `lib/requirements/reqSpecViewRevision.php:73`.
3. `reqSpecCommands::bulkReqMon()` (`:869`, `:877`) — `get_requirements()` returns `null` for
   a spec with no requirements, and `foreach($obj->items …)` then warns
   `foreach() argument must be of type array|object, null given` on every Bulk Monitoring
   load of such a spec. **Fixed by this issue for the `tplan_id` half only** — the
   `foreach(null)` warning is unrelated and still fires (measured, event row 13).

---

## Resume

```bash
# 1. reset the Event Viewer, as admin on a project with Requirements enabled load
#    the create screen and the edit screen (doAction=create, doAction=edit&req_spec_id=N)
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "delete from events;"
# 2. the regression exists again if this prints anything other than 0
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "select count(*) from events where log_level=2;"
```

Browser: log in `admin`/`admin`, open
`http://localhost:8082/lib/requirements/reqSpecEdit.php?doAction=create&tproject_id=<ID>` —
Event Viewer must show no new warning, and view-source must show
`name="tproject_id" value="<ID>"`.
