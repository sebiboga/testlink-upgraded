# Bugfix — Issue #1484: `testprojects.options` unserialize() E_WARNING spam on every request

## Problem

Every request bound to testproject 1 in the freshly imported DB fired
**2× `E_WARNING unserialize(): Error at offset 26 of 93 bytes`** at
`lib/functions/testproject.class.php:3927` (`getOptions`). Each warning is
forwarded by `watchPHPErrors()` (`lib/functions/logger.class.php:1483`) into
the `events` table (`activity="PHP"`, `log_level=2`), filling the Event Viewer
with noise on every screen — blocking regression monitoring (AGENTS rule 12).

Reproduced at HEAD on a throwaway app bootstrap (`config.inc.php` + `common.php`
+ `doDBConnect()`, with `watchPHPErrors` active so a warning lands in `events`),
PHP 8.3.33, freshly-imported MySQL. `testprojects` is empty on the fresh import,
so a corrupt 93-byte fixture row (id=100001) was recreated per
FIX-ISSUE.md §2.

```
E_WARNING\nunserialize(): Error at offset 26 of 93 bytes - in .../lib/functions/testproject.class.php - Line 3927
```

Measured: 3 decode calls (see Regression) → 3 identical event rows at lines
**3927, 243 and 445** — the issue report identified only 3927, but the blast
radius covers every decode site of the `options` column.

## Root Cause

Chain (each hop backed by `file:line`):

1. `testprojects.options` for id=1 (from the DB import) holds the 93-byte
   hand-edited blob
   `a:3:{s:15:"requirementsEnabled";b:1;s:16:"priorityEnabled";b:1;s:17:"automationEnabled";b:1;}`
   (hex `613A333A7B733A31353A22726571756972656D656E7473456E61626C656422…`).
2. PHP's serialization framing requires `s:<N>:"…"` to carry the exact byte
   length of each string. The real names measure `requirementsEnabled`=**19**
   (r-e-q-u-i-r-e-m-e-n-t-s(12) + Enabled(7)), `priorityEnabled`=**15**,
   `automationEnabled`=**17** — the stored `s:15/s:16/s:17` are wrong by
   −4/+1/0. PHP's strict scanner reads 15 chars of the key then expects `"`,
   finds `e` → `unserialize()` aborts at offset 26, raises `E_WARNING` and
   returns `false`.
3. `watchPHPErrors()` (`lib/functions/logger.class.php:1435`) only suppresses
   the **E_NOTICE** class of unserialize warnings, so the E_WARNING escapes
   into the `events` table.
4. Three decode sites of the column called **bare `unserialize()`** with no
   warning containment:
   - `lib/functions/testproject.class.php:3927` (`getOptions`) — fires on
     project-bound screens (2×/request in the issue).
   - `lib/functions/testproject.class.php:243` (`parseTestProjectRecordset`) —
     fires on list/recordsets.
   - `lib/functions/testproject.class.php:445` (`get_all`, `access_key` branch)
     — fires on the project management list.

**Why it breaks now:** the corrupted serialization is a static data defect from
the DB import; both modern writers (`api/projectedit/index.php:104`,
`api/projects/index.php:211`) already write correct lengths and use the
`@unserialize` pattern, so the warning is pure read-path noise amplified once
per decode call. The pre-existing first-byte guard (`testproject.class.php:3924`)
cannot detect mid-framing corruption.

**Blast radius (measured):** 3 decode sites, 1 E_WARNING event per call per
site. Fixing only line 3927 would merely relocate the noise to the two
recordset decoders.

## Fix

Committed on branch `fix/issue-1484`, commit `1de8a403a`:

Added a private decode helper that suppresses the warning and mirrors the repo's
existing corrupt-blob pattern (`lib/functions/metastring.class.php:121`):

```php
private function decodeStoredOptions($raw) {
  if (!is_string($raw) || $raw === '') {
    return false;
  }
  return @unserialize($raw);
}
```

and routed all three decode sites through it:
`parseTestProjectRecordset` (`:243`), `get_all` access_key branch (`:445`) and
`getOptions` (`:3927`). Post-conditions are byte-for-byte identical to before
(corrupt → `false` → existing `is_object`/`!== false` fallbacks: stdClass
defaults / empty object), so **zero behavior change** — only the E_WARNING
event spam disappears.

**Why this method:** `@unserialize` + false-check degrades silently, exactly the
"guarded non-warning fallback" the issue requested, and matches the pattern
already used in the file's siblings (`metastring.class.php:121`,
`api/projectedit/index.php:104`, `api/projects/index.php:211`).

**Rejected alternatives:** (a) whitelisting E_WARNING unserialize in
`watchPHPErrors`' suppression block — global masking that would hide genuine
corruption elsewhere; (b) repairing the stored blob at decode time — out of
scope, the DB is re-imported on every CI run and the empty-object fallback is
the pre-existing, intended behaviour.

## Files Changed

- `lib/functions/testproject.class.php` — new `decodeStoredOptions()` helper;
  three call sites switched from bare `unserialize()` (+26/−3).
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1484", 7/7 PASS.

## Verification

All checks on branch `fix/issue-1484`, PHP 8.3.33, MySQL fresh-import schema:

- Pre-fix control: 3 decode calls on the corrupt blob → 3 event rows at lines
  **3927 / 243 / 445**.
- Post-fix R1 (corrupt blob, all 3 decoders): `getOptions` → empty object;
  `get_by_id` / `get_all` → stdClass defaults; **0 new events**.
- Post-fix R2 (valid modern stdClass blob, all 3 decoders): object decoded with
  the 4 flags, **0 new events**.
- Post-fix R3 (`setOptions` round-trip): `requirementsEnabled` 1→0 persisted in
  the stored blob, **0 new events**.
- Post-fix R4 (empty `options=''`): empty object via first-char guard, **0 new
  events**.
- Static sweep: `grep -n unserialize lib/functions/testproject.class.php` → only
  the helper line (3951); `php -l` clean.
- Code-review subagent (AGENTS rule 16): PASS, no defects.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1484", 7/7 PASS.

Address: `Fixes #1484` (verified + pushed on `fix/issue-1484`).