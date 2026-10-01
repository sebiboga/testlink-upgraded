# Modernize — Test Case Summary (`tcSummary`)

**Issue:** [#1767](https://github.com/sebiboga/testlink-upgraded/issues/1767)
**Screen:** `gui/templates/testcases/tcSummary.html`
**BFF:** `api/tcsummary/index.php`
**Legacy source:** `lib/ajax/gettestcasesummary.php` (now a session-guarded launcher)

## Why this screen

In 1.9.20 the summary of a test case was not a screen: the Add/Remove Test Cases
workframe showed it in an **ExtJS tooltip** whose `autoLoad` pointed at
`lib/ajax/gettestcasesummary.php` (`gui/templates/dashio/plan/planAddTC_m1.tpl:58`,
`planAddTCJS.inc.tpl`). The 2.0.1 modernization rewrote that workframe as
`gui/templates/plans/planAddTCView.html` and **dropped the summary entirely**.

The legacy endpoint was also unsafe:

1. **No authorization at all** — `testlinkInitPage()` only validated the session
   and `tcase_id` came straight from `$_REQUEST`, so any authenticated user could
   read the summary of a test case belonging to **any** test project (same class
   as #1696 / #1679).
2. **Stored XSS** — the RichEdit blob was echoed raw into the caller DOM, where
   the tooltip injected it as HTML.
3. **Unguarded nulls** — `testcase::get_last_version_info()` and `get_by_id()` can
   return `NULL` and were dereferenced unconditionally (PHP 8 warning).

## Modern screen

Dashio standalone page, teal header + dark toolbar (Refresh / Open test case /
Close) and the `TLi18n` locale switcher in the footer.

- **Context card** — test project, prefix, `#PREFIX-N` external-id chip, version
  chip, a `latest version` chip when the shown version is the newest, and the
  suite path (`Summary Root / Summary Sub`).
- **Summary card** — the stored RichEdit blob rendered as **escaped plain text**
  (block tags collapsed to newlines, entities decoded exactly once). A collapsed
  `<details>` "Stored source" block lets an author see the real markup.
- Empty summary keeps the legacy `empty_tc_summary` message.
- Explicit loading / empty / missing-id / bad-request / denied / not-found /
  server-error cards, each keeping the stable machine code visible; a `401`
  bounces to `login.php?note=expired` like every other modernized screen.

## BFF contract

`GET|HEAD /api/tcsummary/index.php?action=summary&tcase_id=N[&tcversion_id=V][&tproject_id=P]`

- Session auth + `bffSameOriginGuard` + `bffEnforceSession`.
- The **owning** project is *proved* by walking `nodes_hierarchy.parent_id` up to
  `node_type_id = 1`; it is never trusted from the request.
- `mgt_view_tc` is enforced on that owning project.
- A requested `tcversion_id` is proved to be a version node of the addressed test
  case, **after** the rights check, so the answer cannot be used as an existence
  oracle.
- `tproject_id` is a client-side **assertion**: a stale deep link answers
  `404 project_mismatch` instead of silently showing another project's data.
- Stable codes: `missing_tc_id` / `invalid_tc_id` (400), `not_authenticated`
  (401), `no_right` (403), `tcase_not_found` / `project_mismatch` /
  `version_not_in_case` (404), `method_not_allowed` (405), guarded 500.
- `Cache-Control: no-store`.

## Legacy shim

`lib/ajax/gettestcasesummary.php` serves **no summary** any more:

- real browser navigation (`Sec-Fetch-Dest: document` / `Accept: text/html`)
  → `302` to the modern popup with `tcase_id` / `tcversion_id` / context preserved;
- XHR / fetch (Ext 3.4 and modern browsers) → `405` pointing at the BFF;
- anonymous → `302 login.php?note=expired&destination=…` (legacy
  `testlinkInitPage()` contract), `401` for XHR.

Three legacy templates still `autoLoad` this file (`dashio/plan/planAddTC_m1.tpl`,
`dashio/plan/planAddTCJS.inc.tpl`, `tl-classic/plan/planAddTC_m1.tpl`); they now
get an empty tooltip by design.

## Wiring

- `$actions->tcSummary` in `lib/functions/common.php`.
- `gui/templates/plans/planAddTCView.html` — a per-row summary button (with
  `aria-label`), opening the version selected in that row (or the newest active).
- `gui/templates/testcases/tcView.html` — one **Show summary** button per version
  card.

## i18n

`tcsum.*` (27 keys) + `footers.tcSummary` in all 10 locale bundles, with real
translations, validated with `python3 -m json.tool`.

## Security review

A mandatory code review found **no security hole** in the read path. It found one
information-disclosure issue (the version was resolved before the rights check,
making a pre-authorization existence oracle) which is fixed, plus three
should-fix items (button accessible name, footer key never rendered, shim caller
count). See the suite's `1767.R*` cases.

## Tests

Suite 1767 (28 cases, all PASS) in `tmp/TLU_Test_Cases.md`, fixture
`tmp/fixtures_1767.php`. Screenshots:
`docs/screenshots/issue-1767-tcsummary-01-rich.png`,
`...-02-denied.png`, `...-03-tcview-button.png`.
