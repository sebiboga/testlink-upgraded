# Bugfix: #1739 — legacy `reqEdit.php` refuses unrenderable `doAction` with a 302 and no Event Viewer noise

**Issue:** #1739 — `lib/requirements/reqEdit.php:41` still used
`method_exists($commandMgr, $pFn)` as the dispatch whitelist, the exact defect #1736 had just
removed from the sibling `lib/requirements/reqSpecEdit.php`. `method_exists()` is not a whitelist of
requestable GUI actions: it also matches the internal helpers of `reqCommands`, and the dispatch
called whatever it matched with a **fixed 2-argument** signature. One unrecognised `doAction`
therefore had several outcomes:

- unknown action (`xyz`, bare URL) → **HTTP 200**, 0 bytes, **4 `E_WARNING` rows**
  (`reqEdit.php:160,172,173`, "Attempt to read property "suggest_revision"/"prompt_for_log" on null");
- `simpleCompare` (a helper that requires 4 arguments, `reqCommands.class.php:804`) →
  `ArgumentCountError` → **HTTP 500**, 0 bytes, **0 log rows** (worse: nothing to grep);
- `initGuiBean` / `initGuiObjForAttachmentOperations` / `setAuditContext` → not a renderable bean
  → blank 200;
- `?doAction[]=x` → an array from the query string → `trim(array)` in the shared input layer.

**Root cause:** `reqEdit.php:41` used `method_exists($commandMgr,$pFn)` as a dispatch whitelist
(it is not one), and `reqEdit.php:42` invoked the matched method with a fixed `($args,$_REQUEST)`
signature, so arity/visibility could not match by construction. The array case is handled one level
below by #1738, but the controller still must refuse it, not dispatch it.

**Fix:** three changes in `lib/requirements/reqEdit.php` (mirroring #1736 exactly):

1. `reqEditDropShapedAction()` — unset a non-scalar `$_REQUEST['doAction']` before `R_PARAMS()`
   (local shim; does not change the shared input layer).
2. `reqEditActionWhitelist()` — the exact 24-case list of `renderGui()`'s GUI-rendering switch
   (`reqEdit.php:198-221`), consulted via
   `is_string($pFn) && $pFn !== '' && isset(...)`. Only whitelisted names are invoked, so arity and
   visibility match by construction.
3. `reqEditRefuseUnknownAction()` — logs one `tLog(..., 'INFO')` row with a **fixed literal**
   (the attacker-controlled `doAction` is deliberately NOT interpolated) and 302s to
   `gui/templates/requirements/reqSpecMgmt.html` with `tproject_id` carried forward. INFO does not
   persist below WARNING, so crafted URLs cannot write Error/Warning rows into the Event Viewer.

**Measured results:** 18/18 regression cases PASS. Pre-fix (reproduced): bare URL → 200 / 0 B + 4
`E_WARNING`; `simpleCompare` → 500 / 0 B. Post-fix: bare / `xyz` / `simpleCompare` / `initGuiBean` /
`initGuiObjForAttachmentOperations` / `setAuditContext` / `doAction[]=x` / `doAction[0]=x` → **302,
0-byte body**, correct `Location`, and **0** new `events` rows (measured base 30 → 30). Whitelisted
actions still dispatch: `doAction=create&req_spec_id=1` → 200 / 20517 B, `edit` → 200 / 1536 B,
`reorder` → 200 / 5843 B, `deleteFile` → 200 / 2109 B legacy render. `grep -rn
'method_exists($commandMgr' lib/` → 0 live occurrences; `reqEdit.php:41` was the last one.

**Notes:** No new user-facing string, so no i18n bundle was touched. The `deleteFile` command emits
its own pre-existing legacy `E_WARNING`s (present before this fix, unrelated to dispatch). The
`planMilestonesEdit.php:30` sibling was already retired by #1726/#1894, so this closed the issue's
blast radius of two.
