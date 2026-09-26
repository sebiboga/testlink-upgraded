# Task 975 — per-type configuration template loader (`getCfgTemplate`) in the modern Code Tracker edit modal

**Issue:** [#975](https://github.com/sebiboga/testlink-upgraded/issues/975)
**Status:** IMPLEMENTED & VERIFIED (2026-09-26) — branch `task/issue-975`
**Screen:** `gui/templates/codetracker/codetrackerView.html`
**BFF:** `api/codetracker/index.php`

## The gap

In TestLink 1.9.20 the code-tracker **edit** page carried an `fa-eye` icon next to the
`Configuration` label. Clicking it loaded the XML configuration example **of the interface
currently selected in the Type combo** and injected it under the textarea; clicking it
again cleared the block.

| Step | Legacy code | What it does |
|---|---|---|
| 1 | `gui/templates/dashio/codetrackers/codeTrackerEdit.tpl:194-196` | `<a title="{$labels.show_hide_config_example}" href="javascript:displayCfgExample('type','cfg_example')"><i class="fas fa-eye"></i></a>` |
| 2 | `codeTrackerEdit.tpl:26-66` | `displayCfgExample()` — clear/refresh toggle (the `innerText.trim()` branch at :37-52) + `Ext.Ajax.request` to `lib/ajax/getcodetrackercfgtemplate.php?type=N`, then `document.getElementById(displayOID).innerHTML = obj['cfg']` |
| 3 | `lib/ajax/getcodetrackercfgtemplate.php:18-40` | `getTypes()` → `isset($ctt[$type])` → `getImplementationForType($type)` → `stream_resolve_include_path($cname.'.class.php')` → `$cname::getCfgTemplate()` wrapped in `<pre><xmp>` |
| 4 | same file, `:35` | missing interface class → `lang_get('codetracker_interface_not_implemented')` |
| 5 | same file, `:39` | unknown/disabled type → `lang_get('codetracker_invalid_type')` |
| 6 | `lib/codetrackerintegration/githubrestCodeTrackerInterface.class.php:507` | GitHub template: `<repository>/<branch>/<token>` |
| 7 | `lib/codetrackerintegration/stashrestInterface.class.php:415` | Stash template: `<username>/<password>/<uribase>/<uriapi>/<uriview>/<projectkey>` |
| 8 | `lib/codetrackerintegration/codeTrackerInterface.class.php:317-320` | abstract base throws `RuntimeException("Unimplemented - YOU must implement it in YOUR interface Class")` |

The 2.0.1 rewrite dropped steps 1–5 and replaced them with **one hardcoded** example
block, always the same:

```xml
<codetracker>
  <uribase>https://git.example.com/</uribase>
  <apikey>your-token</apikey>
</codetracker>
```

Measured on the live database, that block matches **neither** enabled interface: GitHub
(`code=200`, the default) needs `repository`/`branch`/`token`, Stash (`code=1`) needs
`username`/`password`/`uribase`/`uriapi`/`uriview`/`projectkey`. It also contradicted the
modal's own behaviour, since `buildCfgXml()` emits `repository/branch/token` for GitHub
and only falls back to `uribase`/`apikey` for the generic branch.

## What was implemented

### 1. BFF route — `GET /api/codetracker/index.php/cfg-template?type=N`

`api/codetracker/index.php:291-327`, placed with the other non-numeric routes and ahead
of the numeric `GET /{id}` detail route.

```php
$ctt = $mgr->getTypes();                 // ENABLED types only
if (isset($ctt[$type])) {
    $iname = $mgr->getImplementationForType($type);
    if (stream_resolve_include_path($iname . '.class.php') !== false) {
        if (!class_exists($iname, false)) { require_once($iname . '.class.php'); }
        out(['status' => 'ok', 'type' => $type, 'template' => $iname::getCfgTemplate()]);
    }
    out(['status' => 'error', 'code' => 'interface_missing', 'iface' => $iname]);
}
out(['status' => 'error', 'code' => 'invalid_type', 'type' => $type]);
```

Two deliberate choices:

* **`getTypes()` parity.** The legacy `isset($ctt[$type])` test means a *disabled* type
  falls into the "invalid type" branch, so the route reproduces that instead of accepting
  any `$systems` entry.
* **`stream_resolve_include_path()` before any `include`.** Legacy probes the file before
  touching the class, which is why a missing implementation never surfaces an
  autoloader `include_once()` failure. Probing with `class_exists()` instead would let the
  autoloader emit an E_WARNING into the `events` table (measured on the sibling
  issuetracker BFF in issue #965: 2 WARNING rows). The `events` check after this run
  reports **0** ERROR/WARNING rows.
* The JSON BFF has no `lang_get()`, so the two legacy localized fallbacks are returned as
  structured codes and localized client-side — the same decision already taken for
  `GET /api/issuetracker/index.php/cfg-template`.

### 2. Screen

`gui/templates/codetracker/codetrackerView.html`

* `#btnCfgExample` — the legacy eye, next to the `Configuration (XML)` label.
* A second eye next to an always-visible `Configuration example` label (same
  `toggleCfgTemplate()` handler).
* `#cfgExampleOuter` / `#cfgExample` replace the old inline `<pre>`.
* `loadCfgTemplate(done)` renders with `.text()`, so raw XML is displayed verbatim
  (`<xmp>` parity, and no markup injection from an interface template).
* `toggleCfgTemplate()` is the port of the legacy clear/refresh branch: visible → hide,
  hidden → load for the current Type, then show.
* `onTypeChange()` reloads a **shown** example, so the sample always matches the selected
  interface (the issue's suggested fix).
* The block is reset to collapsed by `showCreateModal()`, `editTracker()` and a
  `hidden.bs.modal` handler.

**Design decision.** The example block is deliberately placed **outside** `#genericFields`.
The first attempt put it inside, and the browser test caught the consequence
immediately: `onTypeChange()` hides `#genericFields` for the GitHub interface, so the
block's parent computed `display: none` and the example was unreachable for the *default*
type — defeating the point of the issue. Legacy always rendered the Configuration field;
the modern modal needs the always-visible variant to reach parity for GitHub.

### 3. i18n

Five new keys, added to **all ten** locale bundles (`en, ro, de, es, fr, it, ja, pt, ru, zh`):

| Key | en value |
|---|---|
| `ct.cfgExample` | Configuration example |
| `ct.showHideConfigExample` | Show/Hide Config Example |
| `ct.loading` | Loading... |
| `ct.msg.interfaceMissing` | Code Tracker interface {iface} is not implemented/available |
| `ct.msg.errorTemplate` | Failed to load configuration example |

`ct.msg.invalidType` already existed (it was introduced for the unknown-type degradation in
issue #1597) and is reused, mirroring the issuetracker screen.

## Verification

Suite **#975** in `tmp/TLU_Test_Cases.md` — **9/9 PASS**.

| # | Check | Result |
|---|---|---|
| 1 | `?type=200` / `?type=1` return each interface's own template | PASS |
| 2 | `?type=99` → `invalid_type`, rendered as `Code Tracker type 99 is unknown.` | PASS |
| 3 | Create modal opens collapsed; eye loads the GitHub template | PASS |
| 4 | Switching Type to `stash` reloads with the 6-key Stash shape, no `<repository>` left | PASS |
| 5 | Eye toggles show → hide → show | PASS |
| 6 | Modal lifecycle (create / edit / close) resets the block | PASS |
| 7 | 10/10 bundles valid; `ro_RO` renders Romanian labels and messages | PASS |
| 8 | Regression: grid, GitHub connection test, save/edit round trip | PASS |
| 9 | `events` ERROR/WARNING = 0; browser console clean | PASS |

Screenshot: `docs/screenshots/issue-975-codetracker-cfg-template.png` — the create modal
with the per-type GitHub example loaded.
