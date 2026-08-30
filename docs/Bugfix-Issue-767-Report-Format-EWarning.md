# Bugfix: Issue #767 — E_WARNING "Undefined array key <format>" on unsupported report format

## Summary

Requesting a General Report (e.g. `resultsGeneral.php`) with one of the
formats the UI never offers (ODT=1, ODS=2, PDF=5) logged an
`E_WARNING Undefined array key <n>` into the Event Viewer on **every** request,
and the download header was degraded to a bare filename with no extension
(`Content-Disposition: attachment; filename=-2026-08-30.`). Only hand-crafted
URLs reach it, but the warning noise accumulates in the Event Viewer and the
download filename loses its extension.

## Root Cause

Three links in a chain:

1. `lib/results/resultsGeneral.php:149` calls `displayReport()` with the raw
   `format` GET parameter (validated only as an integer,
   `lib/results/displayMgr.php:22`).
2. `displayReport()` (`lib/results/displayMgr.php:152-161`) explicitly routes
   `FORMAT_ODT / FORMAT_ODS / FORMAT_XLS / FORMAT_MSWORD / FORMAT_PDF` into
   `flushHttpHeader()` — so formats 1, 2 and 5 genuinely reach the download
   path even though `$tlCfg->reports_formats` (cfg/reports.cfg.php:31-33) only
   exposes HTML / MSWORD / MAIL in the UI.
3. `flushHttpHeader()` builds the filename with
   `$file_extensions[$format]` (`lib/results/displayMgr.php:232`), and
   `$tlCfg->reports_file_extension` (cfg/reports.cfg.php:42-44) only defines
   keys `0 → html`, `3 → xls`, `4 → doc`. Any other numeric key is an
   **undefined array key** on PHP 8, which the project's own error handler
   (`watchPHPErrors()` in `lib/functions/logger.class.php:1407`, registered at
   line 1483) turns into a new `events` row with `log_level=2`. The undefined
   value also renders an empty extension into the `Content-Disposition` header.

## Fix

`lib/results/displayMgr.php:232` — defensive lookup with the same fallback the
content-type already uses:

```php
$extension = $file_extensions[$format] ?? 'html';
$filename .= $kind_acronym . '-' . date('Y-m-d') . '.' . $extension;
```

## Why This Approach

- **Minimal**: one line changed, no config/DB/template impact.
- **Consistent**: `$reports_applications[$format]` on the next line already
  degrades to `text/html` via `isset()` when the format is unknown
  (`lib/results/displayMgr.php:236`); the `.html` extension fallback now
  matches that content type.
- **Alternatives rejected**: adding keys `1/2/5` to
  `reports_file_extension` would imply "supported output" for formats that
  have no real exporter behind them and would silently change the delivered
  file type; an `array_key_exists()` guard is functionally identical to `??`
  but more verbose.

## Files Changed

- `lib/results/displayMgr.php` — 1 file, +2/−1 (line 232).

## Commit

`f14ec9974` — `fix(results): guard report extension lookup in flushHttpHeader (Refs #767)`

## Regression Matrix

| Format | HTTP | Content-Type | Content-Disposition | Events |
|---|---|---|---|---|
| 0 (HTML) | 200 | text/html | — (unchanged) | 0 |
| 1 (ODT) | 200 | text/html | `filename=-<date>.html` (fallback) | 0 |
| 2 (ODS) | 200 | text/html | `filename=-<date>.html` (fallback) | 0 |
| 3 (XLS) | 200 | vnd.ms-excel | `filename=-<date>.xls` (unchanged) | 0 |
| 4 (MSWORD) | 200 | vnd.ms-word | `filename=-<date>.doc` (unchanged) | 0 |
| 5 (PDF) | 200 | text/html | `filename=-<date>.html` (fallback) | 0 |
| 6 (MAIL) | 200 | text/html (mail path) | — (unchanged) | 0 |

Verified 2026-08-30: full format sweep 0-6 produced **zero** new `events`
rows; formats 0/3/4 byte-for-byte unchanged.