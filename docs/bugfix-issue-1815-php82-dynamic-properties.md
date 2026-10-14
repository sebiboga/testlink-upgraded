# Bugfix Issue #1815 — PHP 8.2 `E_DEPRECATED` "Creation of dynamic property" in the issue-tracker interfaces and `tlUser`

Class of defect: PHP 8.2 deprecated (PHP 9 removes) the implicit creation of object
properties. A property assigned on `$this` (`$this->x = ...`) but never *declared* in
the class — nor in any ancestor — raises
`E_DEPRECATED: Creation of dynamic property <Class>::$x is deprecated`.

Five sites did exactly that: four issue-tracker REST interfaces and the `tlUser`
constructor (the login-page path).

## Symptom

Under PHP 8.2+ with `error_reporting(E_ALL)` (`config.inc.php:331`):

```
PHP Deprecated: Creation of dynamic property redminerestInterface::$canSetReporter is deprecated
  in lib/issuetrackerintegration/redminerestInterface.class.php on line 39
PHP Deprecated: Creation of dynamic property redminerestInterface::$issueAttr is deprecated
  in lib/issuetrackerintegration/redminerestInterface.class.php on line 114
PHP Deprecated: Creation of dynamic property tlUser::$loginRegExp is deprecated
  in lib/functions/tlUser.class.php on line 158
```

Measured, the identical defect also exists in `githubrestInterface` (`$issueAttr`),
`gitlabrestInterface` (`$issueAttr`) and `kaitenrestInterface` (`$canSetReporter`) — the
report named only the redmine class because its repro fixture used redmine.

The noise was **hidden on the web tier**, not absent: TestLink registers its own handler
`watchPHPErrors` (`lib/functions/logger.class.php:1483`); its `$errors` map
(`:1409-1416`) lists only `E_USER_NOTICE/E_USER_WARNING/E_USER_ERROR/E_ERROR/E_WARNING/E_NOTICE`,
and the guard at `:1427` (`isset($errors[$errno])`) is therefore **false for
`E_DEPRECATED`** — the diagnostic is silently dropped and never reaches the `events`
table (0 rows). The engine still raises it (fatal on PHP 9).

## Root cause

| Class | Property | Assignment site | Declaration |
|---|---|---|---|
| `redminerestInterface` | `$canSetReporter` | `:39` | none |
| `redminerestInterface` | `$issueAttr` | `:112-114` | none |
| `githubrestInterface` | `$issueAttr` | `:102-104` | none |
| `gitlabrestInterface` | `$issueAttr` | `:103-105` | none |
| `kaitenrestInterface` | `$canSetReporter` | `:41` | none |
| `tlUser` | `$loginRegExp` | `:158` (ctor) | none (siblings `$usernameFormat`, `$loginMethod`, `$maxLoginLength` **are** declared at `:112-114`) |

The abstract base `issueTrackerInterface` declares the shared members (`$cfg`, `$name`,
`$connected`, `$methodOpt`, `$resolvedStatus`, …) but **not** `$issueAttr` nor
`$canSetReporter`. `$loginRegExp` is read at `tlUser.class.php:663`
(`preg_match($this->loginRegExp, ...)`) and written only in the constructor, so declaring
it changes nothing.

This is not a regression from a recent commit: the assignments were unguarded since they
were written and only became diagnostics when 2.0.1 moved to PHP 8.2+. Same class as the
earlier PHP 8.2 effort (#204).

## Fix (minimal)

Declare the properties explicitly; no assignment is removed and no call site changes.

```php
// lib/issuetrackerintegration/redminerestInterface.class.php
private $issueAttr = null;
private $canSetReporter = false;

// githubrestInterface.class.php / gitlabrestInterface.class.php
private $issueAttr = null;

// kaitenrestInterface.class.php
private $canSetReporter = false;

// lib/functions/tlUser.class.php
protected $loginRegExp;
```

**Alternatives rejected:**
- *Declare `$issueAttr`/`$canSetReporter` once in the base `issueTrackerInterface`* — a
  `public`/`var` base property fatals against the `private $issueAttr` already declared in
  `jirarestInterface:20` and `jirasoapInterface:30` (*"Access level to jirarestInterface::$issueAttr
  must be public (as in class issueTrackerInterface)"*, verified with a PHP snippet). Making the
  JIRA properties public to accommodate that would widen the change and loosen their encapsulation.
- *Remove the write-only `$canSetReporter` assignment* — it is dead (write-only today) but
  removing it is a behaviour/API decision outside a "declare the property" fix; declaring it
  keeps the property present for any external/legacy reader while silencing the deprecation.

## Verification (fresh DB)

CLI harness `php tmp/repro_1815.php` (instantiates each interface like the BFF and calls
`getSecurityNotes()`), plus live HTTP against the built-in server.

| # | Step | Expected | Observed | Verdict |
|---|------|----------|----------|---------|
| 1 | `php -l` on the 5 touched files | no syntax errors | No syntax errors detected (×5) | PASS |
| 2 | pre-fix `php tmp/repro_1815.php` | 5 deprecations | redmine=2, github=1, gitlab=1, kaiten=1, tlUser=1; exit 1 | PASS (bug confirmed) |
| 3 | post-fix `php tmp/repro_1815.php` | 0 deprecations | all 0; `ALL PASS`; exit 0 | PASS |
| 4 | `getSecurityNotes($db)` | 0 deprecations, array result | `deprecations=0`, `notes_type=array` | PASS |
| 5 | live `GET /login.php` | HTTP 200 | `http=200` | PASS |
| 6 | `GET /api/issuetracker` | HTTP 200 | `http=200` | PASS |
| 7 | `POST /api/issuetracker/test-connection` (redmine) | HTTP 200 JSON | `{"status":"ok","connected":false,...}` 200 | PASS |
| 8 | `tlUser::checkLogin("admin")` / `("a b!@#")` | OK(1) / E_NOTALLOWED(-4) | `1` / `-4` | PASS |
| 9 | Event Viewer | only AUDIT rows | 2 rows, `log_level=16` (audit_login_succeeded) | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1815" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
Suite: `Regression — Issue #1815` in `tmp/TLU_Test_Cases.md`.

Screenshots: none new — the defect is server-side (no screen state changes); the evidence is
the deprecation count (5 → 0), which is also why the Event Viewer was never polluted.

## Related / follow-up

The same *class* of defect exists with **different property names** in five other
issue-tracker interfaces — `$defaultResolvedStatus` in `bugzilladbInterface:29`,
`bugzillaxmlrpcInterface:49`, `tracxmlrpcInterface:62`, `tuleaprestInterface:33`, and
`$statusDomain` in `jirarestInterface:185` (declared only in the *helper* `jiraCommons`, which
is not an ancestor). They were discovered by the code review of this fix and measured with the
same harness (`tuleaprest=1`, `bugzillaxmlrpc=1`, `tracxmlrpc=1` deprecations). Per the
one-bug-per-run rule they are **not** fixed here; they are tracked in **#1914**. Vendored
`third_party/fayp-jira-rest` files also emit dynamic-property deprecations and are out of scope.

## Files changed

- `lib/issuetrackerintegration/redminerestInterface.class.php` — declare `$issueAttr`, `$canSetReporter`.
- `lib/issuetrackerintegration/githubrestInterface.class.php` — declare `$issueAttr`.
- `lib/issuetrackerintegration/gitlabrestInterface.class.php` — declare `$issueAttr`.
- `lib/issuetrackerintegration/kaitenrestInterface.class.php` — declare `$canSetReporter`.
- `lib/functions/tlUser.class.php` — declare `$loginRegExp`.
- `CHANGELOG` — 2.0.1 key-bugfix line.
- `tmp/TLU_Test_Cases.md` — regression suite (append-only).
- `docs/` mirror + this GitHub Wiki page.
