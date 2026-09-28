# Metrics Dashboard — per-status breakdown behind `show_test_plan_status` (issue #1019)

**Screen:** `gui/templates/results/metricsDashboard.html`
**BFF:** `api/metrics/index.php` (one added key: `precision`)
**Config:** `config.inc.php:977` — `$tlCfg->metrics_dashboard->show_test_plan_status` (default `false`)
**Wiki:** [Metrics-Dashboard](https://github.com/sebiboga/testlink-upgraded/wiki/Metrics-Dashboard)
**Test suite:** `tmp/TLU_Test_Cases.md` → suite 1019 (15 cases, all PASS)


## What was missing

The config flag `$tlCfg->metrics_dashboard->show_test_plan_status` was already plumbed
end-to-end on the server — the BFF returned it, the client honoured it — but the client only
used it to render **one** fragment of what legacy renders. Legacy appends a *per-status
breakdown line* to the test-plan cell; the modern cell emitted the overall progress only.

`lib/results/metricsDashboard.php:50-72` (legacy, verbatim):

```php
$tplan_string = strip_tags($platform_metric['tplan_name']);
if ($show_all_status_details) {
  $tplan_string .= "<br>";
  foreach( $statusSetForDisplay as $status_verbose => &$status_label) {
    $tplan_string .= lang_get($status_label) . ": " .
             $tplan_metrics['overall'][$status_verbose] .           // PLAN-level qty
             " [" . getPercentage($tplan_metrics['overall'][$status_verbose],
                                  $tplan_metrics['overall']['active'],
                                  $round_precision) . "%], ";      // qty [%] of PLAN active
  }
} else {
  $tplan_string .= " - ";
}
$tplan_string .= $labels['overall_progress'] . ": " .
                 getPercentage($tplan_metrics['overall']['executed'],
                               $tplan_metrics['overall']['active'], $round_precision) . "%";
```

With the flag on and 8 active TCs (4 executed: 2 passed / 1 failed / 1 blocked) legacy renders:

```
Not Run: 4 [50%], Passed: 2 [25%], Failed: 1 [12.5%], Blocked: 1 [12.5%], Overall Progress: 50%
```

The modern screen rendered, with the same data already in the response:

```html
<b>MD Demo Plan</b><div class="tplan-subline">Overall Progress: 50%</div>
```

**4 of the 5 expected fragments were missing.** This also closed the "Not in scope" note left
by the previous task on this screen ([#1018](Issue-1018-MetricsDashboard-Grouping-Toolbar-Filters.md)).

## What was implemented

### 1. The breakdown builder — `gui/templates/results/metricsDashboard.html:210-232`

`planStatusBreakdown(r, overall)` sits next to `statusLabel()`/`statusColor()` and is a
line-for-line port of the legacy block:

| Legacy | Modern |
|---|---|
| `foreach ($statusSetForDisplay …)` | `$.each(r.status_set …)` — BFF `status_set` **is** `array_keys($statusSetForDisplay)` |
| `lang_get($status_label)` | `statusLabel(k)` (reuses the existing `md.status*` keys) |
| `$tplan_metrics['overall'][$status_verbose]` | `overall.statuses[key]` — the **plan-level** block |
| `getPercentage($qty, $overall['active'], $round_precision)` | `active > 0 ? Math.round(qty/active*100*factor)/factor : 0` with `factor = 10^precision` |
| `$labels['overall_progress']` | `TLi18n.t('md.overallProgress')` |
| `", "` after every item, then the progress | `parts.join(', ')` with the progress as the last element |
| `" - "` when the flag is off | today's collapsed form kept (no sub-line at all) |

Three details that matter for parity:

- **The quantities are plan-level, not row-level.** With platforms enabled the BFF repeats the
  same `overall` block on every platform row of a plan, exactly as legacy reads
  `$tplan_metrics['overall']` — so all rows of a group show the same breakdown.
- **The denominator is `overall.active`, not `overall.executed`.** This is the case that proves
  it: `1/8` must render `12.5%`; with the wrong denominator it would be `33.33%`.
- **The status list is config driven.** An install that adds a custom exec status to
  `results.status_label_for_exec_ui` gets it in the breakdown with no code change, and
  `statusLabel()` falls back to a capitalized key for statuses without an `md.status*` key.

### 4. Exact PHP rounding — `roundPct()`

> **Gotcha found while reviewing this port:** `getPercentage()` uses PHP `round()`, which
> rounds half **away from zero**. Reproducing it with a JS float is **not always possible**:
> `23/160*100` is `14.374999999999998` in IEEE-754, so `Math.round(x*100)/100` *and*
> `toFixed(2)` both report `14.37` where PHP reports `14.38`. Measured over the whole
> reachable domain (`active` 1..400, `qty` 0..`active`, 80 600 pairs) both JS variants
> diverge from PHP on **10** pairs, all exact ties.

The percentage is a *rational*, so `roundPct()` rounds it as one with `BigInt`:

```js
var den = BigInt(active);
var scale = 10n ** BigInt(precision);
var num  = BigInt(qty) * 100n * scale;                 // value = num/den, in 1/scale %
return Number((2n * num + den) / (2n * den)) / Number(scale);   // ties away from zero
```

Exhaustive re-check against the PHP reference: **0 divergences in 80 600 pairs** (precision 0
and 4 spot-checked too). Falls back to the float form where `BigInt` is unavailable. A
malformed `qty` is coerced with `Number(...) || 0` and a missing `overall.progress` renders
`0` instead of `undefined`.

The whole assembled line is escaped with `esc()`. The previous code escaped only the label,
and the labels come from user-editable `lang` strings.

### 2. `precision` on `/dashboard` — `api/metrics/index.php:365-368`

Legacy reads `$round_precision = config_get('dashboard_precision')` (`:23`) and passes it to
every `getPercentage()`. `/meta/rights` already exposed it (`:139`) but nothing used it, and
`/dashboard` did not send it at all, so the client hardcoded 2 decimals. It is now sent, and
the builder falls back to `2` when absent. With the default config this is numerically
identical; it only matters for installs that tune the precision.

### 3. i18n — new key `md.statusBreakdownItem`

`"{label}: {qty} [{pct}%]"` and `"{label}: {pct}%"` (the overall-progress item), added to **all 10**
bundles (`en, de, es, fr, it, ja, pt, ro, zh`).

The pattern is **identical in every locale on purpose**: legacy concatenates the literal
`" ["` … `"%]"` (not a translatable string) for every locale, so a French-specific
`[12.5 %]` would *break* byte-identity with legacy French rather than improve it. The
existing `md.statusNotRun` / `md.statusPassed` / `md.statusFailed` / `md.statusBlocked` /
`md.overallProgress` keys were already present in every bundle and are reused. The `", "`
join separator stays a literal, exactly as in legacy (punctuation is not localized in
TestLink's own string tables).

## Verification

| check | result |
|---|---|
| flag ON, `locale=en` | `Not Run: 4 [50%], Passed: 2 [25%], Failed: 1 [12.5%], Blocked: 1 [12.5%], Overall Progress: 50%` — byte-identical to legacy |
| flag OFF | `{text:"MD Demo Plan", hasSubline:false}` — collapsed form preserved |
| flag ON, `locale=fr` | `Non Exécuté: 4 [50%], Réussi: 2 [25%], Échoué: 1 [12.5%], Bloqué: 1 [12.5%], Progression globale: 50%` |
| flag ON, `locale=ro` | `Neexecutat: 4 [50%], Reușit: 2 [25%], Eșuat: 1 [12.5%], Blocat: 1 [12.5%], Progres general: 50%` |
| rounding source | `Failed: 1 [12.5%]` — plan `active` (8) used, not `executed` (4) |
| Show all columns | 7 → 11 headers, qty columns revealed (`metricsDashboard.php:126`) |
| Reset to default state | back to 7 headers, breakdown cell unchanged |
| console | no errors, no warnings |
| `events` table | `count(*), sum(log_level<=2) where id > 22` → `0 / NULL` |
| `php -l`, `node --check`, `json.tool` ×10 | all clean |

## Fixtures used

`php tmp/fixtures_1019.php` → test project 30 `MD Demo` (prefix `MDD`, platforms disabled),
test plan 31, build 4, 8 active TCs, 4 `executions` rows (`p`, `p`, `f`, `b`).

> **Gotcha:** `executions.status` is `char(1)` and the database is **not in strict mode**, so
> inserting the verbose word `passed` is *silently truncated* to `''` instead of raising the
> error. `lib/functions/tlTestPlanMetrics.class.php:1075-1084` then folds every executed row
> into a bogus `""` counter and the BFF happily reports
> `{"":4,"not_run":4,"passed":0,"failed":0,"blocked":0}` — a **wrong but silent** payload. The
> fixture must write the codes (`p`/`f`/`b`/`n`, from `results.status_code`). This is a
> pre-existing sharp edge shared with the legacy controller and is reported separately in
> #1019 rather than fixed there.

## How to reproduce

```bash
# 1. the flag under test
sed -i '977s/= false;/= true;/'  config.inc.php   # and back to false for the OFF case
# 2. data
php tmp/fixtures_1019.php
# 3. UI
#    http://localhost:8082/gui/templates/results/metricsDashboard.html?tproject_id=<project>
#    toggle the locale with ?locale=ro to see the localized breakdown
```
