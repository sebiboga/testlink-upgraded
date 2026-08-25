# Issue 496 — REST interfaces: unhandled exception on missing credentials (Stash/JIRA) clutters Event Viewer

**Issue:** [#496](https://github.com/sebiboga/testlink-upgraded/issues/496)
**Branch:** `fix/issue-496` · **Commits:** `3fd58c58f` (jira), `22bfea31c` (stash + matrix verification)
**Status:** VERIFIED-FIXED & CLOSED (2026-08-25)

## Symptom

Event Viewer entry reported in the issue (2026-08-18 09:27:24):

```
stashrestInterface::connect Missing or Empty username - unable to continue
```

Incomplete/disabled integrations (Stash, JIRA) threw unhandled exceptions on every screen that
instantiates a tracker, writing ERROR rows into the Event Viewer.

## Root cause chain

1. `tlIssueTracker::getInterfaceObject()` (`lib/functions/tlIssueTracker.class.php:670-698`) and
   `tlCodeTracker::getInterfaceObject()` instantiate the interface class for whatever tracker is
   linked to the active test project — no credential validation there.
2. `__construct` calls `connect()`; pre-fix `connect()` went straight into
   `(string)trim($this->cfg->username)` then `new StashApi\Stash(...)` / `new JiraApi\Jira(...)`.
3. Both third-party constructors throw `Exception('Missing or Empty username - unable to continue')`
   for empty credentials (`third_party/stash-rest/Stash.php:65`, `third_party/fayp-jira-rest/Jira.php:70`);
   the surrounding `catch(Exception)` logged it via `tLog(...,'ERROR')`.
4. **Fix wave 1** — commit `38c7d6f16` added an emptiness guard before client instantiation
   (silent skip, `$this->connected = false`). That killed the ERROR rows but was INCOMPLETE under PHP8:
   - cfg trees are produced by `json_decode(json_encode($simplexml))`
     (`issueTrackerInterface.class.php:162`, `codeTrackerInterface.class.php:147`), so:
     - a MISSING `<username>` element → undefined stdClass property → **E_WARNING per instantiation**
       (measured: 4 warnings across the two files);
     - an EMPTY element `<username></username>` → property-less `stdClass{}` → `(string)trim(stdClass)`
       = **uncaught TypeError, fatal white page** (TypeError is not an `Exception`, so it bypassed the catch).

## Fix approach

Minimal, symmetric change in both `connect()` methods — extract credentials through an explicit
guard so the existing silent-skip logic runs for every incomplete variant:

```php
$username = '';
if (isset($this->cfg->username) && is_string($this->cfg->username)) {
    $username = trim($this->cfg->username);
}
// identical block for $password
```

Files: `lib/issuetrackerintegration/jirarestInterface.class.php:145-157`,
`lib/codetrackerintegration/stashrestInterface.class.php:99-111`.

Rejected alternatives: catching `\Throwable` around the whole connect body (hides future bugs,
wider blast radius); validating in the manager factory (duplicates logic across ~20 call sites).

**Runtime gotcha encoded as a comment:** PHP 8.3.33 on this host mis-evaluates the compact
`trim(is_string($cfg->username ?? '') ? $cfg->username : '')` form — measured in isolation,
`is_string($x->cfg->username ?? '')` returns true for a MISSING property and the ternary
true-branch fires anyway, re-introducing both warnings. Do not refold the guard into a one-liner.

## Verification performed (2026-08-25)

Fixtures: project 9001 'Issue496Proj' with incomplete jirarest (id 501, type 7) and stashrest
(id 601, type 1) linked; harness drives the real production path
(`tlIssueTracker::getInterfaceObject(9001)` / `tlCodeTracker::getInterfaceObject(9001)`).

| Scenario | Pre-fix | HEAD before this fix | After fix |
|---|---|---|---|
| A: credentials elements missing | 2 ERROR + 4 E_WARNING | 4 E_WARNING | **0 events**, graceful skip |
| B: empty elements `<username></username>` | fatal TypeError | fatal TypeError | **0 events**, graceful skip |
| C: valid creds, unroutable host | n/a | n/a | **0 events**, connected=false |

Browser pass: login admin/admin, active project I496P, mainPage load (mainPage.php:401 path),
Event Viewer shows only the AUDIT login row:


Regression suite: `tmp/TLU_Test_Cases.md` → **Suite 496 — 9/9 PASS**.
