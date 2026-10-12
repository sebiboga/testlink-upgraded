# Issue 1753 — Move/Reorder Test Suites: a no-op move/reorder was reported as an error and showed a raw server message

**Issue:** [#1753](https://github.com/sebiboga/testlink-upgraded/issues/1753)
**Branch:** `fix/issue-1753` · **Fix commit:** `13f2ca183` (landed in the `#1740` code-review commit)
**Screen:** `gui/templates/testcases/suiteMove.html` · **BFF:** `api/suitemove/index.php`
**Status:** VERIFIED FIXED (2026-10-12) — the issue was a stale report of a fix that had already landed

## Symptom

Clicking **Move up** on the first row, or submitting an unchanged order, answered
`HTTP 400 {"status":"error","code":"no_change"}`. The screen painted it as a red error
banner containing the untranslated server string `The suite is already at that boundary` /
`The order did not change`.

Two problems in one:

1. a no-op is a **success**, not a failure — every sibling BFF answers it with
   `HTTP 200 {"status":"no_change"}` (`api/tcreorder`, `api/tcstepsreorder`, `api/reqtreereorder`);
2. the BFF sent an English `message` that the screen displayed verbatim, bypassing i18n.

Additionally the `reorder` branch detected "nothing changed" **after** `change_order_bulk()`,
i.e. it reported a failure for a request that had already written to the DB.

## Root cause chain

The no-op answers lived in three places in `api/suitemove/index.php`, all reporting *failure*:

| # | Site (pre-fix) | Pre-fix | Fixed code |
|---|---|---|---|
| 1 | `move` up/down at a boundary | `out(... 'error','code'=>'no_change','message'=>'Suite is already at the boundary', 400)` | `api/suitemove/index.php:778-781` → `out(array('status'=>'no_change','message'=>'Suite is already at the boundary'))` (HTTP default 200) |
| 2 | `move` top/bottom already in place | same `400 status:error code:no_change` | `api/suitemove/index.php:837-843` → `out(array('status'=>'no_change','message'=>'The suite is already at that position'))` |
| 3 | `reorder` with an unchanged list | the no-op was checked **after** `change_order_bulk()` | `api/suitemove/index.php:987-991` → `if ($newOrder === $currentOrder)` runs **before** the write at `:993-994` |

`out($data, $code = null)` (`api/suitemove/index.php:153-160`) only calls
`http_response_code()` when an explicit code is passed; with no second argument the response is
HTTP 200. That is why the fixed calls answer 200 while the pre-fix `,400)` produced `HTTP 400`.

The i18n half was on the screen side: `gui/templates/testcases/suiteMove.html:392-396` treats
`no_change` as success and substitutes the localized key `smv.stNoChangeMsg` instead of echoing
`r.message`. The key is present in **all 10** locale bundles (`en.json:5199` … `zh.json:5199`).

### Why the report was stale

The defect was introduced by the BFF cut `a45462e2c` (`2026-09-30 12:35:24Z`; that commit touched
only `api/suitemove/index.php` — the screen `suiteMove.html` arrived later, in `0674bbdd5`). It was
fixed by a later `#1740` code-review commit, `13f2ca183` ("#1740: apply code-review fixes to
Move/Reorder Test Suites"): authored `2026-09-30 13:30:30Z`, committed/landed `2026-09-30 13:35:13Z`
(19 commits after `a45462e2c`). Issue #1753 was filed at `2026-09-30T13:30:46Z` — **after** the fix
was authored but **before** it was committed — describing the then-current code. `git merge-base
--is-ancestor 13f2ca183 origin/sebiboga` is true, so no build carries the reported behaviour today.

## Fix

No new change was required: the three `out(...)` calls above plus the screen branch already
implement the requested contract. This run verified that contract, added the `Regression — Issue
#1753` suite and this documentation, and closed the tracking issue.

### Alternatives considered (and why the chosen contract is right)

| Option | Verdict |
|---|---|
| keep `400` and only localize the message | rejected — the status code is the contract the siblings (`tcreorder` etc.) already fixed; clients now test `status` |
| answer `200 {"status":"ok"}` for a no-op | rejected — the screen would print the caller's "moved up" text, which is a lie |
| check the reorder no-op after `change_order_bulk()` | rejected — a "failure" for a request that already wrote |

## Verification

Live, on current `origin/sebiboga` (`6b7f83bf0`), fixture `FIX1753 Project (FX1753)`:

```bash
curl -s -c /tmp/tl_cookies.txt -X POST http://localhost:8082/login.php \
     -d "tl_login=admin&tl_password=admin&destination=&reqURI=&ssodisable="
B="http://localhost:8082/api/suitemove/index.php"; C='-b /tmp/tl_cookies.txt -H Origin:http://localhost:8082'

curl -s -w '\nHTTP %{http_code}\n' $C -X POST "$B" -d "action=move&tproject_id=1&node_id=2&position=up"
#   HTTP 200 {"status":"no_change","message":"Suite is already at the boundary"}

curl -s -w '\nHTTP %{http_code}\n' $C -X POST "$B" -d "action=reorder&tproject_id=1&container_id=1&nodelist=2,3"
#   HTTP 200 {"status":"no_change","message":"The order did not change"}
#   DB node_order stayed 2->0, 3->1  (no write)
```

| Case | Expected | Observed |
|---|---|---|
| `move` up on first row | `200 no_change`, no write | `200 no_change` |
| `move` down on last row | `200 no_change` | `200 no_change` |
| `reorder` current order | `200 no_change`, DB untouched | `200 no_change`, DB `2->0,3->1` |
| real `move` down / up | `200 ok`, order flips | `200 ok`, DB flips and restores |
| screen forced no-op | neutral localized notice | `.notice` = `The order is already like that - nothing to change.` |
| `events` ERROR/WARNING rows | 0 new | `0` |

Suite `Regression — Issue #1753` in `tmp/TLU_Test_Cases.md`: **7 API + 5 screen PASS / 0 FAIL**.

Screenshot: `docs/screenshots/issue-1753-noop-neutral-notice.png`.

## Related

* #1740 — the modernized screen and BFF this issue was found while reviewing.
* `13f2ca183` — the code-review commit that fixed it.
