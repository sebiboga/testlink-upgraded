# Bugfix: Issue #568 — Report Generation Dead in Standalone Windows

## Summary

Report generation from the tree view (double-click on folder/root) was completely broken on all report types. The app appeared frozen — no request was sent, no error was shown. This fix restores report generation for all document types: Test Plan Report, Test Report, Test Report on build, Test Specification, and Requirement Specification.

## Root Cause

Every document-generation handler in `gui/javascript/testlink_library.js` (21 call sites total) navigated via:

```js
parent.workframe.location = fRoot + menuUrl + "?...";
```

The `workframe` iframe only exists inside the framed Dashio shells (`frmInner.tpl`, `workframe.tpl`, `workbench.tpl`). But `resultsNavigator.php` opens `printDocOptions.php` as a **standalone tab/window** — it has no parent frameset, so `parent.workframe` is `undefined`.

Every double-click threw:
```
TypeError: Cannot set properties of undefined (setting 'location')
```

The exception aborted the handler silently — no request was ever built or sent, the server stayed idle at 0% CPU, and the user perceived the app as frozen.

## Fix

Added two layout-agnostic helpers at the top of `gui/javascript/testlink_library.js`:

```js
function TL_workframe() {
  try {
    if (window.parent && window.parent !== window) {
      var wf = window.parent.workframe || window.parent.frames['workframe'];
      if (wf) { return wf; }
    }
  } catch(e) {}
  return null; // standalone window
}

function TL_doc_nav(url) {
  var wf = TL_workframe();
  if (wf) { wf.location = url; }        // classic framed behaviour
  else   { window.open(url, '_blank'); } // standalone: new tab
}
```

Replaced all 21 `parent.workframe.location = X` calls with `TL_doc_nav(X)`.

## Why This Approach

- **Minimal change**: only `testlink_library.js` touched, no template/PHP changes
- **Backward compatible**: in framed shells, `TL_workframe()` resolves the frame as before — existing behaviour is preserved
- **Standalone fallback**: `window.open(url, '_blank')` opens the report in a new tab while keeping the tree/options page alive
- **Alternative rejected**: restoring a frame context would require modifying `printDocOptions.php` and multiple templates — disproportionate risk for this bug

## Files Changed

- `gui/javascript/testlink_library.js` — +66/−22 (2 helpers + 21 call sites rewritten)

## Commit

`102ccd687` — `fix(reports): doc-generation handlers dead in standalone windows - route via TL_workframe()/TL_doc_nav(), fallback opens report in new tab`

## Regression Matrix

| Report Type | Tree Root | Result |
|---|---|---|
| Test Plan Report (testplan) | `/ Smoke Plan (1)` | PASS — new tab, report rendered |
| Test Report (testreport) | `/ Smoke Plan (1)` | PASS — new tab, report rendered |
| Test Report on build (testreport_onbuild) | `/ Smoke Plan (1)` | PASS — new tab, report rendered |
| Test Specification (testspec) | `(1)` | PASS — new tab, report rendered |
| Requirement Specification (reqspec) | `(1)` | PASS — new tab opened (JS fix works); backend had pre-existing DB error unrelated to this fix |
| Event Viewer | — | PASS — zero new TypeError events |
| Console errors | — | PASS — zero `TypeError: Cannot set properties of undefined (setting 'location')` across all types |
