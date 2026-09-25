# Task 973 — Environment (`checkEnv`) column in the modern Code Trackers grid

**Issue:** [#973](https://github.com/sebiboga/testlink-upgraded/issues/973)
**Status:** IMPLEMENTED & VERIFIED (2026-09-25) — branch `task/issue-973`
**Screen:** `gui/templates/codetracker/codetrackerView.html`
**BFF:** `api/codetracker/index.php`

## The gap

TestLink 1.9.20 showed an **Environment** column in the Code Tracker list: for every
configured tracker it reported whether the *PHP environment* can actually serve that
tracker's interface (extension/library availability) — `OK`, or a human message such as
*"cURL extension is required for the GitHub code tracker interface"*.

Legacy path, in order:

| Step | Legacy code | What it does |
|---|---|---|
| 1 | `lib/codetrackers/codeTrackerView.php:23` | `$gui->items = $codeTrackerMgr->getAll(array('output' => 'add_link_count', 'checkEnv' => true));` — the `checkEnv` option is the **only** trigger of the probe |
| 2 | `lib/functions/tlCodeTracker.class.php:527` | `checkEnv` defaults to `false` in the option map |
| 3 | `lib/functions/tlCodeTracker.class.php:562-563` | every item seeded with `env_check_ok = true`, `env_check_msg = ''` |
| 4 | `lib/functions/tlCodeTracker.class.php:566-572` | when enabled: `$impl = getImplementationForType($type); $dummy = $impl::checkEnv();` → `env_check_ok` / `env_check_msg` |
| 5 | `lib/codetrackerintegration/codeTrackerInterface.class.php:325-332` | base `checkEnv()` → `['status' => true, 'msg' => 'OK']` |
| 6 | `lib/codetrackerintegration/githubrestCodeTrackerInterface.class.php:523-532` | override: `!function_exists('curl_init')` → `status=false`, `msg='cURL extension is required for the GitHub code tracker interface'` |
| 7 | `gui/templates/dashio/codetrackers/codeTrackerView.tpl:42` | `<th>{$labels.th_codetracker_env}</th>` — locale string `$TLS_th_codetracker_env = 'Environment'` (`locale/*/strings.txt`) |
| 8 | `gui/templates/dashio/codetrackers/codeTrackerView.tpl:74` | `<td class="clickable_icon">{$item_def.env_check_msg|escape}</td>` |

The 2.0.1 rewrite dropped steps 1–8 and replaced the column with a **Server URL** column:

- `api/codetracker/index.php` list route called `getAll(['output' => 'add_link_count'])` —
  `checkEnv` was omitted, so it stayed `false` and the probe block never ran.
- `trackerToJSON()` returned no `env_check_*` key at all.
- The grid had 5 columns (`Name | Type | Server URL | Active | Actions`) and no
  Environment column.

Notably, the **sibling screens already had the feature** — `api/issuetracker/index.php:100-101`
and `gui/templates/issuetracker/issuetrackerView.html:246-256` implement exactly this
pattern. The asymmetry was the bug.

## Implementation

### 1. BFF — run the probe and forward it

`api/codetracker/index.php`, list route:

```php
// checkEnv parity with legacy codeTrackerView.php:23 — the per-tracker
// environment probe ($impl::checkEnv(), tlCodeTracker.class.php:566-572)
// runs here so env_check_ok/env_check_msg reach the grid's Environment
// column. Omitting it silently downgrades every row to "OK" (issue #973).
$all = $mgr->getAll(['output' => 'add_link_count', 'checkEnv' => true]);
```

`trackerToJSON()` gained two keys next to `link_count`:

```php
'env_check_ok'  => (bool)($item['env_check_ok'] ?? true),
'env_check_msg' => (string)($item['env_check_msg'] ?? ''),
```

`trackerToJSON()` is shared by the list, GET-by-id, POST, PUT and DELETE responses, so
every route carries the fields. The `?? true` / `?? ''` fallbacks mirror
`tlCodeTracker.class.php:562-563` and keep the routes that do not request `checkEnv`
well-defined (they use `getByID()`), instead of dropping the key. The UI only renders the
GET list, so those fallbacks are never displayed.

### 2. Screen — render the column

`gui/templates/codetracker/codetrackerView.html`:

- CSS `.badge-env-ok` (green `#3a9c5c`) / `.badge-env-ko` (red `#e6605e`), copied from
  `issuetrackerView.html:28-29` so both integration grids look the same.
- `<th data-i18n="ct.environment">Environment</th>` between Server URL and Active.
- In `renderTable()`:

```js
var envOk = t.env_check_ok !== false;
var envMsg = t.env_check_msg ? esc(t.env_check_msg) : '';
var envHtml;
if (envOk) {
  envHtml = '<span class="badge-env-ok" title="' + (envMsg || TLi18n.t('ct.envOk')) + '">'
          + TLi18n.t('ct.envOk') + '</span>';
} else {
  envHtml = '<span class="badge-env-ko" title="' + TLi18n.t('ct.envKo') + '">'
          + (envMsg || TLi18n.t('ct.envKo')) + '</span>';
}
```

  OK → compact green `OK` badge with the implementation's message as **tooltip**;
  KO → red badge whose **label** is the human failure message (the only information a
  failing check has), tooltip = the localized "Environment check failed".
- Row arrays grew 5 → 6 entries, the DataTables `columns` map grew a `null`, and
  `table.column(4).visible(canManage)` became `table.column(5).visible(canManage)` so the
  view-only-user collapse still targets the moved Actions column.

`env_check_msg` is passed through the existing `esc()` helper (issue #1581) — it is a
constant string from the implementation class, but server text is never trusted as HTML.

### 3. i18n

Three new keys in **all 10** bundles (`en, de, es, fr, it, ja, pt, ro, ru, zh`):
`ct.environment`, `ct.envOk`, `ct.envKo`. The bundles mirror the already-shipped
`it.environment` / `it.envOk` / `it.envKo` values. The failing check's own text is
deliberately **not** translated — it comes verbatim from `checkEnv()`, exactly as legacy
rendered `{$item_def.env_check_msg|escape}`.

| bundle | `ct.environment` | `ct.envOk` | `ct.envKo` |
|---|---|---|---|
| en | Environment | OK | Environment check failed |
| de | Umgebung | OK | Umgebungsprüfung fehlgeschlagen |
| es | Entorno | OK | Falló la verificación del entorno |
| fr | Environnement | OK | Échec de la vérification de l'environnement |
| it | Ambiente | OK | Controllo ambiente non riuscito |
| ja | 環境 | OK | 環境チェックに失敗しました |
| pt | Ambiente | OK | Falha na verificação do ambiente |
| ro | Mediu | OK | Verificarea mediului a eșuat |
| ru | Окружение | OK | Проверка окружения не удалась |
| zh | 环境 | OK | 环境检查失败 |

## Why Server URL was kept

The issue allowed either "keep the Server URL addition" or "merge into the same cell".
Merging was rejected: `editTracker()` prefills `#editServerUrl` from `t.serverUrl`
(`codetrackerView.html:346`), and Server URL is a deliberate 2.0.1 addition with no legacy
counterpart. The result is a **superset**: legacy Environment column *and* the modern
Server URL column.

## Rights behaviour

The column is visible to **view-only** users, exactly like legacy: `th_codetracker_env`
(`codeTrackerView.tpl:42`) sits outside every `{if $gui->canManage}` block. The probe is a
credential-free `function_exists()` test, so exposing it needs no management right. The
#1576 gate on the raw `cfg` (which may hold a plaintext `<token>`) is untouched and stays
management-only.

## Verification

Fixtures — `tmp/fixtures_973.php` (re-runnable), seeded through the **real** save path
`tlCodeTracker::create()`: a GitHub tracker (type 200) and two Stash trackers (type 1),
plus a view-only user `ctview973` / `viewonly` (role 900 with right 52
`codetracker_view` only).

The KO branch is exercised end-to-end on a second instance started with
`php -d disable_functions=curl_init -S 127.0.0.1:8085 -t .` — precisely the condition
`githubrestCodeTrackerInterface::checkEnv()` tests. On that instance the two grids differ
per row while server, screen and data are identical:

| tracker | interface | :8082 (cURL present) | :8085 (`disable_functions=curl_init`) |
|---|---|---|---|
| GitHub Main | `githubrestCodeTrackerInterface` | `.badge-env-ok` "OK" | `.badge-env-ko` "cURL extension is required for the GitHub code tracker interface" |
| Stash Generic | `stashrestInterface` | `.badge-env-ok` "OK" | `.badge-env-ok` "OK" |
| Stash Second | `stashrestInterface` | `.badge-env-ok` "OK" | `.badge-env-ok` "OK" |

`Suite 973` in `tmp/TLU_Test_Cases.md` records **13/13 PASS**:

1. Environment column present and localized (6 columns, 6 cells).
2. OK state renders a green badge per row.
3. KO state renders the implementation's message, per row.
4. KO badge tooltip is the localized `ct.envKo` fallback.
5. Server URL column preserved (no regression).
6. Create/Edit modal still prefills.
7. Action icons still land in the last (Actions) column after the index shift.
8. Search and sort still work with the extra column.
9. View-only user still sees the Environment column, Actions/Create stay hidden.
10. No credential leak — `cfg` still blank for non-managers.
11. create/delete round-trip keeps the column consistent.
12. i18n coverage: 3 keys × 10 bundles, all valid JSON, pure additions.
13. Event Viewer clean (only AUDIT rows), console clean on both instances.

