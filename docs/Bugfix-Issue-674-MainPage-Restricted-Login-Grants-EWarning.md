# Issue 674 — E_WARNING "Undefined property: stdClass::$tproject_user_role_assignment" on restricted-user login

**Issue:** [#674](https://github.com/sebiboga/testlink-upgraded/issues/674)
**Branch:** `fix/issue-674` · **Commits:** `8b17cd4cd` (fix), `f498e7aa7` (regression suite), docs (this page)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Every login of a user whose role has no per-project role grants (e.g. a fresh user bound to
the built-in `<no rights>` role 3) logged one PHP warning into the Event Viewer immediately
after a successful login:

```
E_WARNING
Undefined property: stdClass::$tproject_user_role_assignment - in lib/functions/common.php - Line 2182
```

Measured in the events table during reproduction:

```
id=4  log_level=2 (WARNING)  entry_point=/lib/general/mainPage.php
description: E_WARNING Undefined property: stdClass::$tproject_user_role_assignment ...
```

No fatal, no visible UI damage — pure Event Viewer noise on **every** such login.


## Root cause chain

1. `lib/functions/common.php:2077-2092` — `getGrantSetWithExit()` has a fast path for the
   *blind-folded* case (system has ≥1 test project but the user can access none) that builds
   the grants array ONLY from three key lists: `$systemWideRights`, `$r2cTranslate`,
   `$r2cSame`. **`tproject_user_role_assignment` is absent from all three lists**, so the
   early `return (object)$grants;` emits an object missing that key.
2. `lib/functions/common.php:1691-1707` — `initUserEnv()`: callers passing
   `forceCreateProj` (`mainPage.php:215`) still run `getMenuVisibility($gui)` while
   blind-folded.
3. `lib/functions/common.php:2185` — `getMenuVisibility()` dereferences
   `$gui->grants->tproject_user_role_assignment` unguarded inside the Projects-menu OR-chain
   → E_WARNING. Every other property it reads IS covered by the fast-path lists; this is the
   single uncovered key.
4. The NORMAL path initializes the same key to `"no"` at common.php:2130 before evaluating
   rights — which is why only restricted users hit it.

**Trigger condition (refined during investigation):** requires ≥1 test project in the system
AND an empty accessible set for the user. With zero projects the `zeroTestProjects` branch
runs the normal path; with accessible projects the normal path runs too. Only the
blind-folded state takes the fast path.

## Blast radius

- One E_WARNING per login of every role-restricted user once any project exists they cannot
  see (e.g. private projects).
- Template readers of the same grant key would warn identically if they ever received
  fast-path grants: `gui/templates/dashio/aside.tpl:100`
  (`$menuGrants->tproject_user_role_assignment`), `tl-classic/mainPageLeft.tpl:32/128`,
  usermanagement menu/tab templates. Fixing the builder shape protects all of them.
- Code review cross-checked ALL unguarded `grants->` reads across `lib/` +
  dashio templates against the union of the three key lists plus this fix: no other missing
  keys exist.

## Approach — restore the builder invariant

Chosen fix (one statement in the fast path, right after the `$r2cSame` fill loop):

```php
// getMenuVisibility() reads this key unconditionally (see issue #674):
// the fast path must return the SAME object shape as the normal path
// below, where the key is initialized at 'no' before being evaluated.
$grants['tproject_user_role_assignment'] = 'no';
```

Both return paths of `getGrantSetWithExit()` now emit identical object shapes, so every
consumer (PHP OR-chains and Smarty templates alike) sees the key.

Rejected alternatives:

- null-coalesce at the read site (`?? ''`) — hides the symptom while leaving the object-shape
  inconsistency live for template readers;
- computing the real right in the fast path — contradicts the fast path's documented purpose
  ("avoid lot of processing", all-`'no'` shape).

## Files changed

| File | Change |
|---|---|
| `lib/functions/common.php` | fast-path grants shape: initialize `tproject_user_role_assignment = 'no'` (+ comment) |
| `tmp/TLU_Test_Cases.md` | Suite 674 regression entry (6-case matrix) |

## Verification

| Case | Setup | Expected post-fix | Observed |
|---|---|---|---|
| R1 primary | restricted login, private project present | audits only, no E_WARNING | ids 5/6 then 16/17: audits only ✓ |
| R2 causality | ORIGINAL file swapped back in, same login | warning re-fires | id=9 fired again → fix proven causal ✓ |
| R3 admin normal path | admin login, project present | menus unchanged, clean | aside liCount=4, audits only ✓ |
| R4 zero projects | restricted login, no projects | zeroTestProjects branch intact | System/Projects/Documentation render ✓ |
| R5 aside parity | blind-folded state A/B | identical rendering pre/post fix | liCount=0 both variants — log-only change ✓ |
| R6 Event Viewer | after all flows | no new Error/Warning | empty result set ✓ |

Suite 674 in `tmp/TLU_Test_Cases.md`: **6/6 PASS**.


## Notes for future work

- Pre-existing latent fragility spotted by review (untouched, no runtime symptom today):
  the normal path of `getGrantSetWithExit()` passes undefined locals `$dbH`/`$db`
  (common.php:2131/2133) into `hasRight(&$db,...)`; silently null-vivified because the
  parameter is by-reference. Worth a cleanup during a future refactor of this function.
