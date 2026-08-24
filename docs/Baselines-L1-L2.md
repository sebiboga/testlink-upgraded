# Baselines L1 & L2 — Modernized Screen

The **Baselines Level 1 & Level 2 Test Suites** report (ASIDE → Reports → *Baselines Level 1 & Level 2 Test Suites*) shows the SAVED baseline snapshots of a test plan: for every platform, one table per baseline capture with the First/Latest execution window and the "Baseline taken on" timestamp, listing Top : Child (L1 : L2) suite rows with per-status quantities/percentages and the completion percentage at capture time. It replaces the legacy 1.9.20 `lib/results/baselinel1l2.php` HTML view with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Baselines Level 1 & Level 2 Test Suites*
**URL:** `gui/templates/results/baselineL1L2.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=metrics_baseline_l1l2&tproject_id=&tplan_id=`
**Rights:** `testplan_metrics`
**Tracking issue:** [#673](https://github.com/sebiboga/testlink-upgraded/issues/673)

---

## Overview (with platforms + baselines)

The header carries the test plan context; the teal "Important Notice" banner appears only when the plan has platforms (legacy platform-relationship counting rule). Each platform gets its own card titled "Results on Platform: \<name\>"; inside, EVERY saved baseline snapshot renders as its own block:

* **Baseline taken on: \<timestamp\>** – when the baseline was captured (newest first, legacy `ORDER BY BLC.creation_ts DESC`).
* **First/Latest Execution on:** – the execution window covered by the snapshot.
* The classic matrix:

| Column | Content |
|--------|---------|
| Top : Child (Test Suites) | L1:L2 suite path, alphabetical order (legacy SQL `top_name ASC, child_name ASC`) |
| Total | test cases linked for that platform at capture time |
| Not Run / Passed / Failed / Blocked | qty + [%] per status (server-localized labels, results-config display order) |
| Completed [%] | `(total - not_run) / total * 100` — same formula as legacy |

## Toolbar actions (legacy parity)

* **Send by email to myself** – POSTs to the legacy `baselinel1l2.php?format=<MAIL_HTML>` flow.
* **Export data as spreadsheet** – downloads `TestLink_GTMP_<proj>_<plan>.xls` from the legacy XLS generator.
* **Refresh** – re-fetches the BFF metrics.

## Empty state (no baseline yet)

A plan without any saved baseline shows an explicit friendly message pointing to the *Save baseline* action on the Results by Test Suite report (the legacy page simply rendered nothing).

## How baselines are created

Unchanged from legacy: open **Results by Test Suite** (`resultsByTSuite.php`) and use its *Save baseline* action — it writes one `baseline_l1l2_context` row (+`baseline_l1l2_details`) per platform. The modern screen reads exactly those tables; you can capture several snapshots over time and compare them side by side.

## BFF API

`GET /api/reports/index.php?action=metrics_baseline_l1l2&tproject_id=<id>&tplan_id=<id>`

Key payload fields: `hasData`, `show_platforms`, `platform_blocks` (ordered `[{id,name,snapshots:[{begin,end,baseline_ts,rows:[…]}]}]` — real arrays so JS cannot re-sort platform/snapshot order), `columns` (status label map in display order), plus legacy `send_mail_url` / `export_xls_url`. Timestamps are raw and formatted client-side (the server strftime path renders placeholders literally under PHP 8, see #563).

Permission handling mirrors legacy `checkRights()`: no session ⇒ 401; missing `testplan_metrics` on global role AND project context ⇒ 403 (users holding the right only via a per-project role pass, like `hasRightOnProj()`).

## i18n

18 `bll.*` keys in all 10 locale bundles (en de es fr it ja pt ro ru zh); `bll.header` mirrors the legacy page title `baseline_l1l2`.

## Testing

13-case regression matrix (Suite 673) in `tmp/TLU_Test_Cases.md`: BFF parity vs DB, multi-snapshot ordering, empty state, XLS/mail exports, 403 path, aside switch, refresh, locale switcher, i18n completeness, Event Viewer clean.
