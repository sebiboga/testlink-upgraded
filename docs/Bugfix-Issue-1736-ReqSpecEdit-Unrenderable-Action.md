# Bugfix: #1736 — legacy `reqSpecEdit.php` refused unrenderable `doAction` with a 302 and no Event Viewer noise

**Issue:** #1736 — `lib/requirements/reqSpecEdit.php:147` logged 3 E_WARNING rows ("Attempt to
read property "askForRevision" on null" etc.) whenever the requested `doAction` was not a method of
`reqSpecCommands`, and the second `switch($renderType)`'s `default: echo 'Can not process RENDERING!!!';`
returned **200 bytes 28** body. For internal helper methods (`simpleCompare`, `process_revision`,
`initGuiObjForAttachmentOperations`, `initGuiBean`, `getReqMgrSystem`, `setAuditContext`) the code
called them with the fixed 2-argument signature — `ArgumentCountError`, `Call to private method`, or
an unsuitable return value — producing a **silent HTTP 500 with 0 bytes and 0 log rows** (worse:
nothing to grep). `?doAction[]=x` hit `trim(array)` in the input layer and also became a silent 500.

**Root cause:** `reqSpecEdit.php:37` used `method_exists($commandMgr, $pFn)` as a dispatch whitelist
(it is not one: 24 members, 18 GUI actions). The shared layer `inputparameter.class.php:295` calls
`trim()` unconditionally on `STRING_N` values, so a non-scalar `$_REQUEST['doAction']` fatals before
the controller runs.

**Fix:** three changes in `lib/requirements/reqSpecEdit.php`:

1. `reqSpecEditDropShapedAction()` — unset a non-scalar `$_REQUEST['doAction']` before `R_PARAMS()`
   (local shim, does not change the shared input layer).
2. `reqSpecEditActionWhitelist()` — the exact 18-case list of `renderGui()`'s GUI-rendering switch,
   consulted via `is_string($pFn) && $pFn !== '' && isset(...)`; only whitelisted names are invoked.
3. `reqSpecEditRefuseUnknownAction()` — logs one `tLog(..., 'INFO')` row with a **fixed literal**
   (no attacker-controlled value interpolated) and 302s to `gui/templates/requirements/reqSpecMgmt.html`
   with `tproject_id` carried forward. INFO does not persist below WARNING, so crafted URLs cannot
   write Error/Warning rows.

The `default:` branch in `renderGui()` also null-guards `$opObj` as defence in depth.

**Measured results:** 32/32 regression cases PASS (unrecognised doActions → 302 + 0 Event Viewer rows;
internal helpers → 302 + 0 rows; whitelisted actions behave identically to pre-fix; hostile `doAction`
values produce 0 rows of any level). Browser: unknown `doAction=init` lands on the Req. Spec. Management
screen, while `doAction=create` still renders the full editor form.

**Notes:** The shared `trim(array)` bug is filed as #1738; two `reqSpecCommands` warnings as #1737;
and `reqEdit.php:41` and `planMilestonesEdit.php:30` (the last two `method_exists()` dispatches) as
#1739. All measured identical on pre-fix code and were not fixed here.
