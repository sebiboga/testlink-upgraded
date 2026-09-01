# Test Case Version Comparison — Modernized Screen

Modernization of the legacy compare-versions screen (`lib/testcases/tcCompareVersions.php`,
`gui/templates/dashio/testcases/tcCompareVersions.tpl`) — GitHub issue
[#809](https://github.com/sebiboga/testlink-upgraded/issues/809).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/testcases/tcCompare.html`) backed by a plain-PHP REST BFF API
(`api/testcasescompare/index.php`). The Test Case Viewer toolbar **Compare Versions**
button now opens the modern screen instead of submitting the legacy POST form.

**Path:** Test Spec → Test Case Viewer → toolbar **Compare Versions**
**URL:** `gui/templates/testcases/tcCompare.html?tproject_id=..&testcase_id=..`
**BFF API:** `GET /api/testcasescompare/?action=info` + `GET /api/testcasescompare/?action=compare`
**Rights:** any authenticated session (parity with legacy, which is read-only).
**Tracking issue:** [#809](https://github.com/sebiboga/testlink-upgraded/issues/809)

---

## Screen layout

| Section | Description |
|---------|-------------|
| **Heading** | Compare title + test-case context (name + id) |
| **Version table** | One row per version: version number, radio **Version A (left)**, radio **Version B (right)**, modified/created timestamp, author |
| **Diff method** | Radio **HTML (code) comparison** (DaisyDiff) vs **Text (inline) comparison** (legacy line diff) |
| **Context rows** | Only for text mode: **Context lines** number + **Show all** toggle |
| **Action** | **Compare selected versions** and **Cancel** |

Default selection mirrors legacy: the newest version is pre-selected on the right
and the previous one on the left (matching the legacy `{if $mycount == 2}` /
`{if $mycount == 1}` defaults).

## Flow

1. On load the page calls `GET /api/testcasescompare/?action=info` to resolve the
   test case, its version list (with modification/creation timestamp + author) and
   the default text-diff context.
2. The user picks two different versions and a diff method, then clicks
   **Compare selected versions**.
3. The page calls `GET /api/testcasescompare/?action=compare` with
   `version_left`, `version_right`, `use_html_comp`, and (text mode) `context` /
   `context_show_all`.
4. The BFF runs the **same third_party diff engines** as the legacy controller
   (`third_party/diff/diff.php` `inline()` for text, `HTMLDiffer` for HTML) and
   returns per-section diffs. The client renders each section with an i18n heading
   and a localized change-count message.

## Diff sections compared

| Key | Source |
|-----|--------|
| `summary` | `tcversions.summary` (left vs right version) |
| `preconditions` | `tcversions.preconditions` |
| `steps` | concatenated step actions with line breaks |
| `expected_results` | concatenated step expected results with line breaks |

## Error & edge states

| Case | Result |
|------|--------|
| No `testcase_id` | warn box `tcc.errNoTestcase`, Compare disabled |
| Test case not found | HTTP 404 `{"status":"error","message":"Test case not found"}` |
| Same version selected twice | local toast `tcc.warnSameVersions`, no request |
| Invalid (negative/non-numeric) context | local toast `tcc.warnInvalidContext`, no request |
| Not authenticated | HTTP 401 `{"status":"error","message":"Not authenticated"}` |

## Backend notes

- The BFF reuses the **exact legacy diff logic** (`buildDiff()` in
  `tcCompareVersions.php`), including the step concatenation with `<br /><br />`
  separators and the `</p> → </p>\n` line-break insertion for the text engine.
- **Event Viewer fix:** the legacy controller resolved the section messages and
  subtitle with `lang_get('num_changes'/'no_changes'/'diff_subtitle_tc')`. Those
  strings are missing from most `locale/*/strings.txt`, which fired
  `E_WARNING Undefined array key` into the Event Viewer on every compare. The BFF
  now returns raw counts + version ids and the **client resolves all messages via
  `TLi18n` keys** (`tcc.numChanges`, `tcc.noChanges`, `tcc.subtitle`,
  `tcc.heading.*`), so the Event Viewer stays clean.

## API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/testcasescompare/?action=info` | test case name + version list + default context |
| GET | `/api/testcasescompare/?action=compare` | side-by-side / inline diff of two versions |

All routes require a valid session (return 401 otherwise).

## i18n

All labels, titles, headings, messages and placeholders use client-side `TLi18n`
keys under the `tcc.*` namespace (30 keys) present in all 10 locale bundles
(`gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json`). Bundle consistency is
enforced by `tools/lint_i18n.py`; every bundle passes `python3 -m json.tool`.

---

_TestLink 2.0.1 · Compare Test Case Versions · Refs #809_
