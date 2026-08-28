# Bugfix — Issue #613: chosen.jquery.js loaded before jQuery in frmInner.tpl / workbench.tpl

**Date:** 2026-08-28 · **Branch:** `fix/issue-613` · **Commits:** `dbd80bdde` (fix), `4775da557` (test)

## Summary

Every load of `lib/general/frmWorkArea.php` (the workarea inner frame that hosts the
tree + work frame iframes) fired **two uncaught JavaScript errors** in the frame document:

```
Uncaught ReferenceError: jQuery is not defined        (chosen.jquery.js:622:3)
Uncaught Error: Bootstrap's JavaScript requires jQuery (bootstrap.min.js:6:37)
```

The template `gui/templates/dashio/frmInner.tpl` loaded the chosen jQuery plugin — and
bootstrap.min.js — in a document that **never loaded jQuery at all**. The child iframes
worked because they load their own jQuery, so the bug was purely cosmetic/robustness:
console noise on every workarea load plus a guaranteed failure for any future inline
jQuery use on that page.

## Symptom

- Open any legacy workarea feature (`frmWorkArea.php?feature=searchTc`,
  `feature=printTestSpec`, `executeTest`, `planAddTC`, `showMetrics`, `reqSpecMgmt`, …).
- DevTools console of the `frmWorkArea.php` document shows exactly the two errors above.
- Network panel: `chosen.jquery.js` and `bootstrap.min.js` load with **no** jQuery script
  tag present in that document (jQuery is only fetched by the child iframes).

## Root Cause

**Chain:**
1. `lib/general/frmWorkArea.php:171` renders the frame page with
   `$smarty->display('frmInner.tpl')` (dashio template dir, `tlsmarty.inc.php:101`).
2. `gui/templates/dashio/frmInner.tpl:20` emitted the `chosen.jquery.js` script tag —
   a jQuery plugin whose IIFE dereferences `jQuery` immediately (`chosen.jquery.js:622`),
   throwing `ReferenceError: jQuery is not defined`.
3. `gui/templates/dashio/frmInner.tpl:22` `{include file="bootstrap.inc.tpl"}` emits
   `bootstrap.min.js`, whose loader throws
   `Bootstrap's JavaScript requires jQuery` at runtime (`bootstrap.min.js:6`).
4. The frmInner document never loads jQuery itself: the only jQuery script
   (`TL_JQUERY` = `third_party/jquery/jquery-2.2.4.min.js`, defined in `config.inc.php:2161`)
   was loaded by the *child* tree/work frames, not by the parent.

**Correction to the issue body's premise:** the report assumed `bootstrap.inc.tpl` is
"the include that loads jQuery". That is not the case in either
`gui/templates/dashio/include/bootstrap.inc.tpl` or the `tl-classic` variant — it only
loads bootstrap CSS + `bootstrap.min.js`. Consequently:
- `frmInner.tpl` was the only genuinely broken site (chosen *and* bootstrap both ran
  with jQuery undefined).
- `workbench.tpl` already loaded `TL_JQUERY` before `chosen.jquery.js`, so its order was
  already correct; it is also orphaned (no PHP references). No change was needed there.

**Blast radius:** every legacy feature opened through `frmWorkArea.php?feature=…`.
Grep of `chosen` in dashio `.tpl`: only `frmInner.tpl:20` and `workbench.tpl:24`; only
`frmWorkArea.php:171` renders `frmInner.tpl`.

## Fix

`gui/templates/dashio/frmInner.tpl` — inserted the standard `TL_JQUERY` script tag
**before** the `chosen.jquery.js` include, so the head order becomes
**jQuery → chosen → bootstrap** (the same order the legacy `tl-classic` head uses):

```
+<script type="text/javascript" src="{$basehref}{$smarty.const.TL_JQUERY}"></script>
 <script type="text/javascript" src="{$basehref}third_party/chosen/chosen.jquery.js"></script>
 {include file="bootstrap.inc.tpl"}
```

**Approach:** minimal +1 line. Reuses the already-defined `TL_JQUERY` constant, keeps the
exact template structure, and satisfies both plugins' jQuery requirement. The stale
compiled template `gui/templates_c/*frmInner*.php` was deleted so Smarty regenerates it.

## Files Changed

- `gui/templates/dashio/frmInner.tpl` — +1 (jQuery tag before chosen/bootstrap).
- `tmp/TLU_Test_Cases.md` — regression suite 613 (613.1–613.5, all PASS).

## Verification

- `frmWorkArea.php?feature=searchTc`: console **0 uncaught errors** (was 2); parent frame
  fetches `jquery-2.2.4.min.js` [200] → `chosen.jquery.js` [200] → `bootstrap.min.js` [200].
- Frame probe: `jQuery.fn.jquery === '2.2.4'`, `typeof jQuery.fn.chosen === 'function'`
  (plugin registered in the parent frame).
- `frmWorkArea.php?feature=printTestSpec`: 0 uncaught errors.
- Main dashboard reload: no new errors.
- `events` table: no new ERROR/WARNING rows (only `audit_login_succeeded`, log_level=16).
- Note: `tcSearchForm.php` [500] seen during repro is pre-existing and unrelated
  (`Error Processing Request - Invalid Test project id`, `tcSearchForm.php:45`, fires with
  `tproject_id=0`).

## Regression Matrix

| Case | Result |
|------|--------|
| `frmWorkArea.php?feature=searchTc` — 0 uncaught JS errors; correct script order | ✅ |
| `frmWorkArea.php?feature=printTestSpec` — 0 uncaught JS errors | ✅ |
| Main dashboard reload — no new errors | ✅ |
| Chosen plugin registered in frame (`jQuery.fn.chosen`) | ✅ |
| Event Viewer / `events` — no new Error/Warning entries | ✅ |