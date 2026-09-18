# Issue 1534 — reqReorder.html loads broken local font-awesome CSS path (404) — icons missing

**Issue:** [#1534](https://github.com/sebiboga/testlink-upgraded/issues/1534)
**Branch:** `fix/issue-1534`
**Status:** VERIFIED-FIXED

## Symptom

`gui/templates/requirements/reqReorder.html` (the modern Reorder Requirements
screen, Refs #1488) linked Font Awesome from the Dashio-local copy:
`/gui/templates/dashio/lib/font-awesome/css/all.min.css`. That file does not
exist in the shipped Dashio assets — the local `css/` dir only carries FA 4.x
(`font-awesome.css`, `font-awesome.min.css`) — so every page load produced an
HTTP **404** and the `class="fa …"` glyphs rendered as empty/tofu boxes.

## Repro steps

1. Log in as admin at http://localhost:8082/index.php (admin/admin).
2. Open `http://localhost:8082/gui/templates/requirements/reqReorder.html`.
3. DevTools Network shows a **404** for
   `/gui/templates/dashio/lib/font-awesome/css/all.min.css`; all FA icons
   render as empty boxes.

**Expected:** Font Awesome stylesheet loads (200) and icons render.

## Root cause

The modernized `reqReorder.html` was written (commits `aa11616dc` /
`01e1b794b`, 2026-09-13) with the Dashio-template-local FA reference instead of
the CDN URL used by the other modern screens. The local file it pointed to —
FA 6 `all.min.css` — was never shipped (only FA 4 `font-awesome.min.css`
exists under `gui/templates/dashio/lib/font-awesome/css/`), hence the 404.
This is a static copy/paste inconsistency from the original rewrite, not a
runtime regression. The Direct-Link resolver screen hit the same 404 during
#1532 and was fixed identically.

## Fix

Replace the broken `<link>` href with the CDN Font Awesome URL already used by
the other 148 modern screens (landed on the default branch in commit
`398d68115`, `gui/templates/requirements/reqReorder.html:9`):

```diff
 <link rel="stylesheet" href="/gui/templates/dashio/lib/bootstrap/css/bootstrap.min.css">
-<link rel="stylesheet" href="/gui/templates/dashio/lib/font-awesome/css/all.min.css">
+<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
```

**Alternatives considered and rejected:** shipping the local FA 6 `all.min.css`
would add a duplicated asset when the whole fleet already uses the CDN; keeping
the FA 4 local file would break glyph classes (the screen uses FA 6 style
families like `fa-grip-vertical`). CDN + the existing cross-origin fallback is
the zero-duplication, fleet-consistent option.

## Verification (measured)

Fixture: `php tmp/fixtures_1534.php` creates project `RE1534` (prefix `RS34`),
spec `RS-REORD` (id=2) and requirements RRQ-001/002/003.

| # | Check | Result |
|---|---|---|
| 1 | Pre-fix 404 premise: `curl` `/gui/templates/dashio/lib/font-awesome/css/all.min.css` | **404** (file not shipped; local dir has only FA4 css) |
| 2 | Post-fix page Network panel (headless Chrome) | CDN `all.min.css` [200], webfont `fa-solid-900.woff2` [200]; **no** request to the local path |
| 3 | Fleet scan: `grep -rln "dashio/lib/font-awesome" gui/templates/` | 0 matches — no other modern screen references the local FA copy |
| 4 | Icon rendering probe (JS) | 14 `.fa` elements, computed `font-family: "Font Awesome 6 Free"`, `inline-block` — glyphs render |
| 5 | Reorder round-trip: Down on RRQ-001 → Save | list swaps to RRQ-002, RRQ-001, RRQ-003; green "Requirements reordered successfully." |
| 6 | Persistence: re-`GET init` API | returns `["RRQ-002","RRQ-001","RRQ-003"]` |
| 7 | Console + `events` table | 0 console messages; 0 Error/Warning rows (only INFO audit rows) |

All 7 PASS. Full sequence detailed as regression suite in `tmp/TLU_Test_Cases.md`
(7/7 PASS, Refs #1534).