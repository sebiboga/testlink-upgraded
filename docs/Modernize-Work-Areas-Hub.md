# Modernize: Work Areas hub (`lib/general/frmWorkArea.php`) — Refs #1971

## Context

TestLink 1.9.20 opened every work area through
`lib/general/frmWorkArea.php?feature=<id>`: the controller rendered a frameset
with the left tree and the right content pane (`frmInner.tpl`). Issue #1575
retired that renderer and replaced it with a 302 shim that forwards each
`feature` to the matching modern Dashio screen, but the launcher itself never
got a modern landing of its own. Stale bookmarks, the `guiTopMenu` map in
`cfg/const.inc.php` and legacy links could still enter through legacy PHP.

This run adds the missing landing: a modern **Work Areas hub**.

## What changed

### `api/workarea/index.php` (new BFF)

`GET|HEAD ?action=init[&tproject_id=N][&tplan_id=M][&feature=<legacy>]`

- Returns the session/auth context, the resolved project/plan names, the four
  per-area rights flags, the area cards and — when `feature` is given — the
  resolved modern target.
- Feature resolution mirrors the legacy `$feature_map` of
  `lib/general/frmWorkArea.php` (19 ids), so every old deep link keeps working.
- Rights are checked per area on the **addressed** project before its name is
  resolved (#1697: no existence oracle):
  - Requirements: `mgt_view_req` or `mgt_modify_req`
  - Test Specification: `mgt_view_tc` or `mgt_modify_tc`
  - Execute Tests: `testplan_execute` or `exec_ro_access`
  - Results & Metrics: `testplan_metrics`
- JSON contract: 401 `not_authenticated`, 400 `invalid_request` /
  `invalid_parameter`, 403 `no_right`, 404 `unknown_feature`, 405
  `method_not_allowed`; `Cache-Control: no-store`, `nosniff`,
  `bffEnforceSession` + `bffSameOriginGuard`, reads only.

### `gui/templates/general/workArea.html` (new screen)

- Dashio shell with the teal header, dark toolbar (project/plan context,
  Refresh, Back to dashboard, Close), locale switcher, footer and notice.
- Four area cards: Requirements (5 links), Test Specification (4), Execute
  Tests (6), Results & Metrics (1). Each link opens the existing modern screen
  with the current `tproject_id`/`tplan_id`.
- Locked cards for areas the caller cannot use ("No access").
- `feature=` deep links show an "Opening screen..." card (with a manual link)
  and auto-redirect to the modern target.
- State cards for session expired (401), no right (403), unknown screen (404)
  and generic errors, each carrying the machine code.

### Wiring and legacy retirement

- `$actions->workArea` added in `lib/functions/common.php` →
  `/gui/templates/general/workArea.html?{ctx}` (project-scoped, no test plan
  required).
- `lib/general/frmWorkArea.php` keeps its feature map and context override, but
  a missing/unknown `feature` now lands on the hub instead of the legacy empty
  page (`tLog` at WARNING, no `exit()`).

### i18n

`wa.*` (41 keys) + `wa.area.*` (4 keys) + `footers.workArea` in all 10 client
bundles (`en, de, es, fr, it, ja, pt, ro, ru, zh`).
`bash ai/verify_i18n_coverage.sh` → 9/9 PASS.

## Verification

Fixture `tmp/fixtures_1971.php`: project `WA1971` + plan `WA1971 Plan`, role-3
user `norights`.

- Hub render: four cards, 5/4/6/1 links, correct project/plan context, all
  links carry the context (Suite #1971).
- `feature=reqSpecMgmt|executeTest|showMetrics` → the matching modern screen;
  all 19 legacy ids resolve (curl matrix).
- Shim: no feature → hub; known feature → target; unknown feature → hub.
- `feature=bogus` → 404 card; `feature[]=x` → 400; unknown action → 400;
  anonymous → 401; POST without same-origin proof → 403.
- `norights` user: all four cards locked, deep link → 403 `no_right` card.
- RO locale renders "Zone de lucru", "Cerințe", "Execută teste",
  "Rezultate și metrici" with no raw keys.
- No console errors; Event Viewer shows no new ERROR/WARNING rows.
- Suite `Issue #1971` = 8/8 PASS in `tmp/TLU_Test_Cases.md`.

## Files

| File | Purpose |
|---|---|
| `api/workarea/index.php` | BFF: context, rights, area cards, feature resolution |
| `gui/templates/general/workArea.html` | Dashio Work Areas hub screen |
| `lib/functions/common.php` | `$actions->workArea` |
| `lib/general/frmWorkArea.php` | shim now falls back to the hub |
| `gui/templates/i18n/*.json` | `wa.*` + `footers.workArea` keys |
| `tmp/fixtures_1971.php` | test fixture (project + plan + norights user) |
