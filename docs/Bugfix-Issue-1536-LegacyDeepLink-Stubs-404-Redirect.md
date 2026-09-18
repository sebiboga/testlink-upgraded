# Issue 1536 — reqSpecView.php/archiveData.php: 7x E_WARNING on legacy deep-link views + broken stub redirect target (404)

**Issue:** [#1536](https://github.com/sebiboga/testlink-upgraded/issues/1536)
**Branch:** `fix/issue-1536`
**Status:** VERIFIED-FIXED

## Symptom

Two legacy controllers remain reachable by direct deep link and each one used to
render the old dashio smarty stack whose templates read four kernel-level props
(`$gui->tproject_id`, `$gui->tplan_id`, `$gui->tprojOpt->testPriorityEnabled`,
`$gui->tprojOpt->automationEnabled`) that the modern `initUserEnv()` no longer
populates — 6-7 E_WARNING entries in the `events` table (Event Viewer) on every
view:

- `lib/requirements/reqSpecView.php?req_spec_id=<id>`
- `lib/testcases/archiveData.php?edit=testcase&id=<id>`

Surfaced by the #1533 fix (linkto.php deep links no longer 500).

## First fix (stub the controllers) — and the regression it introduced

Commit `36783cb57` turned both controllers into 302 redirect stubs pointing at
their modern twins. This closed the E_WARNING family (no legacy template is ever
compiled on this path), but the `Location` header used a **relative** target:

```php
header('Location: gui/templates/requirements/reqSpecView.html?...');
header('Location: gui/templates/testcases/tcEdit.html?...');
```

The browser resolves such a location against the *current* directory, so the
requests became:

- `/lib/requirements/gui/templates/requirements/reqSpecView.html` → **404**
- `/lib/testcases/gui/templates/testcases/tcEdit.html` → **404**

A direct deep link therefore landed on a PHP-built-in-server 404 page instead
of the modern screen. The original verification only asserted `302` was
returned; it never followed the `Location` to its final 200/404.

## Exact repro (pre-fix)

1. Log in admin at http://localhost:8082.
2. `curl -L http://localhost:8082/lib/requirements/reqSpecView.php?req_spec_id=2&tproject_id=1`
   → `302 Location: gui/templates/requirements/reqSpecView.html?...` → resolves to
   `/lib/requirements/gui/templates/requirements/reqSpecView.html` → **404**
   (console: `Failed to load resource: 404 (Not Found)` ×3).
3. `curl -L http://localhost:8082/lib/testcases/archiveData.php?edit=testcase&id=4&tproject_id=1`
   → `302 Location: gui/templates/testcases/tcEdit.html?...` → resolves to
   `/lib/testcases/gui/templates/testcases/tcEdit.html` → **404** (console 404 ×2).

**Expected:** landing on the modern Requirement Specification Viewer resp. Test
Case Editor (HTTP 200), with zero E_WARNING in the `events` table.

## Root cause

RFC 7231: a `Location` value that is not an absolute URI is resolved relative to
the target of the current request. With the stub controllers living under
`lib/requirements/` and `lib/testcases/`, the relative target `gui/templates/...`
is prefixed with those directories. The modern Dashio screens' *own* internal
navigation hardcodes the root-relative prefix instead —
`gui/templates/requirements/reqSpecView.html:315` uses
`href = '/gui/templates/requirements/reqReorder.html?...'` and
`gui/templates/testcases/tcView.html:502` uses
`'/gui/templates/testcases/tcEdit.html?...'` — so the correct stub redirect is
root-relative (`/gui/templates/...`).

Blast radius: exactly the two stub controllers; they are the only
`header('Location: gui/templates/...')` callers in the repo (grep). `linkto.php`
already redirects to modern screens correctly with an absolute
`basehref + gui/templates/...` URI (linkto.php:85-90, 161-166).

## Fix (minimal)

Prepend `/` to the two redirect targets so the browser resolves them from the
webroot:

```diff
- header('Location: gui/templates/requirements/reqSpecView.html?req_spec_id=' .
+ header('Location: /gui/templates/requirements/reqSpecView.html?req_spec_id=' .
```

```diff
- header('Location: gui/templates/testcases/tcEdit.html?doAction=edit&tcase_id=' .
+ header('Location: /gui/templates/testcases/tcEdit.html?doAction=edit&tcase_id=' .
```

**Alternatives considered and rejected:**
- Absolute `basehref + ...` (like linkto.php) — requires bootstrapping the
  session/common stack inside the stub, defeating the stub's purpose; root-relative
  matches the modern screens' own JS convention and works regardless of session.
- `../../gui/templates/...` — correct but brittle if the install path ever moves;
  root-relative is the codebase's established convention for these screens.

## Regression matrix (all executed, live)

| # | Case | Result |
|---|---|---|
| 1 | `curl -L reqSpecView.php?req_spec_id=2&tproject_id=1` | **302 → 200**, final `/gui/templates/requirements/reqSpecView.html`, `<title>Requirement Specification Viewer</title>` |
| 2 | `curl -L archiveData.php?edit=testcase&id=4&tproject_id=1` | **302 → 200**, final `/gui/templates/testcases/tcEdit.html`, `<title>Test Case Editor</title>` |
| 3 | Browser navigation of both deep links (Chrome, admin session) | Modern screens render, no 404 page |
| 4 | Direct root-relative twin hits (`/gui/templates/...`) | 200 |
| 5 | `events` table after all hits | zero new Error/Warning (only `audit_login_succeeded` log_level=16) |
| 6 | grep for relative `Location: gui/templates` | no occurrences |
| 7 | Syntax gates | `php -l` clean on both stubs |

Screenshots: `docs/screenshots/issue-1536-reqspecview-404.png`,
`issue-1536-archive-404.png` (pre-fix), `issue-1536-reqspecview-fixed.png`,
`issue-1536-archive-fixed.png` (post-fix).

## Side findings

The original 7× E_WARNING family this issue was opened for is confirmed gone —
no legacy dashio template is compiled on the redirected path (events table clean).
The remaining defect was the redirect landing zone, fixed as above.