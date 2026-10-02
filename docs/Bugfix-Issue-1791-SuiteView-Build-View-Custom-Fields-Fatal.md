# Issue 1791 — PHP 8 fatal: `Call to undefined method build::getCustomFieldsValues()` in `planView.php` and `buildView.php`

**Issue:** [#1791](https://github.com/sebiboga/testlink-upgraded/issues/1791)
**Branch:** `sebiboga` · **Fix commit:** the commit carrying `Fixes #1791`
**Screens:** Test Plan Management · Builds & Releases
**BFF:** `api/plans/index.php` · `api/builds/index.php`
**Status:** FIXED & VERIFIED (2026-10-02)
**Predecessor:** [#1787](https://github.com/sebiboga/testlink-upgraded/issues/1787), which fixed the
third and last caller (`lib/plan/buildEdit.php`) and established the redirect-shim pattern reused here
**Found while:** testing the Build Create/Edit screen (#1787) — correctly left out of that issue as
out of scope

## Symptom

`getCustomFieldsValues()` was removed from the build class during the 2.0.1 modernization (custom
fields are now served by the REST BFF from `cfield_build_design_values` /
`cfield_testplan_design_values` via `cfield_mgr`), but two of its three legacy callers were never
migrated. Both died with **HTTP 500 and a 0-byte body**:

| legacy URL | fatal (captured from the live dev server) |
|---|---|
| `lib/plan/planView.php` | `Uncaught Error: Call to undefined method testplan::getCustomFieldsValues() in lib/plan/planView.php:75` |
| `lib/plan/buildView.php?tplan_id=N` | `Uncaught Error: Call to undefined method build::getCustomFieldsValues() in lib/plan/buildView.php:94` |

Note the two messages differ: `testplan` does **not** extend `build` (it extends
`tlObjectWithAttachments`), so the plan-view fatal names `testplan::` and the build-view one names
`build::`.

## Why it survived the modernization

The call sat behind a guard in **both** controllers:

```php
$availableCF = (array)$tplan_mgr->get_linked_cfields_at_design(current($set), $gui->tproject_id);
$hasCF = count($availableCF);
...
if ($hasCF) { $cfields = (array)$build_mgr->getCustomFieldsValues($id, $gui->tproject_id); }
```

So the screen only died once the **active test project had a design-time custom field linked**. A
test project without one renders the legacy screen perfectly. That is why this is a conditional
fatal and why reproducing it needs a purpose-built fixture (`tmp/fixtures_1791.php`).

## Why both screens could simply be redirected

Both are already modernized, and the 2.0.1 aside menu already points at them — the legacy
controllers were reachable only from a stale bookmark, a wiki link, or the retired Smarty
templates:

| legacy controller | modernized screen | menu wiring |
|---|---|---|
| `lib/plan/planView.php` | `gui/templates/plans/planView.html` | `lib/functions/common.php:2193` |
| `lib/plan/buildView.php` | `gui/templates/plans/buildsView.html` (`#1723`) | `lib/functions/common.php:2220` |

No modernized `.html` page links either legacy file. The only live inbound path is
`testplan::getViewActions()` (`lib/functions/testplan.class.php:8282`), which the still-existing
legacy `planEdit.php` uses for its "back" button — that user now gets the modern screen instead of a
500, which is the intended outcome.

## The fix

Both controllers became the same redirect-only shim as `lib/plan/buildEdit.php` from #1787:
`testlinkInitPage`, a `405` guard for anything but GET/HEAD placed before any state change, a session
check, then `window.location.replace(...)` to the modernized page. No state, no legacy code path, no
write — every write goes through the BFF, which re-checks `testplan_create_build`
(`canManage()`, `api/builds/index.php:142`) and `exec_delete` (`canDeleteExec()`, `:146`) and
resolves the owning project from `build.testproject_id` on every route.

One behavioural subtlety was preserved deliberately: the legacy `planView.php` took the test project
from `$_SESSION['testprojectID']` (its `init_args()`) and **ignored `?tproject_id=` entirely** — and
because it called `testlinkInitPage($db,false,false)` it never refreshed that session value from the
URL either. The modernized page reads `?tproject_id=`, so the shim forwards the query parameter and
falls back to the session when it is absent; that is what a legacy bookmark without the parameter
used to see.

## Verification

`tmp/fixtures_1791.php` + `tmp/verify_1791.php`:

* **32/32 PASS** on the fixed code, **15 PASS / 17 FAIL** with both legacy controllers restored — the
  suite discriminates.
* Two **non-obvious reproduction steps** are pinned by the suite, because both are needed and both
  are unobvious:
  1. the session must be pointed at the fixture project *first*, via a page that passes
     `$initProject = TRUE` — the legacy `planView.php` passes `FALSE` and therefore cannot do it
     itself;
  2. `buildView.php` throws unless the URL carries a real `tplan_id`.
* Every capability of both legacy screens was audited against the modernized ones and **nothing is
  dropped**: create / edit / delete / activate / release date / notes (builds) and
  create / edit / delete / activate / export / import / assignRoles / gotoExecute (plans) all exist
  in `buildsView.html` and `planView.html`, each enforcing rights in its own BFF. The build-view
  custom-field columns were computed by the legacy controller but **never rendered** by
  `buildView.tpl`; the plan-view custom-field column **is** rendered today, so the modern screen has
  one capability the dashio legacy template lacked.
* Rights: a no-rights user gets a `200` from the shim (it leaks nothing — no name, no existence) and
  a `403` from `api/plans/`; the page itself is a static shell that carries no project name.
* Injection: the target is built from `intval()` only. Probed with `tproject_id[]=1`,
  `" onload=alert(1)`, `</script><script>`, `0x1A`, `%00`, duplicate parameters, 40-digit and
  `PHP_INT_MIN` values — every response emitted only `?tproject_id=<int>`.
* `0` new ERROR/WARNING rows in `events` **and** `0` new `PHP Fatal error` lines in the dev server's
  own stderr — the latter is the assertion that actually matters here, because a PHP fatal never
  reaches `events`.
* Browser end-to-end: `lib/plan/buildView.php?tplan_id=…` lands on
  `buildsView.html` "Builds & Releases Management" with the fixture build and all row actions, and
  `lib/plan/planView.php` lands on `planView.html` with the custom-field column rendered. No console
  errors.

## Known, deliberately NOT fixed

* **Pre-existing existence oracle in `api/builds`** (not introduced here, and the legacy page had the
  same 404-then-403 shape): `resolveTplan()` (`api/builds/index.php:67-74`) answers `404` for a
  non-existent plan *before* `canManage()` answers `403` for an existing one, so a no-rights user can
  distinguish them. `api/plans` is clean. Worth its own issue in the #1790 family.
* **The legacy chain keeps growing shims**: `testplan::getViewActions()` still names
  `lib/plan/planView.php`, and `gui/templates/tl-classic/*` plus
  `gui/templates/dashio/plan/buildEdit.tpl` still link the legacy files (those templates are
  unreachable — `TLSmarty` pins `gui/templates/dashio/`). Follow-up cleanup, cf. #1125
  *"Cleanup: delete legacy buildView"*.
* Anonymous `POST` gets the login bounce (200) rather than `405`, because `testlinkInitPage()` runs
  its session check before the shim's `405` guard. Same as the #1787 shim; harmless.