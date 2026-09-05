# Print Test Specification — Modernized Screen

Modernization of the **Print Test Specification** screen (the legacy
`lib/results/printDocOptions.php?type=testspec` document generator) — GitHub
issue [#982](https://github.com/sebiboga/testlink-upgraded/issues/982).

The standalone Dashio navigator (`gui/templates/testcases/printTestSpec.html`)
lets you print the whole test specification document of a test project or a
single test suite, with the exact same document-option checkboxes as the legacy
screen (table of contents, header numbering, test case header, summary, steps,
author, keywords, custom fields, requirements) and the HTML / MS Word output
format. The generated document opens in its own tab
(`gui/templates/testcases/printTestDoc.html`) so you can preview, print or
download it.

**Screen:** `gui/templates/testcases/printTestSpec.html` (navigator) +
`gui/templates/testcases/printTestDoc.html` (document popup)
**BFF API:** `api/testcasesprint/index.php`
(`GET ?action=init`, `GET ?action=tree`, `GET ?action=print`,
`GET ?action=download`)
**Rights:** `testplan_metrics` on the target test project — 403 without it.
**ASIDE entry:** Test Case Design → **Test Specification Document** (gated the
same way).

Screenshots: `docs/screenshots/pts_navigator.png`, `pts_doc_wholeproject.png`,
`pts_doc_suite.png`.

---

## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

- **Navigator (`printTestSpec.html`):**
  - header "Print Test Specification in test project <name>", with a locale
    switcher (the printed document itself uses the chosen document language,
    exactly like the legacy `document_language` selector).
  - info box (i) explaining the node-click behavior, **(i)** "Select a test
    suite" hint, and the tree listed with per-suite test-case counts and a
    "whole project document" row under the project node, plus a **Refresh
    tree** button.
  - clicking the project node or a suite node opens the document popup for the
    whole project / that suite in a new tab.
  - **Document print options** card: Output format (HTML / MS Word), then the
    legacy checkboxes split into **DOCUMENT STRUCTURE** — Table of contents,
    Header numbering — and **TEST SPECIFICATION CONTENT** — Test case header
    (id, title, status), Test case summary (default on), Test case steps,
    Author, Keywords, Custom fields, Requirements.
  - **Print whole project document** button.
  - footer: "`N` test suites · `M` test cases" plus "TestLink 2.0.1 - Print
    Test Specification".
- **Document popup (`printTestDoc.html`):**
  - loads `GET ?action=print&level=<testproject|testsuite>&id=<id>&tproject_id=<n>`
    with the chosen options as `format`, `toc`, `headerNumbering`, `header`,
    `summary`, `body`, `author`, `keyword`, `cfields`, `requirement` flags and
    renders the extracted legacy HTML document inside an iframe.
  - toolbar: title (`<title>` of the generated document), **Print** (window
    print of the iframe), **Refresh**, **Back** (returns to the navigator), and
    for MS Word format a client-side `.doc` Blob download button.
  - HTML output is shown in a bordered preview; MS Word output is downloaded as
    `TestSpecification_<project-name>.doc` (the legacy filename).

## 2. REST API Reference

Authenticated (session cookie); BFF runs at top-level script scope so the
legacy `printDocument.php` include resolves project-level `$tlCfg`.

| Method & path | Rights | Description |
|---|---|---|
| `GET ?action=init&tproject_id=<n>` | view | Project name + option defaults (matching the legacy `getPrintOptions()` defaults: summary on, rest off) + output formats |
| `GET ?action=tree&tproject_id=<n>` | view | Nested suite tree with recursion-aware `tcTotal` counts (project root excluded from its own path) |
| `GET ?action=print&level=<testproject\|testsuite>&id=<id>&tproject_id=<n>&format=…&…options…` | `testplan_metrics` | Rendered document title + HTML body; whole-project (`level=testproject`, `id=<tproject_id>`) or suite (`level=testsuite`, `id=<suite_id>`) |
| `GET ?action=download&…` (same params) | `testplan_metrics` | MS Word: streams the legacy document with attachment headers |

Errors: 400 invalid level, 403 missing right, 404 missing project / unknown
suite / suite not belonging to the project.

## 3. Legacy parity notes

- The entire legacy pipeline is reused: `lib/functions/printDocument.php`
  at top-level include scope, `spec_header/spec_footer`, the per-suite
  recursive document builder (`print_testsuite`), `get_tarefa_array()` step
  reconstruction and the keywords/CF/requirement sections. Nothing is
  re-implemented, so output stays byte-identical to 1.9.20.
- `FORMAT_HTML = 0`, `FORMAT_MSWORD = 4`, `DOC_TEST_SPEC = 'testspec'`.
- Toc (table of contents) and header-numbering state are kept across the
  legacy object (`report_debug/options` in `printDocumentSpecMakeSpec()`).
- Suite membership is verified with `get_subtree_list(...,'array')` over
  `get_path()` (which excludes the root node, so a `get_path()` membership
  test alone would wrongly reject suites).
- Document title extraction prefers the `<title>` tag, then the TOC `<h1>`.
- The MS-Word legacy generator commits its `Content-Type: application/vnd.ms-word`
  headers during the include (`headers_sent()` is already true on return), so
  the BFF cannot un-commit them; the front-end therefore forces jQuery
  `dataType:'json'` for the `print` request so the JSON response is never
  re-sniffed as text.

## 3a. Deviations from legacy

- The legacy click-through flow (checkbox list + "Print document" submit within
  the same page) becomes a navigator + separate doc-popup tab; selection state
  is kept in the popup's query string instead of a server-side form.
- The legacy locale dropdown written into the document header is preserved as
  the navigator's locale switcher (same `ls_*` locale codes).
- `format=HTML` documents are previewed in the popup (an iframe) and can be
  browser-printed; no download is forced, matching the legacy "display / save"
  choice.

## 4. i18n Keys

New keys in ALL 10 locale bundles (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`):

`footers.printTestSpec`, `footers.printTestDoc`, the `pts.*` navigator set
(status header, tree labels, option-group headers, each of the 9 document
option captions, format labels, message strings) and the `ptdoc.*` popup set
(toolbar buttons, download label, preview helpers). Reused existing `common.*`
keys for buttons and table chrome.

## 5. Security

- Same-origin guard (`bffSameOriginGuard()`) on every route.
- `testplan_metrics` on the owning test project gates `print`/`download`
  (verified against the legacy `has_right()` check in `printDocOptions.php`);
  `init`/`tree` answer with `canGenerate:false` (and hide the print controls)
  when the user lacks it — legacy lets the user see the form but fails on
  submit, the screen reflects the grant up-front.
- `level` is whitelisted; `id`/`tproject_id` are `intval`-cast and every id is
  cross-checked against the session's test projects.

## 6. Testing

Exercised via browser (navigator, whole-project & suite documents, MS Word
preview/download, locale switch, ASIDE wiring) and direct BFF curl probes
(401 unauth, 400 invalid level, 403 no-rights, 404 missing project/suite,
404 unknown action). Regression suite PTS-982 (16/16 PASS) appended to
`tmp/TLU_Test_Cases.md`; Event Viewer check confirmed no Error/Warning rows
from the modernized screen.