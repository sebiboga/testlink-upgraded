# Issue 650 — issueTrackerInterface + reqMgrSystemInterface truthiness check falsely treats empty-root XML as parse failure

**Issue:** [#650](https://github.com/sebiboga/testlink-upgraded/issues/650)
**Branch:** `fix/issue-650-simplexml-truthiness` · **Commit:** `3b10069fd`
**Status:** FIXED & VERIFIED (2026-08-26)

## Symptom

The identical truthiness check fixed for code trackers in #649 exists in two sibling
classes. A config whose root element has NO children parses successfully but is treated
as a parse failure: spurious `tLog ERROR 'Failure loading XML STRING'` and interface
left disconnected.

- `lib/issuetrackerintegration/issueTrackerInterface.class.php:116-117`:
  `$this->cfg = simplexml_load_string($this->xmlCfg); if (!$this->cfg)`
- `lib/reqmgrsystemintegration/reqMgrSystemInterface.class.php:80-81`: same pattern

Additionally, `issueTrackerInterface.class.php:134` had a loose `== false` comparison
that also triggered the false failure (discovered during regression testing).

Practical impact is low — issue-tracker/reqmgr configs normally require child elements —
but it is the exact latent defect class as #432/#649.

## Root cause chain

1. `lib/issuetrackerintegration/issueTrackerInterface.class.php:116` —
   `$this->cfg = simplexml_load_string($this->xmlCfg);`
2. `:117` — `if (!$this->cfg)` uses TRUTHINESS. PHP casts a SimpleXMLElement wrapping a
   childless root element to boolean FALSE (measured: `(bool)$x === false`,
   `$x === false === false`). A SUCCESSFUL parse entered the failure branch.
3. `:118-122` — `$msg = "...Failure loading XML STRING"` set with zero libxml errors.
4. `:134` — `if ($this->cfg == false)` loose comparison ALSO triggers for empty-root
   SimpleXMLElements (PHP loose comparison casts object to boolean → `false == false = true`).
5. Same pattern in `lib/reqmgrsystemintegration/reqMgrSystemInterface.class.php:80-81` —
   identical `$this->cfg = simplexml_load_string(...)` → `if (!$this->cfg)` chain.
6. Correct reference implementation: `lib/codetrackerintegration/codeTrackerInterface.class.php:106-109`
   (fixed by #649) — `if ($this->cfg === false)` identity check with explanatory comment.

**Why now:** #432 fixed only `tlCodeTracker::checkXMLCfg` (save-time validation). #649 fixed
`codeTrackerInterface` (runtime loading for code trackers). But `issueTrackerInterface` and
`reqMgrSystemInterface` were siblings missed in both blasts. Now that empty-root configs are
savable (post-#432), this runtime-side truthiness bug is reachable.

## Blast radius

- `issueTrackerInterface.class.php` — parent of all issue-tracker subclasses (JIRA, Mantis,
  Bugzilla, etc.). Any subclass with a childless root config triggers the false failure.
- `reqMgrSystemInterface.class.php` — parent of all reqmgr-system subclasses. Same blast.
- Practical impact is LOW: real configs always have child elements, so the empty-root case
  is latent. But it is the exact defect class as #432/#649 and must be fixed for consistency.

## Approach — mirror the proven identity check

Chosen fix (three conditions + comments):

```php
// issueTrackerInterface.class.php:120 — was: if (!$this->cfg)
if ($this->cfg === false) {

// issueTrackerInterface.class.php:134 — was: if ($this->cfg == false)
if ($this->cfg === false) {

// reqMgrSystemInterface.class.php:81 — was: if (!$this->cfg)
if ($this->cfg === false)
```

Identity comparison is the ONLY change that distinguishes "parse failed" from "parsed
object that happens to be falsey". The `reqMgrSystemInterface` return path
(`return is_null($msg)`) was already clean — only one condition needed fixing there.
The `issueTrackerInterface` had TWO checks (truthiness at :117 + loose `==` at :134),
both requiring the identity fix.

## Files changed

| File | Change |
|---|---|
| `lib/issuetrackerintegration/issueTrackerInterface.class.php` | :117 truthiness → `$this->cfg === false`, :134 loose `==` → `=== false` (+ explanatory comment) |
| `lib/reqmgrsystemintegration/reqMgrSystemInterface.class.php` | :81 truthiness → `$this->cfg === false` (+ explanatory comment) |

## Verification (regression matrix, CLI against live DB)

| Case | cfg | file | setCfg() | Expected |
|---|---|---|---|---|
| R1 | `<issuetracker></issuetracker>` (valid, empty root) | issueTrackerInterface | **true** (was false) | true ✓ |
| R2 | `<reqmgrsystem></reqmgrsystem>` (valid, empty root) | reqMgrSystemInterface | **true** (was false) | true ✓ |
| R3 | valid config WITH children | both | true | true ✓ |
| R4 | malformed garbage | both | **false** (correctly) | false ✓ |

No new ERROR events in `events` table. PHP syntax clean on both files.

Suite 650: **6/6 PASS** (`tmp/TLU_Test_Cases.md`).

## Related issues

- **#432** — original fix for `tlCodeTracker::checkXMLCfg` identity check (closed).
- **#649** — same truthiness pattern in `codeTrackerInterface.class.php` (closed).
