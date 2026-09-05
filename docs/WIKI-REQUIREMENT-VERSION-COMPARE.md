# Requirement Version Compare — Modernized Screen

Modernization of **Requirement Version Compare**
(`lib/requirements/reqCompareVersions.php`) — GitHub issue
[#1017](https://github.com/sebiboga/testlink-upgraded/issues/1017).

The legacy Smarty diff screen is replaced by a standalone Dashio page
(`gui/templates/requirements/reqCompare.html`) backed by a dedicated BFF
(`api/reqcompare/index.php`, `versions` + `compare` actions). It is a
**non-ASIDE** screen reached from the modernized **Requirement Viewer**
(`reqView.html`) via the new "Compare versions" toolbar button (shown whenever
the requirement has more than one version/revision in its history).

**URL:** `gui/templates/requirements/reqCompare.html?requirement_id=<id>&tproject_id=<id>`
**BFF API:** `api/reqcompare/index.php?action=versions|compare`
**Rights:** `mgt_view_req` on the owning test project (resolved from
`requirements JOIN req_specs`) enforced server-side on every route.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Version picker | table of history rows (version/revision, left/right radios, log message, timestamp) | DataTable of history rows (ordered DESC) with two radio columns (left / right), log message, "last change" timestamp + editor; newest two pre-selected (left = newest, right = 2nd newest) |
| Diff method | radio `htmlCompare` (DaisyDiff) vs `htmlCodeCompare` (text `diff`/`inline` + context + show-all) | same two radio options; context input + "Show all" shown only in code-compare mode |
| Attributes diff | `getAttrDiff()` table (status, type, expected coverage) with changed highlighting | same — attribute table with `changed` rows highlighted |
| Scope diff | `diff`/`HTMLDiffer` on the `scope` of each version/revision | same — HTML-ins/del or text diff; "No changes" when equal |
| Custom fields diff | `getCFDiff()` table of linked design CF values on each item | same — CF table, `show_custom_fields_without_value` honored |
| Notification | none | "No changes" badge per unchanged section; diff summary line "Comparing vX rY ↔ vA rB" |

## 2. REST API Reference

All routes are session-authenticated and JSON; CSRF Origin header required.

| Method | Route | Query | Returns |
|---|---|---|---|
| GET | `?action=versions` | `requirement_id`, `tproject_id` (optional) | `{status, tproject_id, tproject_name, req_id, req_doc_id, req_type, context, items:[{item_id, version_id, revision_id, version, revision, scope, status, status_label, type, type_label, expected_coverage, log_message, timestamp, last_editor}]}` |
| GET | `?action=compare` | `requirement_id`, `left`, `right`, `method=html|text`, `context`, `context_show_all` | `{status, req_id, req_doc_id, req_type, left, right, method, attributes:[{label,lvalue,rvalue,changed}], scope:{type,left,right,count,diff}|null, custom_fields:[{label,lvalue,rvalue,changed}]}` |

### Error conditions
- Missing/invalid session → HTTP 401.
- Unknown requirement → 404 `Requirement not found`.
- Missing `requirement_id` / missing `left` or `right` → 400.
- Conflicting `tproject_id` → 400.
- Unknown action → 404 `Unknown action`.
- Non-GET method → 405.
- No `mgt_view_req` right on the owning test project → 403.

## 3. Legacy parity notes

- The version/revision list comes from `requirement_mgr->get_history()` (same as
  legacy), returning `item_id`/`version`/`revision`/`revision_id` (`-1` for plain
  version rows), `scope`, `status`, `type`, `expected_coverage`, `log_message`,
  `timestamp`, `last_editor`. Ordering is the legacy hardcoded DESC
  (newest version/revision first).
- Two diff engines are ported 1:1: `HTMLDiffer::htmlDiff` (HTML comparison;
  `nl2br` applied on the scope only when the req type is plain text, matching the
  legacy `getWebEditorCfg('requirement')` branch) and `diff::doDiff` + `inline`
  (HTML code comparison with a context window).
- Attribute labels come from `init_labels(['status','type','expected_coverage'])`
  (e.g. "Number of test cases needed"); the `type`/`status` single-char columns are
  compared using the legacy constants.
- Custom fields are read via `get_linked_cfields` on each item's node id; date and
  datetime values are decoded with `tlStrftime`; `show_custom_fields_without_value`
  decides whether empty values appear.
- **Known quirk (pre-existing):** `last_editor` renders as "undefined" for items
  created via `create_new_version()`/`create_new_revision()` — that's
  `get_history()` legacy behavior, byte-identical in the already-modernized
  `reqSpecCompare` `spec_revision_compare` path; intentionally not fixed here
  (documented, not a regression).
- `create_new_version()` bumps the previous version's own `revision` counter (so a
  v2 row may show `r3` next to a separate `r1` revision row) — legacy behavior,
  reproduced by the fixture.

## 4. i18n Keys

All labels are client-side via `TLi18n`; keys under the `rcmp.` namespace
(`rcmp.title`, `rcmp.selectVersions`, `rcmp.selectTwo`, `rcmp.diffMethod`,
`rcmp.htmlCompare`, `rcmp.codeCompare`, `rcmp.context`, `rcmp.showAll`,
`rcmp.version`, `rcmp.revision`, `rcmp.compare`, `rcmp.logMessage`, `rcmp.timestamp`,
`rcmp.lastChange`, `rcmp.left`, `rcmp.right`, `rcmp.compareSelected`, `rcmp.invalidContext`,
`rcmp.computing`, `rcmp.diffDetails`, `rcmp.diffBetween`, `rcmp.noChanges`,
`rcmp.attributes`, `rcmp.attribute`, `rcmp.scope`, `rcmp.customFields`,
`rcmp.customField`, `rcmp.noVersions`, `rcmp.errLoad`, `rcmp.errNotFound`,
`rcmp.errNoRights`) — 31 keys, plus `footers.reqCompare` (footer) and
`reqv.compareVersions` (the `reqView.html` toolbar button label). Present in all
10 bundles (`en ro de es fr it ja pt ru zh`).

## 5. Security

- Server-side rights check on every route: `mgt_view_req` required even to list
  versions; the requirement's owning test project is resolved from the
  `requirements JOIN req_specs` rows (guest user verified 403). Deep links without
  `tproject_id` still resolve the owner.
- CSRF guarded via Origin header check (all BFF routes).
- All ids are int-cast/prepared; diff HTML is re-escaped client-side
  (`sanitizeDiffHtml()` strips `script` tags and event attributes) before
  injection into the panel.

## 6. Testing

See **Suite 1017 — Requirement Version Compare** in `tmp/TLU_Test_Cases.md`
(17/17 PASS): versions list, HTML diff, code-compare diff, identical-pairs
"No changes", unknown-action 404, missing requirement 404, missing-param 400,
tproject-mismatch 400, unauthenticated 401, no-rights 403, screen rendering +
preselection, compare flow via UI, locale switch, `reqView.html` link-switch,
i18n key/JSON validation in all 10 bundles, and Event Viewer cleanliness
(events table left empty by fixture + screen).