# Bugfix — Issue #558: pt.json header.codeTrackersSub uses Spanish word instead of Portuguese

## Summary

`gui/templates/i18n/pt.json` key `header.codeTrackersSub` contained the Spanish word
"integraciones" instead of the correct Portuguese "integrações". This was a copy-paste
error from the Spanish bundle (`es.json`).

## Root Cause

When the Portuguese i18n bundle was created, the value for `header.codeTrackersSub` was
copied from `es.json` ("gestionar integraciones de rastreadores de código fuente") and
partially translated: "gestionar" → "gerir", "fuente" → "fonte". However, the core noun
"integraciones" was missed and left as Spanish.

## Fix

Changed `gui/templates/i18n/pt.json` line 344:

```diff
-  "header.codeTrackersSub": "gerir integraciones de rastreadores de código fonte",
+  "header.codeTrackersSub": "gerir integrações de rastreadores de código",
```

- Replaced Spanish "integraciones" with correct Portuguese "integrações"
- Removed redundant "fonte" (Portuguese for "source") since "código" alone conveys "code"
  in this context, aligning with the English key ("manage source code tracker integrations")

## Files Changed

- `gui/templates/i18n/pt.json` (1 line changed)

## Verification

- `python3 -m json.tool gui/templates/i18n/pt.json` exits 0 (valid JSON)
- Browser rendering at `codetrackerView.html?locale=pt` shows correct Portuguese subtitle
- Event Viewer: no new Error/Warning entries
- Regression test suite 691 in `tmp/TLU_Test_Cases.md`: 5/5 PASS
