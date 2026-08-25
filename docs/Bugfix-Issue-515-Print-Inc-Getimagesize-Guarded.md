# Issue 515 — print.inc.php `getimagesize()` HTTP fail + "Cannot use bool as array" (old line 694) during document generation

**Issue:** [#515](https://github.com/sebiboga/testlink-upgraded/issues/515)
**Branch:** `fix/issue-515` · **Fix already landed in:** `e85ee1db8`, `ab5833b67`
**Status:** VERIFIED-FIXED & CLOSED (2026-08-25) — no new code needed; ticket closed with verification evidence

## Symptom

Reported (2026-08-19): ~36 warnings per generated PDF document under PHP 8:

- `E_WARNING Cannot use bool as array` at `print.inc.php:694`
- `getimagesize()` called with HTTP URLs that fail on the built-in dev server (TCPDF flow)

## Root cause chain & fix approach (the HOW)

1. **HTTP self-fetch of the company logo.** `renderFirstPage()` used to call
   `getimagesize($_SESSION['basehref'] . TL_THEME_IMG_DIR . $docCfg->company_logo)` — an HTTP URL
   pointing back at the same server. On single-worker runtimes (PHP built-in server) that request
   deadlocks/fails and `getimagesize()` returns `false`.
   Fix `e85ee1db8` ("read company logo size from local file instead of HTTP self-fetch", marked
   `fix #570` in code) reads from the LOCAL filesystem instead:
   `@getimagesize(TL_ABS_PATH . TL_THEME_IMG_DIR . $docCfg->company_logo)`
   and only uses the dimensions when `$imgData !== false`, otherwise emits the `<img>` without
   width/height attributes (`lib/functions/print.inc.php:700-713`). This also removes the
   bool-as-array access at old line ~694, because `$imgData[0]`/`list()` is now unreachable when
   `false`.

2. **Attachment images.** All six attachment-rendering sites in `print.inc.php`
   (lines 292, 1311, 1537, 1650, 1812 + loop-variable fix at 1647-1649) were hardened by
   `ab5833b67` ("guard all attachment getimagesize() calls against false (PHP8)", marked
   `fix #574`): every call now passes a local path (`$repoDir . $fitem['file_path']`) and every
   result is checked with `if ($imgData !== false)` before any array access.

Why this approach: reading image size from the filesystem removes the network round-trip entirely
(no deadlock class at all), and false-guards make dimension rendering best-effort — a missing or
corrupt attachment degrades to an un-sized `<img>` instead of warning spam. Alternatives rejected:
wrapping calls in `error_reporting()` suppression (hides real errors), pre-downloading HTTP URLs
to temp files (re-introduces the self-fetch problem).

## Verification performed (2026-08-25)

Environment: fresh DB (baseline 14 events), app http://localhost:8082, admin/admin, headless Chrome,
fixtures: project 10 "Issue503 Demo Project" / plan 20 "Smoke Plan" (+build v1.0), project options
stored as the same PHP-serialized object the application itself writes.

| Check | Result |
|---|---|
| `GET /lib/results/printDocument.php?type=testreport&docTestPlanId=20&id=10&level=testplan&format=0` | HTTP 200, document renders fully |
| Logo `<img>` from print.inc.php:691-713 | complete, naturalWidth×Height = 231×56 (local getimagesize succeeded) |
| Server log grep for `print.inc.php|getimagesize|Cannot use bool` | 0 matches |
| Event Viewer delta after generation | 0 new events (max id unchanged) |
| Static audit: all 7 `getimagesize(` sites local-path + false-guarded | PASS |

Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #515" (**6/6 PASS**, incl. one
out-of-scope control case documenting a malformed deep-link crash filed separately as #683).

## Investigation notes (transparency)

The first repro attempt used an incomplete deep link (no `id` param) and a bad fixture
(`options='{}'`, not PHP-serialized). That produced 4 events (2× unserialize
testproject.class.php:239; 1× `testPriorityEnabled` on false printDocument.php:491; 1× SQL 1064
empty `parent_id` in tree.class.php:917). All four artifacts disappeared after fixing the request
and fixture — none was the #515 defect. The missing-`id` crash remains reachable via stale
bookmarks and was filed as [#683](https://github.com/sebiboga/testlink-upgraded/issues/683).
