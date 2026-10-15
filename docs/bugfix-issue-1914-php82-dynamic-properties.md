# Bugfix Issue #1914 — PHP 8.2 `E_DEPRECATED` "Creation of dynamic property" in the bugzilla/trac/tuleap/jira interfaces

Follow-up of **#1815** (same defect class, **different property names**): PHP 8.2
deprecated (PHP 9 removes) the implicit creation of object properties. A property
assigned on `$this` but never *declared* in the class — nor in any ancestor — raises
`E_DEPRECATED: Creation of dynamic property <Class>::$x is deprecated`.

Five more issue-tracker interface sites did exactly that, and the mandated code review of this
fix found a **sixth** (`jiradbInterface`, same `$statusDomain` property).

## Symptom

Under PHP 8.2+/8.3 with `error_reporting(E_ALL)` (`config.inc.php:331`):

```
PHP Deprecated: Creation of dynamic property tuleaprestInterface::$defaultResolvedStatus is deprecated
  in lib/issuetrackerintegration/tuleaprestInterface.class.php on line 33
PHP Deprecated: Creation of dynamic property bugzillaxmlrpcInterface::$defaultResolvedStatus is deprecated
  in lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php on line 49
PHP Deprecated: Creation of dynamic property tracxmlrpcInterface::$defaultResolvedStatus is deprecated
  in lib/issuetrackerintegration/tracxmlrpcInterface.class.php on line 62
PHP Deprecated: Creation of dynamic property bugzilladbInterface::$defaultResolvedStatus is deprecated
  in lib/issuetrackerintegration/bugzilladbInterface.class.php on line 29
PHP Deprecated: Creation of dynamic property jirarestInterface::$statusDomain is deprecated
  in lib/issuetrackerintegration/jirarestInterface.class.php on line 185
```

The report's own `php -r` fixture only saw the three *unconditional* constructor sites
(`tuleaprest`, `bugzillaxmlrpc`, `tracxmlrpc`) because its config never connected; measured
here, `bugzilladb:29` (inside `if($this->connected)`) and `jirarest:185` (inside the
live-JIRA block) raise identically once their config reaches a BTS.

As with #1815 the noise is **hidden on the web tier**, not absent: `watchPHPErrors`
(`lib/functions/logger.class.php:1483`) drops `E_DEPRECATED` (its `$errors` map
`:1409-1416` has no entry), so the `events` table stays empty — but the engine still
raises the diagnostic (fatal on PHP 9).

## Root cause

| Class | Property | Assignment site | Declaration |
|---|---|---|---|
| `bugzilladbInterface` | `$defaultResolvedStatus` | `:29` (inside `if($this->connected)`, `:25`) | none |
| `bugzillaxmlrpcInterface` | `$defaultResolvedStatus` | `:49` (constructor) | none |
| `tracxmlrpcInterface` | `$defaultResolvedStatus` | `:62` (constructor) | none |
| `tuleaprestInterface` | `$defaultResolvedStatus` | `:33` (constructor) | none |
| `jirarestInterface` | `$statusDomain` | `:185` (live-JIRA block) | none |
| `jiradbInterface` | `$statusDomain` | `:189` (`getStatuses()`) / read at `:71`, `:204` | none |

The abstract base `issueTrackerInterface` declares the shared members (`$cfg`, `$name`,
`$connected`, `$dbConnection`, `$methodOpt`, …) but **not** `$defaultResolvedStatus` nor
`$statusDomain`. `jirarestInterface extends issueTrackerInterface` (`:15`) — it does **not**
extend `jiraCommons`; `statusDomain` is declared only on the *helper*
(`jiraCommons.class.php:15`) that fills `$this->support`, so `$this->statusDomain` on the
interface itself is undeclared. The sibling interfaces
`redminerest`/`githubrest`/`gitlabrest`/`kaitenrest`/`jiradb`/`jirasoap`/`mantissoap`
already declare `var $defaultResolvedStatus;` — only the five above forgot.

Not a recent regression: the assignments were unguarded since they were written and only
became diagnostics when 2.0.1 moved to PHP 8.2+.

## Fix (minimal)

Declare the properties; no assignment is removed and no call site changes.

```php
// bugzilladbInterface.class.php / bugzillaxmlrpcInterface.class.php /
// tracxmlrpcInterface.class.php / tuleaprestInterface.class.php
var $defaultResolvedStatus;

// jirarestInterface.class.php (jirarestInterface already declares var $defaultResolvedStatus;)
private $statusDomain = array();

// jiradbInterface.class.php (declares getStatusDomain(); sibling of jirasoapInterface)
protected $statusDomain = array();
```

**Why `var` and not `private` for `$defaultResolvedStatus`:** the base
`issueTrackerInterface::setResolvedStatusCfg()` (`:633`) reads `$this->defaultResolvedStatus`
at `:639`, so a `private` child property would be unreachable from the parent method and
the assignment would fatal with `Cannot access private property ...::$defaultResolvedStatus`
(measured live — see the correction in the checkpoint comment). `var` (public) mirrors the
sibling classes and keeps the parent working. `jirarestInterface::$statusDomain` is never
read by the parent, so `private` is safe there; `jiradbInterface::$statusDomain` is declared
`protected`, mirroring `jirasoapInterface.class.php:24` and `gforgesoapInterface.class.php:23`.

**Alternatives rejected:**
- *Declare both properties once in the base `issueTrackerInterface`* — rejected for the
  same reason as #1815: a base property conflicts with the per-class declarations that
  already exist (e.g. `jirarestInterface:23` `var $defaultResolvedStatus;`), and it widens
  the change far beyond "declare the missing ones".
- *Remove the assignments* — they are the interfaces' resolved-status configuration, read
  by `setResolvedStatusCfg()`; removing them would change behaviour.

## Verification (fresh DB)

CLI harness `php tmp/repro_1914.php` (instantiates each interface exactly like the BFF
factory `tlIssueTracker::getInterfaceObject()` at `lib/functions/tlIssueTracker.class.php:774`),
plus live HTTP through the BFF `POST /api/issuetracker/index.php/test-connection` route
(which does `new $impl($type,$cfg,$name)`).

| # | Step | Expected | Observed | Verdict |
|---|------|----------|----------|---------|
| 1 | `php -l` on the 6 touched files | no syntax errors | No syntax errors detected (×6) | PASS |
| 2 | pre-fix `php tmp/repro_1914.php` | 5 deprecations | bugzilladb=1, bugzillaxmlrpc=1, tracxmlrpc=1, tuleaprest=1, jirarest=1; exit 1 (jiradb's `:189` fires only when its JIRA DB query returns rows, so the harness reports it via the reflection check) | PASS (bug confirmed) |
| 3 | post-fix `php tmp/repro_1914.php` | 0 deprecations | all 0; reflection `declared(own)=YES ×6`; `ALL PASS`; exit 0 | PASS |
| 4 | live type=2 bugzilladb `test-connection` (local MariaDB) | 200 `connected:true`, no fatal | `{"status":"ok","connected":true,...}` 200 | PASS |
| 5 | live type=27 tuleap `test-connection` (dead host) | 200 JSON, no dynamic-property fatal | `{"status":"ok","connected":false,...}` 200 | PASS |
| 6 | authenticated `GET /api/issuetracker/index.php/` | JSON, `canManage:true` | `{"status":"ok","canManage":true,"items":[],"total":0}` 200 | PASS |
| 7 | Event Viewer | no dynamic-property rows | only the interfaces' own outbound connection-failure log (`tuleaprestInterface::connect`, log_level=1) for the dead probe host | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1914" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
Suite: `Regression — Issue #1914` in `tmp/TLU_Test_Cases.md`.

Screenshots: none new — the defect is server-side (no screen state changes); the evidence is
the deprecation count (5 → 0), which is also why the Event Viewer was never polluted.

## Files changed

- `lib/issuetrackerintegration/bugzilladbInterface.class.php` — declare `$defaultResolvedStatus`.
- `lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php` — declare `$defaultResolvedStatus`.
- `lib/issuetrackerintegration/tracxmlrpcInterface.class.php` — declare `$defaultResolvedStatus`.
- `lib/issuetrackerintegration/tuleaprestInterface.class.php` — declare `$defaultResolvedStatus`.
- `lib/issuetrackerintegration/jirarestInterface.class.php` — declare `$statusDomain`.
- `lib/issuetrackerintegration/jiradbInterface.class.php` — declare `$statusDomain`.
- `CHANGELOG` — 2.0.1 BUGFIX line.
- `tmp/TLU_Test_Cases.md` — regression suite (append-only).
- `docs/` mirror + this GitHub Wiki page.

## Related

- #1815 — the original dynamic-property fix (redmine/github/gitlab/kaiten + `tlUser`); this
  issue was its documented follow-up.
- `third_party/fayp-jira-rest/Jira.php:44` and `RestRequest.php:30-31` also emit
  dynamic-property deprecations; vendored library files, out of scope.
