# Issue 649 — codeTrackerInterface truthiness check falsely treats empty-root XML config as parse failure

**Issue:** [#649](https://github.com/sebiboga/testlink-upgraded/issues/649)
**Branch:** `fix/issue-649` · **Commits:** `cb7238aa3` (fix), `0aead081a` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-23)

## Symptom

A code tracker saved with a valid but childless-root config (`<codetracker></codetracker>`,
savable since the #432 identity-check fix) produced a spurious ERROR on every interface
instantiation and left the integration disconnected:

```
setCfg() returned: false        ← valid XML reported as failure
connected flag:    false

events table:
id=1 log_level=1(ERROR) source=GUI :: codeTrackerInterface::setCfg - Failure loading XML STRING
id=2 log_level=1(ERROR) source=GUI :: codeTrackerInterface::setCfg - Failure loading XML STRING
```

Note no libxml error text was appended — because there WAS no parse error.

## Root cause chain

1. `lib/codetrackerintegration/codeTrackerInterface.class.php:105` —
   `$this->cfg = simplexml_load_string($xmlCfg);`
2. `:106` — `if (!$this->cfg)` used TRUTHINESS. PHP casts a SimpleXMLElement wrapping a
   childless root element to boolean FALSE (measured: `(bool)$x === false`,
   `$x === false === false`). A SUCCESSFUL parse entered the failure branch.
3. `:107-113` — `$msg = "...Failure loading XML STRING"` set with zero libxml errors.
4. `:121-124` — `!($retval = is_null($msg))` → returns `false` → `tLog(...,'ERROR')`.
5. Constructor `:60-69` — else branch sets `$this->connected = false`; every code-integration
   lookup via `tlCodeTracker::getInterfaceObject()` runs disconnected.

**Why now:** #432 fixed only the save-time validator `tlCodeTracker::checkXMLCfg`
(`lib/functions/tlCodeTracker.class.php:694`, `$cfg === false`). Empty-root configs became
savable, making this runtime-side copy of the same latent 1.9.20 pattern reachable for the
first time. #432 exposed it; it did not introduce it.

## Blast radius

- Direct: every concrete subclass constructor — currently `stashrestInterface` (the only
  subclass of `codeTrackerInterface`). All entry points through
  `tlCodeTracker::getInterfaceObject()`: `execSetResults.php:753`, `scriptAdd.php:303`,
  `scriptDelete.php:121`, `archiveData.php:199`, `print.inc.php:2369`, plus BFF paths.
- Same-pattern siblings found during investigation, filed separately as #650:
  `issueTrackerInterface.class.php:117`, `reqMgrSystemInterface.class.php:81`.

## Approach — mirror the proven identity check

Chosen fix (one condition + comment):

```php
// was:  if (!$this->cfg)
if ($this->cfg === false)
```

Identity comparison is the ONLY change that distinguishes "parse failed"
(`simplexml_load_string` returns literal `false`) from "parsed object that happens to be
falsey" (childless root). Rejected alternatives: `is_null($this->cfg)` (wrong — never null),
wrapping in try/catch only (libxml failures return false rather than throwing), or
suppressing the log at handler level (hides real failures).

Post-fix behavior for `<codetracker></codetracker>`: parse succeeds → `connect()` silently
skips (no credentials configured — by design) → no events written.

## Files changed

| File | Change |
|---|---|
| `lib/codetrackerintegration/codeTrackerInterface.class.php` | :106 truthiness → `$this->cfg === false` (+ explanatory comment) |
| `tmp/TLU_Test_Cases.md` | Suite 649 regression entry (5 cases incl. pre-fix repro) |

## Verification (regression matrix, CLI against live DB)

| Case | cfg | setCfg() | connected | new events |
|---|---|---|---|---|
| R1 | `<codetracker></codetracker>` | **true** (was false) | false (silent credential skip — by design) | NO bogus ERROR; 3 WARNINGs from unguarded stashrest property reads → pre-existing class filed as #651 |
| R2 | full config with children | true | false* | none — unchanged (*unreachable host fails cleanly) |
| R3 | `<codetracker<` malformed | **false** (correctly) | false | ERROR WITH libxml detail text ✓ |

Suite 649: **5/5 PASS** (`tmp/TLU_Test_Cases.md`).

## Related issues

- **#650** — identical truthiness pattern in issue-tracker and reqmgr sibling interfaces (open).
- **#651** — unguarded cfg property reads (`stashrestInterface.class.php:47/99/100`) warn for
  incomplete configs; pre-existing, surfaced by this fix's verification matrix (open).
