# Bugfix / Enhancement — Issues #1340/#1341/#1329: projectInfoView 'Generate Test Spec' buttons routed to modern printTestDoc (no more legacy lib/results/printDocument.php, no header E_WARNING)

## Problem

1. **Gap #1329/#1340 (roaming reference):** the modern `projectInfoView.html`
   screen's two "Generate Test Spec" buttons still pointed at the legacy
   generator `lib/results/printDocument.php` — the last `lib/*.php` reference
   left inside a modernized screen.
2. **Bug #1341:** when printing test-spec as **MS Word** (`format=4`), a new
   E_WARNING `Cannot modify header information - headers already sent` was
   logged to `events` on every render (5 rows per print), originating from
   `api/testcasesprint/index.php` lines 500-504.

## Root Cause (#1341)

The modern BFF `api/testcasesprint/index.php` handles `action=print` by
_including_ the legacy `lib/results/printDocument.php`. For MS Word output that
legacy code calls `flushHttpHeader(FORMAT_MSWORD)`, which performs
`header('Content-Type: ...')` **plus `flush()`** — i.e. it commits the HTTP
response headers/output before the BFF's own JSON `header_remove()` /
`header('Content-Type: application/json')` block runs. PHP then refuses to
re-send headers → `E_WARNING Cannot modify header information - headers already
sent` at the BFF's lines 500-504, once per `header()` call (hence 5 rows).

HTML output (`format=0`) never triggered it because the legacy printer writes the
document body through its own template, so the BFF's JSON-header reset is
skipped/short-circuited in that path.

**Blast radius:** BFF-only — the modern print tab (`printTestDoc.html`) already
used it; only Word downloads polluted `events`.

## Fix (#1341)

Commit `1de4b8070` — guard the BFF's header-reset block with `headers_sent()`:

```php
if (!headers_sent()) {
  header_remove('Content-Type');
  header_remove('Content-Disposition');
  header('Content-Type: application/json; charset=utf-8');
}
```

When the legacy `flush()` already committed the Word headers, the reset is
skipped cleanly — no warning, and the LOG format/COD format paths (which do set
their own `Content-Type` first) behave identically.

## Gap Fix (#1329/#1340)

Commit `e283f8dc2` — `gui/templates/projects/projectInfoView.html` lines 61-62:

```html
specBase = '/gui/templates/testcases/printTestDoc.html?action=print&type=testspec&level=testproject&allOptionsOn=1&id=<PROJECT_ID>&tproject_id=<PROJECT_ID>';
// HTML button:  specBase + '&format=0'
// Word button:  specBase + '&format=4'
```

Now both buttons open the modern Dashio `printTestDoc.html` screen (sets
`action=print`; `format!=0` additionally triggers the `.doc` blob download).
`grep -c printDocument` in `projectInfoView.html` → **0**. The `mgt_modify_tc`
UI gate is unchanged (buttons remain hidden without the right; the BFF also
enforces `testplan_metrics` → HTTP 403 for unprivileged direct calls). No new
user-facing strings, so the existing `piv.genSpecHtml` / `piv.genSpecWord`
bundle keys (flat dot-notation, present in all 10 locales) are reused.

## Files Changed

- `gui/templates/projects/projectInfoView.html` — `specBase` → modern `printTestDoc.html`.
- `api/testcasesprint/index.php` — `headers_sent()` guard around the JSON header-reset block.
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issues #1333/#1329/#1340/#1341", 17/17 PASS.

## Verification

All checks on the fresh-import CI box, admin session, fixture `php tmp/fixtures_982.php` (project id=1, suites Alpha/Beta, 4 test cases):

| Scenario | Result |
|---|---|
| HTML button click → `printTestDoc.html?...format=0` | document renders in-page (full spec: suites + 4 TCs + steps) |
| Word button click → `printTestDoc.html?...format=4` | document renders + `.doc` blob download; **0** new E_WARNING (`headers already sent`) rows after `events` cleared |
| Network fan-out | only `/api/testcasesprint/index.php?action=print...` (HTTP 200); no `/lib/results/printDocument.php` request |
| No-rights user (`norights`, role 3, no project role) | both buttons hidden; direct BFF call → HTTP 403 `No permission` |
| `events` scan after full pass | only INFO/AUDIT; `log_level IN (2,3)` = 0 |
| Browser console on both print tabs | no JS errors |

Screenshots: `docs/screenshots/issue-1340-project-info-buttons.png`, `docs/screenshots/issue-1340-print-testdoc-format0.png`.

Address: `Refs #1340`, `Fixes #1341`. Closes the #1329 roaming-reference list item.