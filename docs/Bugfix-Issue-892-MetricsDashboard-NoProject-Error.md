# Bugfix — Issue #892: Metrics Dashboard shows bare "Error" when no test project is selected

## Problem

Opening the Metrics Dashboard (`gui/templates/results/metricsDashboard.html`) when
**no test project is selected** (empty/fresh install, or before picking a project
in the top bar) renders a red box whose only content is the bare string **"Error"**.
No guidance is given to the user about why the screen cannot load.

## Root Cause

The screen's `loadDashboard()` requests `GET /api/metrics/index.php/dashboard`.
When no project is selected, the front-end sends no `tproject_id` (empty, falsy —
`metricsDashboard.html:174-178`), the BFF falls back to the session project
(`api/metrics/index.php:53-60`) which is `0`, and the `/dashboard` route answers
`400 {"status":"error","message":"No test project selected"}` (line 91-95). This
mirrors the legacy controller, which throws `Exception("Invalid Test Project ID")`
in the same situation (`lib/results/metricsDashboard.php:388-392`).

The defect was purely on the client side: the `.fail()` handler only
distinguished `403` (`md.msg.noRights`) from the generic fallback
`common.error` — the literal "Error" — and silently discarded the server's
actionable message:

```js
// before (metricsDashboard.html:197-199)
}).fail(function(xhr) {
  showMsg('#errorBox', xhr.status === 403 ? 'md.msg.noRights' : 'common.error');
});
```

## Fix

Applied in commit `98c5ac709` on branch `fix/issue-892`.

1. **i18n** — new key `md.msg.noProject` added to all 10 locale bundles
   (`de, en, es, fr, it, ja, pt, ro, ru, zh`), English value:
   *"No test project selected. Please select a test project first."*
2. **Frontend** — the `.fail()` handler maps the API's `400` to the new key,
   keeping the existing `403` and generic fallbacks untouched:

```js
// after
}).fail(function(xhr) {
  var key = 'common.error';
  if (xhr.status === 403) { key = 'md.msg.noRights'; }
  else if (xhr.status === 400) { key = 'md.msg.noProject'; }
  showMsg('#errorBox', key);
});
```

Why this method: it is the smallest change that restores the app's established
pattern for the no-project state (the same `400 → localized guidance` mapping
already used by `cfieldsAssignView.html:310` with `cfa.msg.noProject`, and
`printReq.noProject` on other screens). No BFF change was needed — the API
contract is correct and stable. Alternative rejected: skipping the API call
entirely when `tproject_id` is empty would have changed load behaviour beyond
the reported symptom and duplicated the server-side decision.

## Files Changed

- `gui/templates/i18n/de.json`, `en.json`, `es.json`, `fr.json`, `it.json`,
  `ja.json`, `pt.json`, `ro.json`, `ru.json`, `zh.json` (+1 key each)
- `gui/templates/results/metricsDashboard.html` (`.fail()` handler)

## Verification

- `tproject_id=0` → red box now shows the localized guidance instead of "Error"
  (verified in English and in Română).
- `tproject_id=1` with seeded project/plan/build/executions → full dashboard
  still renders (progress bars, plan table, "Generated on" footer).
- All 10 locale bundles pass `python3 -m json.tool` and contain `md.msg.noProject`.
- `events` table shows no new ERROR/WARNING entries during the test window.
- Regression suite TC-892 appended to `tmp/TLU_Test_Cases.md`, 8/8 PASS.

Screenshots: `docs/screenshots/issue-892-no-project-message-en.png`,
`docs/screenshots/issue-892-no-project-message-ro.png`.