# Bugfix — Issue #1916: tcEdit.php post-action render emitted 18 E_WARNING rows per request (tcViewViewer.inc.tpl / tcbody.inc.tpl unset vars)

**Issue:** https://github.com/sebiboga/testlink-upgraded/issues/1916
**Files changed:**
- `lib/testcases/testcaseCommands.class.php` (grant list `g2c`)
- `lib/testcases/tcEdit.php` (grant list `grant2check`)
- `lib/functions/testcase.class.php` (other-version `ghost_preconditions` + the other-version CF loop `switch`)

## Symptom

Immediately after #1860 unblocked the `tcEdit.php` version lifecycle render (it used to die
with a Smarty compile 500), each of these three URLs wrote **E_WARNING rows to the Event
Viewer on every request**:

```
.../tcEdit.php?deactivate_this_tcversion=1&tcase_id=T&tcversion_id=V   -> 1 E_WARNING
.../tcEdit.php?activate_this_tcversion=1&tcase_id=T&tcversion_id=V     -> (same path, 1)
.../tcEdit.php?do_create_new_version=1&tcase_id=T&tcversion_id=V       -> 16 E_WARNINGs
```

Three distinct signatures, all in the **legacy test-case viewer partials**:

1. `Undefined property: stdClass::$delete_frozen_tcversion` — `tcViewViewer.inc.tpl` src :238/:286 (compiled `:293`/`:340`).
2. `Undefined array key "ghost_preconditions"` — `tcbody.inc.tpl` src :73 (compiled `:99`).
3. `Trying to access array offset on null` ×6 — `tcbody.inc.tpl` compiled `:31,54,71,86,117,127`.

The page still returned HTTP 200 and rendered correctly; the only impact was Event Viewer
pollution (the issue's `priority: minor`).

## Approach (root cause → method → why)

These partials are reached only through the **legacy** path
`testcase::show()` → `tcView.tpl` → `tcViewViewer.inc.tpl` → `tcbody.inc.tpl`. The normal
viewing path (`archiveData.php?edit=testcase`) now 302-redirects to the modern
`gui/templates/testcases/tcEdit.html`, so `tcView.tpl` is exercised *only* by the tcEdit
post-action renders (activate / deactivate / create-new-version / do_copy) — which is why
#1860 unmasked these warnings exactly there.

### RC1 — `$args_can_do->delete_frozen_tcversion` was never populated

`testcase::initShowGui()` (`lib/functions/testcase.class.php:7511-7520`) builds
`$goo->can_do = getShowViewerActions(...)` and then copies `delete_frozen_tcversion` onto it
**only** `if (property_exists($grantsObj, 'delete_frozen_tcversion'))`. `getShowViewerActions()`
(`testcase.class.php:4957-4989`) does **not** define that member in its default map.

The two grant builders used by the tcEdit post-action path therefore left it undefined:
- `testcaseCommands::__construct()` `$g2c` list — `lib/testcases/testcaseCommands.class.php:43-46`
- `tcEdit::initializeGui()` `$grant2check` list — `lib/testcases/tcEdit.php:657-661`

The reference builder `testcase::getStandardGrantsNames()` (`testcase.class.php:156-171`)
*does* include `delete_frozen_tcversion` (line 169) — that is what `testcase::show()` builds
when `$grantSet` is `null`, i.e. the "healthy" tcView controller. The tcEdit path passes its
own incomplete grants object, so the `property_exists` branch never fired and
`tcViewViewer.inc.tpl:238/:286` read an undefined property.

**Why populate the grants instead of guarding the template with `{if isset(...)}`:** the ticket
listed both options. Populating restores the *reference* (tcView) behaviour rather than hiding
the bug for the next caller. It is **behaviour-neutral**: admin (users.role_id=8) holds
`rights.id=47 testcase_freeze` but **not** `rights.id=56 delete_frozen_tcversion` (measured via
`role_rights`), so the member resolves to `null`, and `null == "yes"` is `false` — identical to
the previous unset-`null` in every `== "yes"` comparison. Same truth value ⇒ no button
appears/disappears; only the warning disappears. Guarding was rejected because it leaves the
grants gap latent.

### RC2 — other versions lacked `ghost_preconditions`

`testcase::show()` sets `ghost_preconditions` **only for the current version**
(`testcase.class.php:1087-1088`). The other-versions loop (`:1208-1210`) set `ghost` but not
`ghost_preconditions`. `tcView.tpl:290` passes an other-version row as `args_testcase`, so
`tcbody.inc.tpl:73` (`{$tco.ghost_preconditions}`) hit `Undefined array key`.

### RC3 — `$gui->cf_other_versions` stayed `null` (the 6 array-offset warnings)

The current-version CF loop (`testcase.class.php:1182-1192`) correctly does
`foreach($cfPlaces as $cfpKey => ...) switch($cfpKey)`. The **other-versions** loop
(`:1213-1219`) does `foreach($cfPlaces as $locKey => ...) switch ($cfpKey)` — `$cfpKey` is the
**stale** variable left over from the finished outer loop (value `hide_because_is_used_as_variable`,
confirmed by instrumenting `testcase::show()` with a temp dump). Every iteration therefore hit
that `case`'s `break;` and `$gui->cf_other_versions[...]` was never assigned → `null`.
`tcView.tpl:232-235` then passed `args_cf=null` → `tcbody.inc.tpl` read `inc_tcbody_cf[...]` on
`null` → 6 warnings per other-version row.

## The fix

```diff
# lib/testcases/testcaseCommands.class.php  (g2c)
+                 'testproject_edit_executed_testcases',
+                 'delete_frozen_tcversion');

# lib/testcases/tcEdit.php  (grant2check)
+                       'testproject_delete_executed_testcases',
+                       'delete_frozen_tcversion');

# lib/functions/testcase.class.php  (other-versions loop)
             $ref[$qdx]['ghost'] = sprintf(self::GHOSTMASK, ...);
+            $ref[$qdx]['ghost_preconditions'] =
+              sprintf(self::GHOSTPRECONDITIONSMASK, $tcvSet[0]['tc_external_id'], $ref[$qdx]['version']);

             foreach($cfPlaces as $locKey => $locFilter) {
-              switch ($cfpKey) {
+              switch ($locKey) {
```

## Verification

Fresh fixture (`php tmp/fixtures_1859.php` → tcase 44 / tcversion 45), logged in as
`admin`/`admin`, four post-action renders (`deactivate`, `activate`, `create_new_version` ×2 —
the two `create_new_version` calls produced two *other-version* viewer rows, the exact RC2/RC3
case):

| Metric | Pre-fix | Post-fix |
|---|---|---|
| `COUNT(events WHERE log_level=2)` (E_WARNING) | 18 | **0** |
| `COUNT(events WHERE log_level IN (1,2,4,8))` | ≥18 | **0** |
| HTTP status of all three URLs | 200 | 200 |
| `event log_level` histogram | warnings + audit | `{16: 2}` (audit only) |

Rendered HTML: `bytes=112991`, test-case name ×16, `new_version` ×3, `delete_tc_version` ×2
buttons intact. Headless-Chrome console: only 1 pre-existing `Deprecated feature used` notice,
**no JS errors**. Regression gate
(`TLU_REQUIRE_SUITE="Issue #1916" bash ai/verify_test_suites.sh`): **7 PASS / 0 FAIL**.
