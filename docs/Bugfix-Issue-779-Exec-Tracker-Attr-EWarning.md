# Bugfix — Issue #779: execSetResults.php 4x E_WARNING (issuetype/issuepriority/version/component) on tracker-connected exec render

**Issue:** [#779](https://github.com/sebiboga/testlink-upgraded/issues/779)
**Branch:** `fix/issue-779` · **Commits:** `f18c27b53` (fix), `35a6fca6f` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-31)

## Symptom

Opening the legacy execution screen (`lib/execute/execSetResults.php`) for a test case on a
project with a **connected** issue tracker whose configuration does **not** declare the
issue-attribute keys logs 4 PHP `E_WARNING` events (log_level=2) in the Event Viewer on
EVERY render:

```
E_WARNING
Undefined property: stdClass::$issuetype     - execSetResults.php Line 1665
Undefined property: stdClass::$issuepriority - execSetResults.php Line 1665
Undefined property: stdClass::$version       - execSetResults.php Line 1675
Undefined property: stdClass::$component     - execSetResults.php Line 1675
```

This is purely event-log noise (no user-visible failure), but it pollutes the Event Viewer on
every tracker-connected execution screen.

## Root cause chain

1. `getIssueTrackerMetaData($itsObj)` — `lib/functions/exec.inc.php:944` — builds
   `$ret = [issueTypes, components, priorities, versions]`, each member set to `null` when
   the tracker lacks the corresponding `get*ForHTMLSelect()` method (exec.inc.php:960-966).
   It returns this non-null array at exec.inc.php:971 — **it never returns null for a real
   tracker instance**.
2. `execSetResults.php:1643` → `$gui->issueTrackerMetaData = getIssueTrackerMetaData(...)`
   is therefore non-null for any connected tracker.
3. `execSetResults.php:1659` → the guard `if (null != $gui->issueTrackerMetaData)` is thus a
   no-op (always true) — it protects nothing.
4. `execSetResults.php:1665` → `$gui->$attr = $itsCfg->$kj` with
   `$kj ∈ {issuetype, issuepriority}`, and `:1675` → `(array)$itsCfg->$kj` with
   `$kj ∈ {version, component}`, where `$itsCfg = $issueTracker->getCfg()` (:1624). A
   mantis/db tracker config only declares db/uri keys (`dbhost`, `dbuser`, `dbpassword`,
   `dbname`, `dbtype`, `dbcharset`, `uriview`, `uricreate`), so those 4 properties are
   undefined → **4× E_WARNING**.

**Why not a modernization regression:** this block is original TestLink 1.9.20 code — the
guard object (`issueTrackerMetaData`) and the actually-dereferenced object (`$itsCfg`) are
different structures, and the metadata is never actually null.

**Blast radius:** only `initializeGui()` in `lib/execute/execSetResults.php` (:1645-1682)
dereferences `$itsCfg->{issuetype,issuepriority,version,component}` unguarded. The consumed
`$gui->issueType/issuePriority/artifactVersion/artifactComponent` (+ForStep) are read only
by templates guarded on `$itMetaData.<attr>.items` being non-empty
(`gui/templates/dashio/execute/include/issueTrackerMetadata.inc.tpl:67-156`), so nothing is
rendered for trackers with all-null metadata. Trackers that DO define these config keys
(jira REST `lib/issuetrackerintegration/jirarestInterface.class.php:375` reads
`$this->cfg->issuetype`; jira SOAP at `jirasoapInterface.class.php:345`) must keep getting
their value.

## Approach — guard each dereference (minimal fix)

`lib/execute/execSetResults.php` `initializeGui()` else-branch now reads:

```php
} else {
    if( isset($itsCfg) && null != $gui->issueTrackerMetaData ) {
      $singleVal = [
        'issuetype' => 'issueType',
        'issuepriority' => 'issuePriority'
      ];
      foreach ($singleVal as $kj => $attr) {
        $gui->$attr = property_exists($itsCfg, $kj) ? $itsCfg->$kj : '';
        $forStep = $attr . 'ForStep';
        $gui->$forStep = $gui->$attr;
      }

      $multiVal = [
        'version' => 'artifactVersion',
        'component' => 'artifactComponent'
      ];
      foreach ($multiVal as $kj => $attr) {
        $gui->$attr = (array)(property_exists($itsCfg, $kj) ? $itsCfg->$kj : null);
        $forStep = $attr . 'ForStep';
        $gui->$forStep = $gui->$attr;
      }
    }
  }
```

`property_exists($itsCfg, $kj)` is the minimal guard that exactly maps to the "Undefined
property" warning and **preserves the value flow** for trackers that define the attributes
(property exists ⇒ identical assignment). The outer `isset($itsCfg)` additionally prevents
an undefined-`$itsCfg` fatal for the "tracker present but not connected" edge case.

**Alternatives rejected:**
- Making `getIssueTrackerMetaData()` return `null` when no attribute method exists: changes
  the shared helper's contract used by many templates (`{if '' != $itMetaData && null !=
  $itMetaData}`), a larger blast radius, and does not address the fact that the dereferenced
  object must still be guarded.
- Metadata-based guard (check `$gui->issueTrackerMetaData['issueTypes']` before each
  dereference): metadata keys and `$itsCfg` properties are distinct structures; coupling
  them is more fragile than guarding the object actually being read.

## Files changed

| File | Change |
|---|---|
| `lib/execute/execSetResults.php` | +3/-3 in `initializeGui()`: `property_exists()` guards + `isset($itsCfg)` outer gate |
| `tmp/TLU_Test_Cases.md` | +Suite 70 (5 test cases, 5/5 PASS) |

## Verification

- `php -l lib/execute/execSetResults.php` → no syntax errors.
- **Primary:** seeded `php tmp/fixtures_627.php` (TRACK627 + mantis/db MANTIS627, tcversion
  4/testcase 3/plan 11/build 1); `DELETE FROM events`; opened
  `http://localhost:8082/lib/execute/execSetResults.php?version_id=4&level=testcase&id=3&tplan_id=11&build_id=1&platform_id=0&caller=exec_feature`
  → page renders fully and `events` stays **empty** (pre-fix: 4 E_WARNING rows).
- **Regression matrix:**
  1. mantis/db tracker render → 0 events, page renders ✅ (primary)
  2. jira-style cfg (attrs present) → values still flow unchanged ✅
     (`issueType=1 issuePriority=2 artifactVersion=[11,12] artifactComponent=[21,22]`)
  3. mantis/db cfg (attrs absent) → safe defaults `''`/`[]`, no warnings ✅
  4. no tracker / tracker-not-connected → `issCfg` undefined path guarded, no fatal ✅
  5. Event Viewer shows no new Error/Warning ✅
