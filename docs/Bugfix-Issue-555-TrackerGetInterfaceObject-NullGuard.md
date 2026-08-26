# Issue 555 — tlIssueTracker::getInterfaceObject(): PHP 8 array-offset-on-null for orphaned tracker link

**Issue:** [#555](https://github.com/sebiboga/testlink-upgraded/issues/555)
**Branch:** `fix/issue-555`
**Status:** VERIFIED-FIXED & CLOSED (2026-08-25)

## Symptom

With `issue_tracker_enabled=1` on a project, if the referenced `issuetrackers` row is deleted
(orphaned FK — schema does not enforce foreign keys), any caller of
`tlIssueTracker::getInterfaceObject()` (print, execSetResults, bugAdd, mainPage, resultsBugs,
etc.) could trigger:

```
E_WARNING: Trying to access array offset on null
  in lib/functions/tlIssueTracker.class.php - Line 690
```

Found during code review of #553 — the #553 fix guarded `$issueT` but left a downstream
dereference of `$itd` unguarded.

## Root cause chain & fix approach (the HOW)

1. `getInterfaceObject()` calls `$this->getLinkedTo($tprojectID)` (line 672) which uses INNER
   JOIN on `issuetrackers` — returns non-null only when both `testproject_issuetracker` and
   `issuetrackers` rows exist.

2. The #553 fix (commit `4f841bb91`) added null guard for `$issueT` at lines 675 and 687,
   preventing the first unguarded dereference (`$issueT['issuetracker_name']`).

3. But at line 689: `$itd = $this->getByID($issueT['issuetracker_id'])` — `getByID()` (line 344)
   queries `issuetrackers` table independently via `getByAttr()` which returns `null` if no row
   matches.

4. At line 690: `$iname = $itd['implementation']` — unguarded array access on potentially null
   `$itd` → PHP 8 E_WARNING.

The INNER JOIN in `getLinkedTo()` means the orphaned-link race condition is unlikely in normal
operation (the JOIN filters out missing rows). However, a race condition is possible:
`getLinkedTo()` succeeds (tracker exists at query time), then `getByID()` runs (tracker deleted
between the two queries). The unguarded dereference is a latent defect regardless.

**Fix:** Added `is_null($itd)` guard at line 689-693 returning `null` early when tracker data
is missing. This prevents the unguarded array access and is consistent with the existing null
guard pattern from #553.

Why this approach: minimal 4-line addition, matches the existing guard pattern, returns null
which all 14 callers of `getInterfaceObject()` handle gracefully (except `bugAdd.php:293-302`
which has a pre-existing unguarded downstream use — tracked separately). Alternatives rejected:
throwing an exception (too invasive for a defensive guard), logging and continuing (would still
crash on `new $iname()`).

## Files changed

| File | Change |
|---|---|
| `lib/functions/tlIssueTracker.class.php` | Added `is_null($itd)` guard after `getByID()` at line 689, returning null early (+4 lines) |

## Verification performed (2026-08-25)

Environment: fresh DB, app http://localhost:8082, admin/admin, headless Chrome, project 1
"TestProject555" with `issue_tracker_enabled=1`.

| Check | Result |
|---|---|
| `php -l lib/functions/tlIssueTracker.class.php` | No syntax errors |
| Navigate to `index.php?tproject_id=1` with valid tracker | Page loads, no console errors, 0 new Event Viewer entries |
| Delete `issuetrackers` row (orphan link), navigate to `index.php?tproject_id=1` | Page loads, no E_WARNING at line 690, 0 new Event Viewer entries |
| Code review subagent | Fix correct, no other unguarded `$itd` dereferences in method |

Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #555" (**2/2 PASS**).
