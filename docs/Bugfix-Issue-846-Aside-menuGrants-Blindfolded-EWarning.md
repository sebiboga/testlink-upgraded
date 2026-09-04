# Issue #846 — ASIDE menu E_WARNING "Undefined property: stdClass::$configuration" for blindfolded (no-rights) users

**Issue:** [#846](https://github.com/sebiboga/testlink-upgraded/issues/846)
**Commit:** `aa365fca4`
**Status:** VERIFIED-FIXED (2026-09-04)

## Symptom

Logging in as a user whose effective role is the `<no rights>` role (role_id 3), when
one or more test projects exist but none are accessible to that user, the Dashio shell
threw a PHP E_WARNING on the main page:

```
E_WARNING
Undefined property: stdClass::$configuration - in
gui/templates_c/<hash>_0.file.aside.tpl.php - Line 135
```

Recorded in the `events` table (`log_level=2`).

## Investigation

### Files examined

1. `gui/templates/dashio/aside.tpl:53-312` — sidebar template reads
   `$menuGrants-><prop>` **unguarded** on ~24 properties (`configuration` at line 87,
   plus `modify_tc`, `view_tc`, `testplan_execute`, `exec_ro_access`,
   `mgt_testplan_create`, `keyword_assignment`, `monitor_req`, etc.).
2. `lib/functions/common.php:1722-1746` — `initUserEnv()` "userIsBlindFolded" branch
   builds `$gui->grants` by hand with only a **13-key subset**.
3. `lib/functions/tlsmarty.inc.php:599-603` — `addMenuContext()` copies that object as
   `menuGrants`; because it is a non-null object, the complete `emptyMenuGrants()`
   fallback is bypassed.

### Code flow analysis

```
initUserEnv() (common.php)
  - userIsBlindFolded = prjQtyWholeSystem>0 AND user's prjSet empty
  - blindfolded branch → $gui->grants = partial 13-key stdClass   ← BUG (before fix)

TLSmarty::addMenuContext() (tlsmarty.inc.php:599)
  - copies partial object to menuGrants (bypasses emptyMenuGrants fallback)

aside.tpl (53-312)
  - $menuGrants->configuration (line 87), etc. → E_WARNING for each undefined property
```

Note: a global `<no rights>` user with a working `get_accessible_for_user()` actually
sees **all** projects (the private/public filter is skipped for role id 3), so the bug
manifests on the **blindfolded** path — projects exist but the rights-less user has no
accessible project (e.g. project-level no-rights role on inaccessible/private projects).

## Root cause chain

1. `common.php:1730-1745` — the blindfolded branch hard-codes a partial grants object
   that omits `configuration`, `modify_tc`, `view_tc`, `testplan_execute`,
   `exec_ro_access`, `mgt_testplan_create`, `keyword_assignment`, `monitor_req`, etc.
2. `aside.tpl:87` (+ ~23 others) — unguarded `$menuGrants-><prop>` read.
3. PHP `error_reporting(E_ALL)` (config.inc.php) turns every such read into an E_WARNING.

The two other grant sources — `getGrantSetWithExit()` (common.php:2049, complete 47-key
object) and `TLSmarty::emptyMenuGrants()` (tlsmarty.inc.php:623, complete 29-key object)
— both return the full key set, but the blindfolded branch did not.

## Fix approach

Seeded the blindfolded grants object from `TLSmarty::emptyMenuGrants()` (all 23 menu
keys defaulted to `"no"` plus the two inventory int keys — exactly the shape
`aside.tpl` reads), then overlaid the same 11 system-wide `"yes"` entries the branch
already granted (so a rights-less user still reaches System/Projects and can request
access). `common.php` already `require_once`s `tlsmarty.inc.php` (line 46), so the
static method is available.

### Why this approach

- Fixes the root cause (incomplete object shape) rather than adding 24 `isset()` guards
  to the template.
- Guarantees a complete object on every path feeding the ASIDE menu
  (`getGrantSetWithExit`, `emptyMenuGrants`, and now the blindfolded branch).
- Purely additive: no menu item that was shown before is hidden, none that was hidden
  is now shown (all added keys are `"no"`).

### Rejected alternatives

1. **Add `isset()` guards to aside.tpl** — larger diff, weaker (template-level) defense,
   and inconsistent with the complete-object guarantee already used everywhere else.
2. **Route through `getGrantSetWithExit()`** — would lose the intentional system-wide
   `"yes"` grants the blindfolded branch grants a rights-less user (behavior change).

## Files changed

| File | Change |
|---|---|
| `lib/functions/common.php` | `$gui->grants = new stdClass()` → `$gui->grants = TLSmarty::emptyMenuGrants()` in the blindfolded branch (+ explanation comment) |
| `tmp/TLU_Test_Cases.md` | Added regression suite `Regression — Issue #846` (TC-846.1–846.7) |

## Verification performed (2026-09-04)

| # | Check | Result |
|---|---|---|
| 1 | `php -l lib/functions/common.php` — no syntax errors | PASS |
| 2 | Repro (TP1 exists, norights_norepro, blindfolded): no `Undefined property: stdClass::$configuration` events | PASS |
| 3 | Repro: `menuGrants` now 29 properties incl. `configuration`, `modify_tc`, `view_tc`, `testplan_execute` | PASS |
| 4 | Blindfolded user still sees System + Projects menus | PASS |
| 5 | Normal admin path: complete 47-key grants, no regression | PASS |
| 6 | Zero-projects path (zeroTestProjects branch) unchanged | PASS |
| 7 | Event Viewer: zero new Error/Warning `Undefined property` rows | PASS |

### RESUME block

To re-test: create a test project, create a `<no rights>` user with no project role,
log in and open the Dashio shell; check `SELECT * FROM events WHERE log_level>=2` — no
`Undefined property: stdClass::$configuration` (or any other `menuGrants` property)
rows should be produced.

## Notes

- The `Undefined property: stdClass::$plugins` warning seen in some probing harnesses
  is *not* this bug: `asideMenu.php` (line 250-257) always populates `$gui->plugins`;
  only a minimal render harness that omits it triggers it.
- No i18n/HTML/JS was touched — this is a pure backend grants-shape fix.
