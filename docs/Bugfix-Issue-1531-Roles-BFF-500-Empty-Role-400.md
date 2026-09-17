# Issue 1531 — BFF POST /api/roles returns 500 (empty body) for rightIDs containing 0 instead of a 400 validation JSON

**Issue:** [#1531](https://github.com/sebiboga/testlink-upgraded/issues/1531)
**Branch:** `fix/issue-1531`
**Status:** VERIFIED-FIXED (2026-09-17)

## Symptom

`POST /api/roles/index.php` with a `rightIDs` array containing `0` crashed the
BFF: HTTP **500** with an **empty body** (a naked PHP fatal) instead of the
intended JSON validation error. The same happened for `rightIDs:[]` and for a
request with no `rightIDs` field at all — any request where no _valid_ right was
actually read. Discovered while testing issue #1530 (role-creation helper used
to build fixtures).

Measured before the fix:

```
{"name":"probe0_1530","rightIDs":[0]}  -> HTTP/1.0 500 Internal Server Error, Content-Type: application/json, EMPTY body
{"name":"probeok_1530","rightIDs":[3,6]} -> HTTP 200 {"status":"ok","item":{...},"feedback_key":"role_created"}
```

## Repro steps

1. Log in as admin at http://localhost:8082/index.php (admin/admin).
2. From the app origin run:
   ```js
   fetch('/api/roles/index.php', {method:'POST', headers:{'Content-Type':'application/json'},
     body: JSON.stringify({name:'probe0_1530', description:'probe0', rightIDs:[0]})})
   ```
3. **Before fix:** `{"status":500,"body":""}` (empty body → uncaught PHP fatal).
   **After fix:** HTTP 400 with
   `{"status":"error","message":"Role must have at least one right","messageKey":"role.error.noRights","code":-5}`.
4. Control (works before and after): `rightIDs:[3,6]` → 200 `role_created`.

## Root cause

Modernized BFF role creation in `api/roles/index.php:380-386` only appends a
right object to `$r->rights` when the freshly-read `tlRight` has a truthy
`dbID`:

```php
foreach ($body['rightIDs'] as $rid) {
    $right = new tlRight(intval($rid));
    $right->readFromDB($db);
    if ($right->dbID) { $r->rights[] = $right; }
}
```

For `rightIDs:[0]` (or `[]`, or a missing field) **no** right is appended, so
`$r->rights` stays `null` — `tlRole` declares `public $rights;`
(`lib/functions/tlRole.class.php:32`) and `_clean()` resets it to `null` on a
fresh object (`:78`).

`writeToDB()` then calls `checkDetails()` which validated the list with
`if (!sizeof($this->rights))` (`lib/functions/tlRole.class.php:224`). On PHP 8
`sizeof(null)` is a hard `TypeError` (on PHP 5.x it was only a warning), so the
request died with an uncaught fatal — HTTP 500 empty body — and the intended
`E_EMPTYROLE` branch (`:225`) was never reached, so the BFF 400 mapping
(`api/roles/index.php:393-397`, "Role must have at least one right") never
fired.

**Why it broke now:** this BFF endpoint is the only `tlRole::writeToDB` caller
that can hand the object to `checkDetails()` with `rights === null` — the legacy
`rolesEdit.php:103` always assigns an array (`tlRight::getAll(...)`), and the
BFF `PUT` route resets `$r->rights = []` before its loop
(`api/roles/index.php:424`). PHP 8.3 converts the latent PHP-5 warning into a
fatal.

**Blast radius:** `checkDetails` is only invoked from `writeToDB`
(`tlRole.class.php:185`); its callers are `rolesEdit.php:118` (legacy
doCreate/doUpdate always pass an array), the BFF POST (`:388`, reported case),
PUT (`:432`, already null-safe via `rights=[]`) and the duplicate route
(`:479`, `getByID` FULL → array). The one-line class-level check now treats a
`null` `rights` (any present or future caller that builds a role without
reading rights first — e.g. a fresh `new tlRole()`) as an empty role and yields
the `E_EMPTYROLE` 400 instead of a PHP-8 fatal, restoring the intended PHP 5.6
semantics.

## Fix

One line in `lib/functions/tlRole.class.php:224` — use the null-safe `empty()`
instead of PHP-8-unsafe `sizeof()`:

```diff
- if (!sizeof($this->rights)) {
+ if (empty($this->rights)) {
```

`empty()` is semantics-identical for arrays (`[]` → true → `E_EMPTYROLE`;
non-empty → false → OK, matching `!sizeof()` exactly) and additionally handles
`null` correctly (`null` → true → `E_EMPTYROLE`). The intended `E_EMPTYROLE` 400
branch becomes reachable for every invalid/no-right payload.

**Alternatives considered and rejected:**
- Initializing `$r->rights = []` only in the POST route — fixes the reported
  endpoint but leaves the class-level fatal in place for any other null-rights
  caller; attacks the symptom, not the cause.
- Mapping `readFromDB` failures to an explicit 400 in the BFF loop — that
  changes the role-id validation semantics (see #1535) and is a separate
  concern; out of scope for the reported 500.

## Regression matrix (all executed, live)

| # | Case | Result |
|---|---|---|
| 1 | POST `rightIDs:[0]` | **400** `Role must have at least one right` (was 500 empty) |
| 2 | POST `rightIDs:[]` | **400** same message |
| 3 | POST with no `rightIDs` field | **400** same message |
| 4 | POST `rightIDs:[3,6]` | **200** `role_created` (unchanged) |
| 5 | PUT /{id} `rightIDs:[0]` | **400** same message (already safe) |
| 6 | PUT /{id} valid rights, then DELETE | **200** updated + **200** deleted |
| 7 | UI create-role modal (browser) | role created, toast shown |
| 8 | UI no-rights save (browser) | blocked client-side "Select at least one right.", no request |
| 9 | Event Viewer | no new Error/Warning rows (AUDIT only) |

Screenshots:
- `tmp/wiki-repo/issue-1531-roles-view-post-fix.png` — Role Management screen
  after the fix (create modal + 400 fetch exercised).

## Related findings

Testing surfaced a **separate** pre-existing defect, filed as
[#1535](https://github.com/sebiboga/testlink-upgraded/issues/1535): a nonexistent
right id greater than 0 (e.g. `rightIDs:[99999]`) creates a role whose
`role_rights` references a right that does not exist (`rights:[{"id":99999,"name":null}]`),
because `tlRight::readFromDB` (default `TLOBJ_O_SEARCH_BY_ID`) never resets
`dbID` on read failure. Left unfixed here per the no-scope-expansion rule.