# Bugfix Issue #1915 — `jiraCommons::initDefaultResolvedStatus()` warns for JIRA status sets without literal `Resolved`/`Closed`

Follow-up of **#1914** (same helper, different defect): filed while testing that fix
(see the "Related" note in `docs/bugfix-issue-1914-php82-dynamic-properties.md`).

## Symptom

`jiraCommons::initDefaultResolvedStatus()` (`lib/issuetrackerintegration/jiraCommons.class.php`)
hardcodes the item set `array('Resolved','Closed')` and indexes the incoming `$statusDomain`
map by those exact names. JIRA status names are fully customizable (and localized), so an
instance whose status set is e.g. `Done` / `In Review` is entirely legal — and then every
call emits two diagnostics and returns a broken map:

```
PHP Warning: Undefined array key "Resolved" in lib/issuetrackerintegration/jiraCommons.class.php on line 93
PHP Warning: Undefined array key "Closed"   in lib/issuetrackerintegration/jiraCommons.class.php on line 93
```

The returned entries get `code => null`, so nothing can ever be classified as resolved.
Unlike the `E_DEPRECATED` noise of #1814/#1915-sibling reports, **`E_WARNING` is persisted**:
the web-tier `watchPHPErrors()` handler writes each one as an Event Viewer row, so a
JIRA-linked TestLink install with custom status names gets a Warn row on every
`POST /api/issuetracker/index.php/test-connection` (and any legacy issue-tracker screen that
instantiates the interface).

Reachable through `jiradbInterface.class.php:72`, `jirarestInterface.class.php:190`,
`jirasoapInterface.class.php:205`.

## Root cause

`jiraCommons.class.php:87-96`:

```php
$itemSet = array('Resolved','Closed'); // Unfortunately case is important
foreach($itemSet as $st) {
  $domain[] = array('code' => $statusDomain[$st], 'verbose' => $st); // line 93
}
```

`$statusDomain` is a `statusName => statusId` map built from the *live* JIRA status list:

| Back-end | Writer | Source |
|---|---|---|
| DB | `jiradbInterface::getStatuses()` `:190` | `SELECT issuestatus.pname` |
| REST | `jirarestInterface` `:186` | `APIClient->getStatuses()` |
| SOAP | `jirasoapInterface` `:202` | SOAP `getStatuses()` |

Line 93 indexes with no `isset()`/`array_key_exists()` guard, so a missing name is an
undefined-key read (PHP 8 `E_WARNING`) whose value is `null`.

The result flows into `setResolvedStatusCfg()` (`jirarestInterface:918`, `jirasoapInterface:369`,
base `issueTrackerInterface:634`) → `resolvedStatus->byCode`, and is consumed by
`isResolved = isset($this->resolvedStatus->byCode[$issue->statusCode])`
(`jiradb:157`, `jirarest:238`, `jirasoap:151`). A `null` key becomes `byCode[""]`, which never
matches a real status code.

Same defect class as #1608 (undefined array key → PHP 8 diagnostic); not a recent regression —
the hardcoding has been there since the helper was written.

## Fix (minimal)

Guard the lookup; skip names that are absent.

```php
foreach($itemSet as $st) {
  // Refs #1915: JIRA status names are customizable/localizable, so a domain
  // without these exact keys is legal. Guard the lookup to avoid an
  // "Undefined array key" E_WARNING and a bogus null 'code' entry; missing
  // names are simply skipped and can be supplied via cfg->resolvedstatus.
  if( array_key_exists($st, $statusDomain) ) {
    $domain[] = array('code' => $statusDomain[$st], 'verbose' => $st);
  }
}
```

No caller changes, no assignment removed. Behavior for instances that *do* have
`Resolved`/`Closed` is byte-identical; an explicit `cfg->resolvedstatus` override in
`setResolvedStatusCfg()` still takes precedence.

**Companion hardening (found by the mandated code review).** An empty list must not break the
DB backend: `jiradbInterface` uses the **base** `issueTrackerInterface::setResolvedStatusCfg()`
(`:634`), which — unlike the REST/SOAP overrides — never initialized `byCode`. With the fix
returning `[]`, the `foreach` is skipped and `array_flip($this->resolvedStatus->byCode)` reaches
`array_flip(null)` → fatal `TypeError` (not caught by `catch(Exception)`). With two `null`-code
entries this fatal was masked pre-fix, so the guard alone would have converted a warning into an
HTTP 500 on JIRA-DB integrations. The base method is now hardened identically to
`jirasoapInterface::setResolvedStatusCfg()`:

```php
$this->resolvedStatus->byCode = array();
if( !is_null($statusCfg['status']) ) {
  foreach ($statusCfg['status'] as $cfx) { ... }
}
```

**Alternatives rejected:**
- *Case-insensitive lookup* — still fails for localized section names (`Terminé`) and would
  silently mis-classify. Rejected.
- *Treat every status actually present as resolved* — would classify `Open` / `In Progress`
  as resolved. Rejected.
- *Populate a JIRA built-in default map* — statuses are per-instance; no such map exists. Rejected.
- *Suppress with `@` / `error_reporting`* — hides a real `null`, keeps the broken code. Rejected.

## Verification

CLI probes (error handler installed to count warnings):

```
$ php /tmp/probe_jiracommons.php
warnings=0
result=[]                       # pre-fix: warnings=2, [{"code":null,...},{"code":null,...}]

$ php /tmp/probe_jiracommons_matrix.php
both:    warnings+=0 result=[{"code":"5","verbose":"Resolved"},{"code":"6","verbose":"Closed"}]
one:     warnings+=0 result=[{"code":"5","verbose":"Resolved"}]
neither: warnings+=0 result=[]
total_warnings=0
```

| # | Step | Expected | Observed | Verdict |
|---|------|----------|----------|---------|
| 1 | `php -l lib/issuetrackerintegration/jiraCommons.class.php` | no syntax errors | No syntax errors detected | PASS |
| 2 | probe, domain `{Done,In Review}` | 0 warnings, `[]` | 0 warnings, `[]` | PASS |
| 3 | probe, domain `{Resolved,Closed}` | unchanged 2-entry map | `[{"code":"5",...},{"code":"6",...}]` | PASS |
| 4 | probe, domain `{Resolved}` | 1 entry, 0 warnings | `[{"code":"5",...}]` | PASS |
| 5 | static reader census | helper is the only reader | 3 writers, 1 guarded reader | PASS |
| 6 | base `setResolvedStatusCfg()` with empty list (`/tmp/probe_base_resolvedcfg.php`) | no fatal | `byCode=[] byName=[]` (pre-hardening: fatal `TypeError` on `array_flip(null)`) | PASS |
| 6b | base `setResolvedStatusCfg()` with both statuses | unchanged map | `byCode={"5":"Resolved","6":"Closed"}` | PASS |
| 7 | Event Viewer | no new Warn row | warning removed at source | PASS |
| 8 | merge-base gate | exit 0 | 7 PASS / 0 FAIL | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1915" bash ai/verify_test_suites.sh` → exit 0.
Suite: `Regression — Issue #1915` in `tmp/TLU_Test_Cases.md`.

Screenshots: none new — the defect is server-side (no screen state changes); the evidence is
the warning count (2 → 0) and the map (`[null,null]` → `[]`).

## Files changed

- `lib/issuetrackerintegration/jiraCommons.class.php` — guard the `$statusDomain` lookup.
- `lib/issuetrackerintegration/issueTrackerInterface.class.php` — harden base `setResolvedStatusCfg()` for an empty list.
- `CHANGELOG` — 2.0.1 BUGFIX line.
- `tmp/TLU_Test_Cases.md` — regression suite (append-only).
- `docs/` mirror + this GitHub Wiki page.

## Related

- #1914 — PHP 8.2 dynamic-property declarations in the issue-tracker interfaces; its docs note
  already flagged this #1915 warning as a separate, unfixed root cause.
- #1608 — same "undefined array key → PHP 8 E_WARNING" defect class in `tree::get_subtree()`.
