# Task 1351 — reqSpecView revision log history tooltip (gap vs legacy)

**Issue:** [#1351](https://github.com/sebiboga/testlink-upgraded/issues/1351)
**Status:** IMPLEMENTED — branch `task/issue-1351`

## The gap

The modern Requirement Specification Viewer (`gui/templates/requirements/reqSpecView.html`)
rendered the revision of the spec as an inert badge (`<span class="badge-rev">Revision 2</span>`)
in the Overview card. The legacy 1.9.20 screen showed an on-hover **revision log history
tooltip**:

- `gui/templates/dashio/requirements/include/reqSpecViewJS.inc.tpl:12-38` — `tip4log()`
  builds an `Ext.ToolTip` (`width: 500, dismissDelay: 0, trackMouse: true`) targeting the
  revision cell `tooltip-<revision_id>` and `autoLoad`s
  `lib/ajax/getreqspeclog.php?item_id=<req_specs_revisions.id>`.
- `gui/templates/dashio/requirements/reqSpecView.tpl:116-119` — the revision cell carries
  `log_message_small` icon; hovering shows the **FULL untruncated** log message of the
  current revision.
- `lib/ajax/getreqspeclog.php` — `SELECT log_message FROM req_specs_revisions WHERE id=...`,
  `nl2br`, strips wrapping `<p>/</p>`, empty message → `empty_log_message`.

The modern screen never displayed the log: the re-written `spec_view` BFF action did not
return `log_message`, and the client never called `spec_revision_view` (which does). Same
gap was filed separately for `reqCompare` (#1308) and `reqView` (#1303); `reqSpecCompare`
got a very similar tooltip under #1357.

## Modern implementation (port)

Pattern mirrored from the verified #1357 fix in `gui/templates/requirements/reqSpecCompare.html`.
No extra fetch round-trip: the full log already travels in the `spec_view` payload.

**BFF — `api/reqspec/index.php` (`spec_view` action):**
- `spec.log_message` — the `log_message` of the shown (latest) revision, fetched directly
  from `req_specs_revisions` by `spec.revision_id` (`requirement_spec_mgr::get_by_id()`
  does not select it, `lib/functions/requirement_spec_mgr.class.php:189-214`).
- `spec.log_message_len` — `req_spec_cfg->log_message_len` (default 200, `config.inc.php:1713`),
  same sink `spec_revision_compare` exposes.

**HTML — `gui/templates/requirements/reqSpecView.html`:**
- The overview Revision row is now `<span class="rev-log-tip">` wrapping the badge plus a
  `fa-commenting-o` log icon (`title = rsv.revisionLog`).
- A mouse-tracked `#logTooltip` div (fixed position, dark Dashio style, `white-space: pre-wrap`,
  max-width 500px) is shown/hidden via event delegation on `#ovCard`
  (`bindRevLogTooltip()`), reading `SPEC.log_message`.
- `normalizeLogTip()` mirrors `getreqspeclog.php`: strip `<p>`, `</p>` → newline, trim;
  empty → `common.emptyLogMessage`. Content is rendered with `.text()` → no HTML injection
  (legacy tooltip autoLoaded raw HTML — an XSS vector this port intentionally drops).

## i18n

New key `rsv.revisionLog` ("Revision log message") added to all 10 locale bundles
(`en ro de es fr it ja pt ru zh`), reusing the existing per-locale `rsvr.logMessage`
wording. The empty-message placeholder reuses the shared `common.emptyLogMessage`
("Log message is empty", present in all 10 bundles).

## REST API

`GET ?action=spec_view&id=N[&tproject_id=N]` now additionally returns
`spec.log_message` (string) and `spec.log_message_len` (int `req_spec_cfg->log_message_len`).
Rights unchanged: `mgt_view_req` on the spec's owning test project.

## Testing

**Suite 1544 — Task Issue #1351** in `tmp/TLU_Test_Cases.md` (**7/7 PASS**):
BFF payload carries the revision log; hover shows the full untruncated log tooltip;
mouse-leave hides it; empty-log placeholder renders `common.emptyLogMessage`; `?locale=ro`
translates icon title + badge; Event Viewer clean (no log_level ≥ 32 rows); legacy
`getreqspeclog.php` + sibling `reqSpecCompare.html` regression pass.

Screenshots: `docs/screenshots/issue-1351-reqspecview-normal.png`,
`docs/screenshots/issue-1351-reqspecview-tooltip.png`,
`docs/screenshots/issue-1351-reqspecview-tooltip-ro.png`. Fixture: `tmp/fixtures_1351.php`.

## Files

| File | Purpose |
|---|---|
| `api/reqspec/index.php` | `spec_view` now returns `spec.log_message` + `spec.log_message_len` |
| `gui/templates/requirements/reqSpecView.html` | revision row → `.rev-log-tip` + mouse-tracked `#logTooltip` (Dashio #1357 pattern) |
| `gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json` | `rsv.revisionLog` key |
| `tmp/fixtures_1351.php` | re-runnable fixture: project RV51 + spec SRS-RSV-1351 with 2 logged revisions |