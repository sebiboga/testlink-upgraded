# Issue 1535 — BFF POST/PUT /api/roles: nonexistent rightID (e.g. 99999) creates role with dangling right reference

**Issue:** [#1535](https://github.com/sebiboga/testlink-upgraded/issues/1535)
**Branch:** `fix/issue-1535`
**Status:** VERIFIED-FIXED

## Symptom

`POST /api/roles/index.php` with `rightIDs:[99999]` (a right id that does not
exist in the `rights` table) succeeded with HTTP **200** and created a role whose
`rights` array contained `{"id":99999,"name":null}` — a dangling `role_rights`
row referencing a nonexistent right. Same defect present on `PUT /roles/{id}`.

## Repro steps

1. Log in as admin at http://localhost:8082/index.php (admin/admin).
2. From the app origin:
   ```js
   fetch('/api/roles/index.php', {method:'POST', headers:{'Content-Type':'application/json'},
     body: JSON.stringify({name:'probeX_1531', description:'x', rightIDs:[99999]})})
   ```
3. **Before fix:** HTTP 200 with
   `{"status":"ok","item":{"id":13,...,"rights":[{"id":99999,"name":null}]},"feedback_key":"role_created"}`.
4. `SELECT * FROM role_rights WHERE right_id=99999;` returns the dangling row.

**Expected:** the invalid id is ignored (legacy parity), yielding an empty rights
set → HTTP **400** `{"status":"error","message":"Role must have at least one right","messageKey":"role.error.noRights"}` (E_EMPTYROLE).

## Root cause

`tlRight::readFromDB()` (`lib/functions/tlRight.class.php:81-104`) runs
`_clean($options)` with the default `TLOBJ_O_SEARCH_BY_ID`; `_clean()`
(`:29-36`) keeps `dbID` whenever that bit is set. On a failed
`fetchFirstRow` it returns `tl::ERROR` **without touching `dbID`**, which stays
at the constructor value (`99999`). The BFF guards
`api/roles/index.php:384` (POST) and `:428` (PUT) tested
`if ($right->dbID)` — truthy even after a failed read — so the phantom object
was appended and `writeToDB()` persisted `role_rights(role, 99999)`, shown in
GET list as `"name":null`.

Legacy `lib/usermanagement/rolesEdit.php:102-103` never had this defect: it
builds `$op->role->rights` via `tlRight::getAll(..., "WHERE description IN (...)")`
— an existence-only fetch, so stale right names can never be stored.

## Fix

`api/roles/index.php` — in both the POST and PUT right loop test the **read
result** instead of the object property:

```diff
             $right = new tlRight(intval($rid));
-            $right->readFromDB($db);
-            if ($right->dbID) { $r->rights[] = $right; }
+            if ($right->readFromDB($db) >= tl::OK) { $r->rights[] = $right; }
```

- `>= tl::OK` honours both success sentinels and respects the object cache
  (a cached right reads `tl::OK` at `tlRight.class.php:83-86`).
- Fallback: nonexistent `rightID` is silently dropped (legacy parity); an
  all-invalid payload → empty `rights` → `writeToDB` returns `E_EMPTYROLE` →
  HTTP 400 `role.error.noRights`.

**Alternatives considered and rejected:**
- Comparing `$right->dbID == intval($rid)` — works but leaves the row-identity
  semantic implicit and masks real read regressions.
- An extra `in_array` existence pre-query — duplicates the lookup `readFromDB`
  already performs (extra round-trip, no benefit).

**Blast radius:** `rg "readFromDB($db)" api/` → 13 call sites; the two roles
routes were the ONLY ones testing a property afterwards instead of the return
value (all others, e.g. `api/auth/index.php:348`, already use `>= tl::OK`). No
other endpoint can mint a dangling `role_rights` row.

## Regression matrix (all executed, live)

| # | Case | Result |
|---|---|---|
| 1 | POST `rightIDs:[99999]` | **400** `role.error.noRights`, no dangling row (was 200+dangling) |
| 2 | POST `rightIDs:[99999,1]` | **200**, role holds ONLY right id 1 (99999 dropped) |
| 3 | POST `rightIDs:[1,5]`: valid payload | **200**, both rights present (no regression) |
| 4 | PUT /{id} `rightIDs:[99999]` | **400** `role.error.noRights`, role unchanged on GET |
| 5 | PUT /{id} `rightIDs:[1]` | **200** `role_updated`, role keeps only right id 1 |
| 6 | DB `role_rights` left-join | dangling count **0** after every probe |
| 7 | UI create-role modal (browser) | modal opens, save creates role + toast; row shows real right name |
| 8 | `php -l api/roles/index.php` | no syntax errors |
| 9 | Event Viewer / `events` table | no new Error/Warning (audit INFO rows only) |

## How the method was chosen

Measured repro first (curl + DB): confirmed the endpoint returned 200 and the
`role_rights` row was genuinely dangling. Root cause pinned to
`tlRight::_clean()` keeping `dbID` under `TLOBJ_O_SEARCH_BY_ID` + the property
test in the BFF. The minimal correct fix tests the return value — the same
pattern every other `api/` call site already uses — giving exact legacy parity
without touching shared infrastructure (`tlRight`, `tlDBObject`), which would
have had a much wider blast radius for no benefit on this data-integrity bug.