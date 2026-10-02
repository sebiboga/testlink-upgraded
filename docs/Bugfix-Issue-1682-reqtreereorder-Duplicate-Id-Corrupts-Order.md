# Bugfix — Issue #1682: `api/reqtreereorder` accepted a DUPLICATE requirement id in `nodes_order` and corrupted the specification order

**Issue** — [#1682](https://github.com/sebiboga/testlink-upgraded/issues/1682) (label `bug`, opened
`2026-09-28T05:28:34Z`, the oldest open bug at the time of the run)
**Screen / endpoint** — `gui/templates/requirements/reqTreeReorder.html` + `api/reqtreereorder/index.php`
(`POST ?action=reorder`)
**Component** — Requirement Specification Tree move / reorder BFF (ported in #1681)
**Fix commit** — `2c7fa2446 fix(reqtreereorder): reject a DUPLICATE requirement id in nodes_order (Refs #1681)`
**Verification branch** — `fix/issue-1682`
**Status** — fixed, verified error-free; closed with a verification comment once `fix/issue-1682` was pushed

---

## 1. Symptom

`POST /api/reqtreereorder/index.php?action=reorder` with a `nodes_order` array that **repeats a requirement
id** was answered `200 {"status":"ok","reordered":3}`. The write that followed rewrote `node_order` twice for
the duplicated requirement and **never rewrote the omitted one**, leaving the specification with two
requirements sharing the same `node_order` and an empty slot.

## 2. Repro steps

1. `php tmp/fixtures_1681.php` → `tproject=1`, `req_spec_id=2` (TR1-SPEC-A) with requirements `6` / `8` / `10`.
2. Authenticate. **2.0.1 does not log in through `POST /index.php`** (it answers 200 and re-`location.href`s
   to `/login.php`); the real form is `POST /login.php` with `tl_login` / `tl_password`.
3. `curl -H 'Content-Type: application/json' -H 'Origin: http://localhost:8082' \
    -d '{"tproject_id":1,"req_spec_id":2,"nodes_order":[6,6,10]}' \
    'http://localhost:8082/api/reqtreereorder/index.php?action=reorder'`
4. `SELECT id,node_order FROM nodes_hierarchy WHERE id IN (6,8,10);`

The `Origin` header is mandatory on writes — `bffSameOriginGuard()` rejects a header-less POST with 403.

## 3. Measured evidence

### Pre-fix

```
HTTP 200  {"status":"ok","reordered":3,"req_spec_id":2}

id | name               | node_order
 6 | First requirement  | 1   <- written TWICE (idx 1, then idx 2)
 8 | Second requirement | 1   <- NEVER written, keeps its stale value
10 | Third requirement  | 2
```

Two requirements on `node_order = 1`, `node_order = 0` empty. `"reordered": 3` counted the submitted
*entries*, not the distinct requirements, so the corruption was invisible to the caller.

### Post-fix

```
HTTP 400  {"status":"error","message":"Duplicate requirement id in nodes_order",
           "code":"invalid_nodes_order"}

id | node_order
 6 | 0
 8 | 1
10 | 2      <- unchanged; the rejected call wrote nothing
```

## 4. Root cause

`api/reqtreereorder/index.php` built the submitted order as a **positional list** but probed it **by
requirement id**:

```php
$order = array();
foreach ($submitted as $v) {
    $nid = intval($v);
    if ($nid <= 0) { failOut(400, 'Invalid requirement id in nodes_order', 'invalid_nodes_order'); }
    if (isset($order[$nid])) { ... duplicate ... }   // wrong: asks about array SLOT $nid
    $order[] = $nid;                                 // appends, so keys are 0,1,2,...
}
```

`isset($order[$nid])` answers "is **slot** `nid` filled in this list?", which has nothing to do with
"was **id** `nid` already seen?". The probe therefore read an unrelated key space and was wrong in **both**
directions, not merely too lax:

* **False negative** — for `[6,6,10]` the probe looks at a 3-element list, so `isset($order[6])` and
  `isset($order[8])` are always `false`. This is the reported corruption.
* **False positive** — whenever the requirement ids are *smaller* than the position being probed, a perfectly
  legal reorder was **refused** with "Duplicate requirement id". Measured by replaying the pre-fix loop over
  all 175 distinct-id permutations of the specs `{1,2,3}` `{1,2,3,4}` `{1,2,3,4,5}` `{6,8,10}` `{6,8,10,12}`
  (identity order excluded): **122 valid reorders were falsely refused**, e.g. spec `{1,2,3}` submitted
  `[2,3,1]` evaluates `isset($order[1])` on the half-built list `[2,3]` and rejects. The #1681 fixture uses ids
  `6 / 8 / 10`, all larger than the 3-element list, so the false positive is invisible there — which is exactly
  why the testers only saw the corruption half.

The fix cures both halves, because `$seen` is keyed in the same space the probe reads.

Two neighbouring gates could not compensate:

| Gate | Line | Why it did not fire |
|---|---|---|
| completeness `count($order) !== count($currentIds)` | `:534` | a list with a duplicated id has the same *length* as the real requirement set |
| membership `isset($currentSet[$nid])` | `:528` | `array_flip` produces a **set**; membership is idempotent, multiplicity is not represented |

The one coincidence that made the guard *look* alive is `isset($order[0])`, which *is* true on a real list —
but id `0` is already rejected by the `$nid <= 0` test at pre-fix `:457`, four lines above the guard at
pre-fix `:461`, so the check could never be right for any valid id.

The write loop then made the damage concrete:

```php
foreach ($order as $idx => $nid) {
    $db->exec_query('UPDATE nodes_hierarchy SET node_order = ' . intval($idx) . ' WHERE id = ' . intval($nid));
}                                                        // :550-554
```

One `UPDATE` **per submitted entry**: the duplicated id is written twice (last write wins, so the earlier
position vanishes) and the omitted sibling is never touched at all.

This was not a regression against 1.9.20 — the legacy endpoint
(`lib/ajax/dragdroprequirementnodes.php` → `tree::change_order_bulk(explode(',', $nodelist))`) had no
validation whatsoever. It was a gap in the *new* validation layer.

## 5. The fix

Track the ids in a **separate membership set**, never in the positional list, so probing and keying agree
(`api/reqtreereorder/index.php:493-512`):

```php
$order = array();
$seen  = array();
foreach ($submitted as $v) {
    $nid = intval($v);
    if ($nid <= 0) {
        failOut(400, 'Invalid requirement id in nodes_order', 'invalid_nodes_order');
    }
    if (isset($seen[$nid])) {
        failOut(400, 'Duplicate requirement id in nodes_order', 'invalid_nodes_order');
    }
    $seen[$nid] = 1;
    $order[] = $nid;   // positional list kept for the write loop and the completeness count
}
```

Because the set is keyed on the **`intval()`-normalised** id, the gate cannot be bypassed by sending the
same value with different JSON types (`["6",6,"6"]` is rejected), while ordinary numeric strings
(`["6","8","10"]`) are still accepted.

### Alternatives rejected

* **`array_unique($order)` after the loop** — would silently shrink the list, so the completeness gate would
  fire with a misleading `incomplete_nodes_order` message instead of naming the duplicate, and a
  de-duplicating caller would still get a wrong order written.
* **Key `$order` by id** (`$order[$nid] = $idx`, read back with `array_values()`) — works, but re-creates the
  exact "one variable, two keying conventions" confusion that produced the bug, for no benefit.
* **A DB-level guard** — `nodes_hierarchy` has no unique constraint on `(parent_id, node_order)`, so a
  duplicate order is perfectly legal SQL and nothing would surface.

## 6. Blast radius

```
$ grep -rn 'isset(\$order\[\$' api/
api/reqreorder/index.php:196:        if ($nid > 0 && !isset($order[$nid])) { $order[$nid] = intval($k); }
api/reqtreereorder/index.php:503:        // isset($order[$nid]) tested an unrelated slot …
```

* `api/reqreorder/index.php:196` (the `#1488` endpoint) is **not** affected — there `$order[$nid] = intval($k)`
  genuinely keys by id, so the same probe is correct.
* The only caller of this `?action=reorder` is `gui/templates/requirements/reqTreeReorder.html:498`, which
  sends `ITEMS.map(x => x.id)` — unique **by construction**. That is why no screen ever hit the bug and only
  a hand-crafted POST could. `gui/templates/requirements/reqReorder.html:141` posts to the *other* API.
* Storage blast radius when exploited: `nodes_hierarchy.node_order` for the whole specification — the exact
  ordering the specification viewer reads back, so the visible symptom is a scrambled requirement list.

## 7. Verification

Full suite in `tmp/TLU_Test_Cases.md` → *Regression — Issue #1682*.

**API matrix — 22 cases, all PASS.** Duplicate id first / middle / last / ×3; the same value with mixed JSON
types; a duplicate expressed as floats; float truncation; nested arrays; a saturating huge int;
`true` / `null`; `id = 0`; negative id; numeric strings (accepted, `no_change`); incomplete list; foreign id;
non-array payload; **valid reorder still lands** (`8=0, 10=1, 6=2`) and is idempotent; `GET` → 405;
anonymous → 401; role-3 user → 403; missing `Origin` → 403.

**No partial writes** — after all 20 rejected calls, `nodes_hierarchy` still held the accepted case-12 result.

**Browser (`chrome-devtools MCP`)** — `reqTreeReorder.html?tproject_id=1&req_spec_id=2` as `admin`:
row-button *Up* → tbody `TR1-2, TR1-1, TR1-3`, "Unsaved changes" chip, `#applyBtn.disabled === false`;
*Apply order* → a real Bootstrap `#confirmModal` (not a native `alert()` — that was #1683); confirming
persisted `8=0, 6=1, 10=2`. Console: 0 errors (one pre-existing `aria-hidden` focus warning from the
Bootstrap 3.4.1 modal focus trap). `events` with `log_level IN (1,2)`: `COUNT(*) = 0`.

**Screenshot** — `docs/screenshots/issue-1682-reqtreereorder-duplicate-rejected.png`.

## 8. Residual risk (pre-existing, not introduced by this fix)

`api/reqtreereorder/index.php:514` reads `orderedRequirements()` and `:550-554` then issues N separate
`UPDATE`s, with **no transaction** between them (the DB driver has none). Two concurrent authenticated
reorders of the *same* specification can interleave and re-create the very duplicate-`node_order` corruption
this issue is about. Out of scope for this defect and **not** introduced by the `$seen` fix; a future issue
should cover it (the legacy endpoint had the same exposure, so there is no parity regression).

Two further observations, deliberately left unchanged to keep this run's diff minimal:

* `:534`, `:557` and `:559` use `count($order)` where `count($seen)` would express the set invariant directly.
  Equivalent today because `$seen` and `$order` are built in the same loop, but `$seen` is the variable that
  carries the guarantee.
* Nested arrays (`[[6],[8],[10]]`) are answered "Duplicate requirement id" rather than "must be an array of
  ids", because `intval()` of a non-empty array is `1`. Safe (rejected, no write), only the message is
  imprecise.

## 9. Files

| File | Change |
|---|---|
| `api/reqtreereorder/index.php` | the fix itself (`:493-512`) — commit `2c7fa2446`, already on the default branch |
| `tmp/TLU_Test_Cases.md` | regression suite for #1682 |
| `CHANGELOG` | `[KEY BUGFIX - DATA INTEGRITY] - #1682` entry |
| `docs/Bugfix-Issue-1682-reqtreereorder-Duplicate-Id-Corrupts-Order.md` | this page |
| `docs/screenshots/issue-1682-reqtreereorder-duplicate-rejected.png` | screenshot evidence |
| `tmp/wiki-repo/Bugfix-Issue-1682-reqtreereorder-Duplicate-Id-Corrupts-Order.md` | Wiki mirror (same page + the image line) |
| `tmp/wiki-repo/issue-1682-reqtreereorder-duplicate-rejected.png` | Wiki copy of the screenshot |
