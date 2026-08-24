# sysinfo.php memory_limit ini-shorthand mis-parsed by bare intval() (Issue #676)

Issue: https://github.com/sebiboga/testlink-upgraded/issues/676
Fix commits: `2aa436403` (+ regression `b776bb938`) on branch `fix/issue-676`
Sibling fix (same defect class, installer pre-flight): Issue #675 / `12d10e954`.

## Symptom

`install/util/sysinfo.php` (`checkMemoryLimits()`, surfaced in the JSON output
and the *Memory Limit* table row) classified any host running PHP with a
`G`- or `K`-suffixed `memory_limit` as broken:

```json
{"memory_limit":"1G", ..., "memory_status":"ERROR",
 "message":"Memory: 1G (Recommended: 512M+) ..."}
```

…although 1 GB is far above the 512 MB "recommended" threshold.

## Root cause

`install/util/sysinfo.php:83` computed

```php
$limitValue = intval($limit);   // $limit = ini_get('memory_limit')
```

and compared `$limitValue` against MB thresholds (`$minMemory = 256`,
`$recommended = 512`). `intval()` keeps only leading digits, so:

* `G`/`K` suffixes collapse to leading digits (`1G` → 1 → ERROR);
* the `-1` unlimited sentinel evaluated as -1 → ERROR;
* bare-byte values (PHP treats suffix-less ints as bytes) were compared
  **un-divided**, so `268435456` (= 256 MB) scored as "OK ≥ 512" — a false
  positive. Only `M` values were accidentally correct.

Pre-existing upstream logic (file introduced by `477195acf`). Same defect class
as #675 in `configCheck.php::check_php_settings()`, fixed by `12d10e954`.

Blast radius: single call site at `sysinfo.php:246`; standalone install
utility, no runtime screens affected.

## The fix

Suffix-aware parsing inside `checkMemoryLimits()` only, mirroring the pattern
established by `12d10e954`:

* value read once via `strtolower(trim(...))`;
* `'-1'` → treated as unlimited (always acceptable);
* unit switch: `g` → ×1024 MB · `m` → ×1 MB · `k` → ÷1024 MB
  · no suffix → bytes ÷ 1048576;
* thresholds (256 WARN / 512 OK), status mapping and message string unchanged.

## Verification

Harness extracted the real production function from `sysinfo.php` source and
ran it under controlled `-d memory_limit` values:

| ini value | Pre-fix | Post-fix |
|---|---|---|
| `1G` | ERROR | OK |
| `1024M` / `512M` | OK | OK (unchanged) |
| `511M` / `256M` | WARN | WARN (unchanged) |
| `255M` / `128M` | ERROR | ERROR (unchanged) |
| `-1` | ERROR | OK (unlimited) |
| `268435456` (bytes = 256 MB) | OK (false positive) | WARN |
| `536870912` (bytes = 512 MB) | OK | OK |
| `2097152K` (= 2048 MB) | ERROR | OK |

Code-review subagent verdict: PASS (exact mirror of the configCheck.php
reference; no drive-by changes). Main app login regression HTTP 200; `events`
table: zero new Error/Warning entries.

Regression suite: *Suite 676* in `tmp/TLU_Test_Cases.md` (11/11 PASS).

## Known blocker (separate issue)

Live HTTP verification of this screen is currently impossible for an unrelated
reason: every request to `install/util/sysinfo.php` dies with
`Fatal error: Cannot redeclare checkPhpExtensions()` because the file's own
top-level `checkPHPVersion()` / `checkPHPExtensions()` collide
case-insensitively with the copies pulled in via
`config.inc.php → configCheck.php`. Tracked separately in
https://github.com/sebiboga/testlink-upgraded/issues/678 — screenshots of the
rendered page will follow once that fix lands.
