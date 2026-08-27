# Documentation Hub — Modernized Screen

The **Documentation Hub** provides access to TestLink user manuals, guides, and references as PDF documents. It replaces the legacy `tools/viewer.php` PDF viewer with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Documentation
**URL:** `gui/templates/documentation/documentation.html`
**BFF API:** `api/documentation/index.php` — `GET ?action=list` (default)
**Rights:** Any logged-in user (same as legacy viewer — no rights check)
**Tracking issue:** [#764](https://github.com/sebiboga/testlink-upgraded/issues/764)

---

## 1. Overview

The Documentation Hub consolidates access to all TestLink documentation in one screen:

| Section | Content |
|---------|---------|
| **PDF Manuals & Guides** | 6 PDF documents with View and Download buttons |
| **Online Resources** | GitHub Wiki link |

The screen follows the same Dashio visual patterns as other modernized screens: teal header, dark toolbar, card grid layout, Bootstrap modal for PDF viewing.

---

## 2. Screen Layout

| Element | Description |
|---------|-------------|
| **Header** | Teal banner with "Documentation" title, subtitle, locale switcher |
| **Toolbar** | Refresh button, generation timestamp |
| **PDF section** | Card grid with 6 document cards (title, filename, View/Download buttons) |
| **Wiki section** | Card grid with GitHub Wiki link card |
| **Footer** | Generation timestamp |

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

Additionally, the GitHub Wiki is linked as an online resource.

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
