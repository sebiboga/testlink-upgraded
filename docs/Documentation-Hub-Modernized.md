# Documentation Hub — Modernized Screen

The **Documentation Hub** provides access to TestLink user manuals, guides, and references as PDF documents. It replaces the legacy `tools/viewer.php` PDF viewer with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Documentation
**URL:** `gui/templates/documentation/documentation.html`
**BFF API:** `api/documentation/index.php` — `GET ?action=list` (default)
**Rights:** Any logged-in user (same as legacy viewer — no rights check)
**Tracking issue:** [#764](https://github.com/sebiboga/testlink-upgraded/issues/764)
**SCREEN-COMPARE:** row 64 ✅ (Refs #1281) — gap closed: `good_test_case` (`docs/bibliographical_references/GoodTest.pdf`) and `youtrack_readme` (`docs/youtrack-readme.pdf`) were dropped from the BFF vs the legacy `tools/viewer.php` allowlist (8 entries); both restored with new `doc_good_test_case` / `doc_youtrack_readme` lang keys in all 19 `locale/*/strings.txt`. Legacy viewer kept for backward compatibility.

---

## 1. Overview

The Documentation Hub consolidates access to all TestLink documentation in one screen:

| Section | Content |
|---------|---------|
| **PDF Manuals & Guides** | 8 PDF documents with View and Download buttons |
| **Online Resources** | GitHub Wiki link |

The screen follows the same Dashio visual patterns as other modernized screens: teal header, dark toolbar, card grid layout, Bootstrap modal for PDF viewing.

---

## 2. Screen Layout

| Element | Description |
|---------|-------------|
| **Header** | Teal banner with "Documentation" title, subtitle, locale switcher |
| **Toolbar** | Refresh button, generation timestamp |
| **PDF section** | Card grid with 8 document cards (title, filename, View/Download buttons) |
| **Wiki section** | Card grid with GitHub Wiki link card |
| **Footer** | Generation timestamp |


The **View** button opens each PDF in a Bootstrap modal with an embedded viewer:


SCREEN-COMPARE #1281 — all 8 documents render (incl. restored Good Test Case + YouTrack Readme):

---

## 3. Available Documents

| Key | Title | File |
|-----|-------|------|
| testlink_user_manual | User Manual | testlink_user_manual.pdf |
| testlink_installation_manual | Installation Manual | testlink_installation_manual.pdf |
| tl_file_formats | File Formats | tl-file-formats.pdf |
| excel2testlink | Excel Import | excel2TestLink.pdf |
| fckeditor_config | FCKEditor Configuration | Configuration_of_FCKEditor_and_CKFinder.pdf |
| tl_bts_howto | Bug Tracking How-To | tl-bts-howto.pdf |
| good_test_case | Good Test Case | docs/bibliographical_references/GoodTest.pdf |
| youtrack_readme | YouTrack Readme | youtrack-readme.pdf |

Additionally, the GitHub Wiki is linked as an online resource.

---

## Verification and repair — #1275 (2026-10-05)

The two documents above were restored by `5236bfe7d` (BFF `$docs`) and `7892bb3f4`
(i18n, 19 bundles) but issue #1275 was never closed. A full re-verification pass
(13 test cases) confirmed the feature and found one regression the restoring
commit had introduced.

### Verified (13-case suite, 13/13 for this issue)

| Check | Result |
|---|---|
| BFF returns **8** docs in the legacy `tools/viewer.php:11-18` order, both restored keys present, all `exists:true` | PASS |
| `GoodTest.pdf` → `200 application/pdf 205312b` | PASS |
| `youtrack-readme.pdf` → `200 application/pdf 541169b` | PASS |
| Screen renders **8** cards; #7 *Good Test Case*, #8 *YouTrack Readme*, each with View + Download | PASS |
| View modal really paints the PDF (`embed` 743×307 px, `type=application/pdf`) | PASS |
| i18n keys present in **19/19** bundles; titles translate (`de_DE` *Guter Testfall*, `ro_RO` *Caz de testare bun*, `zh_CN` *好的测试用例*) | PASS |
| Legacy `viewer.php?file=good_test_case` → 200, `?file=youtrack_readme` → 200, `?file=bogus_key` → 404 (allowlist still enforced) | PASS |
| Console: no errors/warnings; `GET /api/documentation/index.php 200` | PASS |
| Event Viewer: only `log_level 16` (AUDIT); zero ERROR/WARNING | PASS |
| BFF without a session cookie → **401** (adding 2 docs did not weaken the guard) | PASS |

### Regression found and fixed — i18n keys silently dropped by `7892bb3f4`

That commit appended the two new keys from a **stale copy** of the bundles. A
set-difference sweep of every bundle against `7892bb3f4^` showed **8 pre-existing
keys had been deleted** in the process:

- `locale/cs_CZ/strings.txt` — 7 keys (`executed_me_and_also`,
  `remove_plat_msgbox_title`, `remove_plat_msgbox_msg`, `img_title_remove_platform`,
  `report_test_automation`, `error_self_signup_disabled`,
  `simplexml_load_file_wrapper_error`) plus the `// ----- END -----` marker.
- `locale/ro_RO/strings.txt` — 1 key (`href_print_req`).

Impact per `lib/functions/lang_api.php`: each missing key silently falls back to
English **and** fires a LOCALIZATION warning into the Event Viewer.

Both blocks were re-inserted verbatim from `7892bb3f4^`. Re-running the sweep now
reports **0 keys lost** across all 19 bundles, `php -l` is clean on both repaired
files, and `lang_get()` resolves them at runtime:

```
cs_CZ | remove_plat_msgbox_title=Remove Platform
ro_RO | href_print_req=Document specificație cerințe
```

### Hardening applied

`gui/templates/documentation/documentation.html` — the local `esc()` helper only
escaped `& < >` (via `$('<div>').text().html()`), yet doc titles are interpolated
into `data-pdf` / `data-title` **attribute** values. It now escapes `"` and `'`
as well (same helper as `plans/planAddTCView.html:165`). Not exploitable before —
titles come from translator-controlled `strings.txt` and `pdfUrl` is hardcoded —
but it removes the attribute-breakout path entirely.

### Separate defect found while linting all bundles

`locale/fr_FR/strings.txt:4089` contains a PHP **parse error** (unescaped
apostrophes in a single-quoted string), so the entire French locale fails to load.
Introduced by `5cd596219` (Refs #1587), unrelated to #1275. Tracked as **#1839**.

---

## 4. Interactions

| Action | Behavior |
|--------|----------|
| **View** | Opens a Bootstrap modal with an embedded PDF viewer (`<embed>` tag) |
| **Download** | Direct download link to the PDF file via `<a>` with `download` attribute |
| **Open Wiki** | Opens GitHub Wiki in a new browser tab |
| **Refresh** | Reloads document list from BFF API |
| **Locale switcher** | Changes UI language via TLi18n module |

---

## 5. Data Flow

| Step | Description |
|------|-------------|
| 1 | Screen loads, calls `TLi18n.load()` to initialize i18n |
| 2 | `$.getJSON('/api/documentation/index.php')` fetches document list |
| 3 | BFF API checks session, builds document list with titles from `lang_get()` |
| 4 | BFF verifies each PDF file exists on disk |
| 5 | JSON response rendered as card grid by `renderDocs()` |
| 6 | View button click opens Bootstrap modal with PDF embed |

---

## 6. Parity with Legacy

| Legacy behavior | Modernized behavior |
|----------------|-------------------|
| `tools/viewer.php?file=xxx` served PDF directly | Dashboard card view with View/Download |
| Individual ASIDE links per document | Single Documentation link → hub page |
| No UI (just PDF embed) | Dashio-styled card grid with icons |
| No download button | Explicit Download button per document |
| No wiki link | GitHub Wiki card included |
| No i18n | Full TLi18n support (10 locales) |
| No refresh | Refresh button reloads from BFF |

---

## 7. Files

| File | Purpose |
|------|---------|
| `gui/templates/documentation/documentation.html` | Dashio HTML screen |
| `api/documentation/index.php` | BFF API (document list) |
| `gui/templates/i18n/en.json` (+ 9 other locales) | i18n keys (doc.* namespace) |
| `gui/templates/dashio/aside.tpl` | ASIDE link switched to new HTML page |
| `tools/viewer.php` | Legacy viewer (kept for backward compatibility) |
