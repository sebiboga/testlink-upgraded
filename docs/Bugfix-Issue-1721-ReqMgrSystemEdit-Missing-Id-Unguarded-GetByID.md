# Bug fix — Issue #1721: `reqMgrSystemEdit.php?doAction=edit&id=<missing>` returned a broken form + 5 `E_WARNING` rows (unguarded `getByID`)

| | |
|---|---|
| **Issue** | [#1721](https://github.com/sebiboga/testlink-upgraded/issues/1721) |
| **Type** | bug (legacy controller — unguarded `getByID()`; latent dead code) |
| **Fix commit** | `39b3cdfb0` — `lib/reqmgrsystems/reqMgrSystemCommands.class.php` (`edit()`, +24/-1) |
| **Severity** | minor — the HTTP symptom was already neutralised by #1727; the root-cause defect survived as dead code |
| **Status** | **FIXED**, verified error-free |

## 1. Symptom (as reported, 2026-09-29 16:56Z)

`lib/reqmgrsystems/reqMgrSystemEdit.php?doAction=edit&id=<id that does not exist>`
answered **HTTP 200 with a broken edit form** (empty name/type/cfg) and wrote
**5 `E_WARNING` (`log_level=2`) rows** into `events` on every request. An existing
id wrote 0 rows — the warnings were id-specific.

## 2. Current measurement — the symptom is already gone

The report predates commit `3897a17c8` (`feat(#1727)`, 2026-09-29 22:18Z), which
replaced the whole legacy page with a **session-guarded 302 shim**. Verified on a
fresh DB this run (login `admin`/`admin`, branch
`fix/issue-1721-edit-missing-id`):

| Request | Result now |
|---|---|
| legacy `?doAction=edit&id=99999` | `302 Found` → `.../reqMgrSystemEdit.html?id=99999` |
| legacy `?doAction=edit&id=2` (existing) | `302 Found` → same modern editor |
| legacy bare URL (no `doAction`) | `302 Found` → modern editor (create mode) |

The browser followed the 302; the modern editor then called
`GET /api/reqmgrsystemedit/index.php?action=init&id=99999&prune=1` → **404**
`{"status":"error","code":"not_found","message":"Requirement management system not found"}`,
rendering the localized **“Requirement management system not found”** state.

`events` after the whole matrix: **1 row** (the login `audit_login_succeeded`,
`log_level=16`) — i.e. **0** new Error/Warning rows. The reported 200-broken-form
+ 5-warning behaviour is **not reproducible**.

## 3. Root cause chain (still present in the tree)

| # | hop | location |
|---|---|---|
| 1 | `$guiObj->item = $this->mgr->getByID($argsObj->id);` — no null check | `lib/reqmgrsystems/reqMgrSystemCommands.class.php:171` |
| 2 | `getByID()` → `getByAttr()` | `lib/functions/tlReqMgrSystem.class.php:281-285,301` |
| 3 | `getByAttr()` returns `null` when `get_recordset()` finds no row | `lib/functions/tlReqMgrSystem.class.php:336-350` |
| 4 | legacy `renderGui()` copied `$opObj` vars onto `$gui`, so `$gui->item` became `null` | retired `reqMgrSystemEdit.php` (pre-#1727) |
| 5 | `reqMgrSystemEdit.tpl:72,85,93,102,128` dereference `$gui->item.<field>` → 5 `E_WARNING` rows | `gui/templates/dashio/reqmgrsystems/reqMgrSystemEdit.tpl` |

**Blast radius:** `reqMgrSystemCommands` is **orphaned dead code** — `grep -rn
reqMgrSystemCommands` returns only its own file plus comments in the shim. Its
`create()` (line 107) already builds a safe zeroed entity, and
`tlReqMgrSystem::checkConnection()` was hardened for exactly this `getByID()==null`
return in #1625 (`tlReqMgrSystem.class.php:738`, `is_null($xx) || !isset(...)`).
`edit()` was the one remaining unguarded caller of `getByID()` in this screen's
family. No other screen shares the path, so there is no regression surface.

## 4. The fix

```diff
--- a/lib/reqmgrsystems/reqMgrSystemCommands.class.php
+++ b/lib/reqmgrsystems/reqMgrSystemCommands.class.php
@@ edit()
-    $guiObj->item = $this->mgr->getByID($argsObj->id);
+    $item = $this->mgr->getByID($argsObj->id);
+    if( is_null($item) )
+    {
+      $item = array('id' => 0);
+      foreach($this->entitySpec as $property => $type)
+      {
+        $item[$property] = ($type == 'int') ? 0 : '';
+      }
+      $guiObj->item = $item;
+      $guiObj->template = "reqMgrSystemView.php";
+      return $guiObj;
+    }
+
+    $guiObj->item = $item;
     $guiObj->canManage = $argsObj->currentUser->hasRight($this->db,'reqmgrsystem_management');
     return $guiObj;
```

A missing id now degrades to a **well-formed empty entity** (the same shape
`create()` builds) and a redirect to the list screen, instead of handing a `null`
to the renderer. The non-null path is unchanged.

**Alternatives rejected**

* Deleting the orphaned class + the two dead `.tpl` files — a larger, drive-by
  cleanup; the issue is a hardening request, and a guard is the minimal change
  that removes the named defect (same reasoning as #1625's guard).
* Re-pointing the shim to an old-style render — would re-open the pre-#1727
  behaviour; explicitly out of scope.
* Adding a new i18n “not found” message key — unnecessary: the modern editor owns
  the user-facing 404; the legacy class is unreachable.

## 5. Verification

* `php -l lib/reqmgrsystems/reqMgrSystemCommands.class.php` → `No syntax errors detected`.
* Legacy `?doAction=edit&id=99999` → `302`; `?doAction=edit&id=2` → `302`; bare URL → `302`.
* Modern `GET /api/reqmgrsystemedit/?action=init&id=99999` → `404 not_found`.
* `SELECT count(*) total, sum(log_level<4) warn_or_err FROM events` → `total=1, warn_or_err=0`.
* Regression suite `Regression — Issue #1721` in `tmp/TLU_Test_Cases.md`, all cases PASS;
  gate `TLU_REQUIRE_SUITE="Issue #1721" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL, exit 0.

## 6. Files changed

* `lib/reqmgrsystems/reqMgrSystemCommands.class.php` — null guard in `edit()`.
* `CHANGELOG` — one dated 2.0.1 line.
* `tmp/TLU_Test_Cases.md` — regression suite (git-ignored, appended).

No i18n bundle, no template, no BFF file, no DB change.

## 7. How to re-test

```bash
php -l lib/reqmgrsystems/reqMgrSystemCommands.class.php
# login, then:
curl -s -D - -o /dev/null -b c.txt \
  "http://localhost:8082/lib/reqmgrsystems/reqMgrSystemEdit.php?doAction=edit&id=99999"
# -> HTTP/1.1 302 Found -> .../reqMgrSystemEdit.html?id=99999
curl -s -o /dev/null -w '%{http_code}\n' -b c.txt \
  "http://localhost:8082/api/reqmgrsystemedit/index.php?action=init&id=99999"
# -> 404
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "SELECT count(*) total, sum(log_level<4) warn_or_err FROM events;"
# -> 0 Error/Warning rows
```

Browser: log in `admin/admin`, open
`gui/templates/reqmgrsystems/reqMgrSystemEdit.html?id=99999` → localized
“Requirement management system not found”; Event Viewer stays clean.
