# Issue 1520 — addAliens: `method_exists(null,'addLink')` TypeError on tracker-less projects breaks alien insert (PHP 8)

**Issue:** [#1520](https://github.com/sebiboga/testlink-upgraded/issues/1520)
**Branch:** `fix/issue-1520`
**Status:** VERIFIED-FIXED (2026-09-16)

## Symptom

`testcase::addAliens()` fatals on PHP 8 whenever the owning test project has NO
issue tracker configured. The alien INSERT into `testcase_aliens` succeeds (lines
10365-10367) and is then followed by a fatal abort at the optional tracker
update:

```
PHP Fatal error:  Uncaught TypeError: method_exists(): Argument #1
($object_or_class) must be of type object|string, null given in
.../lib/functions/testcase.class.php:10372

Stack trace:
#0 .../lib/functions/testcase.class.php(10372): method_exists()
#1 .../lib/functions/testcase.class.php(10584): testcase->addAliens()
   (call chain: copyAliensTo -> addAliens)
```

## Repro steps

1. Fresh-import DB. Fixture: test project 1 (`PRJ`, `issue_tracker_enabled=0`,
   no `testproject_issuetracker` row), suite node 2, testcase node 3, tcversion
   4 (v1) with `testcase_aliens (1,3,4,1001)`; a second tcversion 5 (v2) with no
   aliens yet.
2. Bootstrap the framework exactly like `api/testcases/index.php`
   (`config.inc.php` + `lib/functions/common.php`), create the `testcase`
   manager, `setTestProject(1)`.
3. Call
   `addAliens((object){tproject_id:1, tcase_id:3, tcversion_id:5}, [1001], 1)`.
4. Before fix: exit 255, `TypeError: method_exists(): Argument #1
   ($object_or_class) must be of type object|string, null given` at line 10372.
   After fix: returns OK, and `testcase_aliens` gets row `(1,3,5,1001,1)`.

**Expected:** the tracker-add loop is declared optional
(`if ( method_exists($repo,'addLink') )`). With no tracker configured the loop
must be skipped silently while the `testcase_aliens` rows are still persisted.

## Root cause

`lib/functions/testcase.class.php:10370-10372`:

```php
$system = new tlIssueTracker($this->db);
$repo = $system->getInterfaceObject($safeID['tpr']);
if ( method_exists($repo,'addLink') ) { ...
```

Chain:

1. `tlIssueTracker::getInterfaceObject($tprojectID)`
   (`lib/functions/tlIssueTracker.class.php:670`): for a project with no linked
   issuetracker, `getLinkedTo()` returns `null`, the block at line 705 falls
   through and the method returns `$_SESSION['its'][$name]` which was set to
   `null` at line 708. So the contract already is "null = no tracker".
2. line 10372 hands that `null` to `method_exists()` as argument #1. PHP 8.x
   raises a `TypeError` on a non-object/non-string first argument; PHP 7.x
   silently returned `false`. Latent PHP7-era code.
3. The guard was written for the optional-tracker semantic but only works when
   `getInterfaceObject()` returns an object.

**Why it breaks now:** TestLink 2.0.1 runs PHP 8; any code path that actually
reaches `addAliens()` on a tracker-less project crashes. Reachable via
`copyAliensTo()` (`create_new_version`, line 10584) and
`testcaseCommands::addAlien()` (line 1610) whenever `$tcaseMgr->tproject_id`
holds a valid tracker-less project id.

**Blast radius:** `testcase::addAliens()` line 10372 (addLink) and its sibling
`testcase::removeAlien()` line 10481 (`method_exists($repo,'removeLink')`) — the
identical latent crash on the remove path. Both fixed with one guard each. No
other call sites hit `method_exists($repo,...)` with a possibly-null repo in
this file (line 1316 is `addAndLinkIsEnabled`, reached only when the project
HAS a linked tracker via `getLinkedTo`).

## Fix (minimal)

`lib/functions/testcase.class.php` — null-guard both optional-tracker blocks:

```php
// line 10372 (addAliens)
if ( !is_null($repo) && method_exists($repo,'addLink') ) {
// line 10481 (removeAlien)
if ( !is_null($repo) && method_exists($repo,'removeLink') ) {
```

`method_exists()` is only invoked on a real repo object; a tracker-less project
(null repo) skips the tracker update but the `testcase_aliens` INSERT/DELETE
still completes. Projects WITH a tracker behave exactly as before. No i18n /
locale / rights changes needed (PHP-level error, no user-facing strings).

## Verification (regression matrix, all PASS on localhost:8082)

- **Trigger case:** `addAliens` on tracker-less project (tpr=1, target tcv=5)
  → returns OK, exit 0, `testcase_aliens` row `(1,3,5,1001,1)` persisted.
- **Idempotency:** re-running the same call keeps exactly 1 row for v5
  (existing-alien `nuCheck` skip path).
- **Existing-alien early return:** target tcv=4 (alien already present) →
  `count($dummy) <= 0` guard returns cleanly.
- **Guard placement:** `grep` shows `!is_null($repo)` at both 10372 and 10481.
- **Hygiene:** `php -l` clean; `events` table gains no new `log_level IN (1,2)`
  Error/Warning rows (only the pre-existing AUDIT=16 LOGIN rows).

## Discoveries while testing

- On the current default branch the API `create_version` endpoint still dies at
  `testcase::addAliens tpr cannot be 0` (`testcase.class.php:10327`) because the
  API never calls `setTestProject()` on the manager (`api/testcases/index.php`
  discards the `$checkWrite()` return at line 2594). That is a **separate bug**
  tracked by issue #1517; the guard fixed here is the prerequisite so that once
  #1517 lands, the tracker-less alien flow verifies end-to-end. Not touched in
  this run per one-issue scope.
- The INSERT (line 10367) runs before the crash point, so alien rows could be
  persisted despite the fatal — consistent with the issue body note.

## Evidence

Commit range on `fix/issue-1520`:
- `a587211c2` — fix (testcase.class.php guard + CHANGELOG)
- `a1904a249` — regression suite (tmp/TLU_Test_Cases.md)
- docs + wiki follow.
Wiki page: `tmp/wiki-repo/Bugfix-Issue-1520-Aliens-MethodExists-Null-Guard.md`.