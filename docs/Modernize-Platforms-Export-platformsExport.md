# Modernize-Platforms-Export-platformsExport (#1583)

The legacy **Export Platforms** dialog was replaced by a standalone Dashio
HTML/JavaScript screen backed by a session-authenticated PHP BFF.

## Deliverables

- `gui/templates/platforms/platformsExport.html` provides project context,
  platform count, XML format, filename validation, Back/Cancel/Refresh actions,
  documentation link, locale switcher, loading state, and explicit empty,
  authentication, permission, and project error states.
- `api/platformsexport/index.php` exposes `init` and `download` actions,
  validates the TestLink session and Test Project, checks `platform_view` or
  `platform_management` access, safely sanitizes download filenames, and returns
  the same TestLink XML fields as the legacy exporter.
- `gui/templates/platforms/platformsView.html` now launches the standalone
  screen and retains the project and plan context.
- `lib/functions/common.php` registers the `platformsExport` action.
- All `pexp.*` labels are present in all 10 client-side i18n bundles.

## Verification

| Scenario | Result |
|---|---|
| Project with two platforms | Export launcher loads and reports `2 defined` |
| Empty filename | Browser validation blocks export |
| Unsafe filename | `a/b name <regression>.xml` downloads as `a_bname_regression_.xml` |
| Populated XML | Valid XML; both platforms and five legacy platform fields present |
| Empty project | Warning shown; valid XML with zero platforms downloaded |
| Romanian locale | Screen labels render in Romanian |
| Invalid project | Structured error and Back action shown |
| Anonymous session | BFF returns 401; session-expired state shown |
| Insufficient rights | BFF returns 403; permission-denied state shown |

Browser verification used disposable Test Projects `PEXPR` and `PEMPTY`.
No unexpected JavaScript exceptions or new PHP Error/Warning output were
observed.

## Test suite

Suite **1583 — Platforms Export standalone screen and BFF** was added to
`tmp/TLU_Test_Cases.md`: **9/9 PASS**.

## Files

- `api/platformsexport/index.php` — export initialization and XML download BFF
- `gui/templates/platforms/platformsExport.html` — standalone Dashio screen
- `gui/templates/platforms/platformsView.html` — live launcher wiring
- `lib/functions/common.php` — shared action registration
- `gui/templates/i18n/*.json` — localized `pexp.*` keys
- `docs/screenshots/issue-1583-platformsexport-*.png` — browser evidence

Refs #1583.
