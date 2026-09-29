# Issue #1627 — `reqMgrSystemEdit.php` answered HTTP 500 with a 0-byte body on a missing or non-whitelisted `doAction`

**Issue:** [#1627](https://github.com/sebiboga/testlink-upgraded/issues/1627)
**Commits:** `01f182bb9` (fix) + `7e014fea4` (regression suite)
**Branch:** `fix/issue-1627` (pushed; the CI `merge-prs.yml` lands it)
**Status:** VERIFIED-FIXED — regression suite **24/24 PASS** (`bash tmp/verify_1627.sh`, exit 0)

## Symptom

`lib/reqmgrsystems/reqMgrSystemEdit.php` (the legacy controller behind the **Req. Management
System** screen) returned **HTTP 500 with an empty body** for every request whose `doAction`
was missing or was not in the command bean's white list. The whitelisted actions were
unaffected (`?doAction=create` → 200/11488 B, `?doAction=edit&id=1` → 200/11715 B).

## Investigation (measured before any code was touched)

| URL | status | bytes |
|---|---|---|
| `/lib/reqmgrsystems/reqMgrSystemEdit.php` | **500** | **0** |
| `…?doAction=bogus` | **500** | **0** |
| `…?doAction=create` | 200 | 11488 |
| `…?doAction=edit&id=1` | 200 | 11715 |

`tmp/php_server.log` — the 500s are **fatals**, and the stack proves where the fault lives:

```
PHP Fatal error:  Uncaught Exception: Input parameter doAction - white list validation failure -
Value: - File: reqMgrSystemEdit.php - Function: init_args
in …/lib/reqmgrsystems/reqMgrSystemEdit.php:126
Stack trace:
#0 …/lib/reqmgrsystems/reqMgrSystemEdit.php(94): init_args()
#1 …/lib/reqmgrsystems/reqMgrSystemEdit.php(18): initScript()
#2 {main}
[200]/[500]: GET /lib/reqmgrsystems/reqMgrSystemEdit.php
```

### Refined diagnosis — the ticket's own hypothesis was wrong, and is corrected

The issue proposed that `renderGui()` dereferences a null `$opObj`
(`$opObj->template`, `$argsObj->doAction`). **Disproven:** execution never reaches
`renderGui()`. The last frame is `init_args()`, called from `initScript()` at line 94 —
`renderGui()` is only called at line 26, *after* `initScript()` returns. The fault is in
the **input-validation layer**.

Also corrected: the "missing `doAction`" case is **not** an absent property.
`R_PARAMS` always creates every declared input, so `property_exists()` is `true` and the
value is `''` — proven by the fatal's `Value:` (nothing after the colon) for the bare URL.
That is why the bare URL throws exactly like `doAction=bogus`: the flipped white list
(`reqMgrSystemCommands.class.php:38-39`, values
`checkConnection|create|edit|delete|doCreate|doUpdate|doDelete`) has no empty-string key.

### Not only reachable by a hand-typed URL

The shipped view forms post an **empty** hidden action and only fill it in with JS:

* `gui/templates/tl-classic/reqmgrsystems/reqMgrSystemView.tpl:83` —
  `<input type="hidden" name="doAction" value="" />`
* `gui/templates/tl-classic/reqmgrsystems/reqMgrSystemView.tpl:87` —
  `onclick="doAction.value='create'"` (dashio copy: `:85` / `:89`)

so a GET/POST of that form with JS disabled, aborted, or bookmarked posted an empty
`doAction` and hit the same 500. The ticket's "no in-app user can hit it today" was
therefore too optimistic.

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | `reqMgrSystemView.tpl:83` (tl-classic) / `:85` (dashio) | the form ships `doAction=""`, filled in by JS only |
| 2 | `reqMgrSystemEdit.php:18` | `initScript($db)` runs first |
| 3 | `reqMgrSystemEdit.php:94` | `init_args(array('doAction' => $mgr->getGuiOpWhiteList()))` |
| 4 | `reqMgrSystemCommands.class.php:38-39` | white list has **no** `''` key |
| 5 | `reqMgrSystemEdit.php:113` | `R_PARAMS` **always** creates `$args->doAction` (declared `:108`) |
| 6 | `reqMgrSystemEdit.php:120` | `isset($allowedValues[''])` is false → guard entered for `''` **and** `'bogus'` |
| 7 | `reqMgrSystemEdit.php:125-126` | `tLog($msg,'ERROR')` (good) **then `throw new Exception($msg)`** |
| 8 | `reqMgrSystemEdit.php:18` | nothing catches it — the file installs no `set_exception_handler` anywhere in `lib/` ⇒ PHP fatal ⇒ **HTTP 500, 0 bytes** |

**Why it breaks now:** step 7. The `throw`-inside-`init_args` is the only place in `lib/`
where a *missing* value is escalated to a fatal
(`grep -rln "white list validation failure" lib/` → this file only), and step 5 is why the
"parameter absent" branch at `:118` was dead code that could never protect anything.

**Latent landmine, not the observed cause** (the ticket's hypothesis, still real):
`renderGui():52,60` dereference `$opObj`, which is `null` when the action is not a
`$commandMgr` method (`:22-25`). `get_object_vars(null)` is a PHP 8 `TypeError`.

## Blast radius (measured)

| query | result |
|---|---|
| `grep -rln "white list validation failure" lib/` | **1** file — this controller |
| `grep -rln "getGuiOpWhiteList" lib/` | 2 — this controller + its command bean |
| 14 sibling legacy edit controllers | all use `switch ($args->doAction)` with **no throwing default**; this one was the outlier |
| modern screen | `gui/templates/reqmgrsystems/reqMgrSystemView.html` + `api/reqmgrsystems/` never call this controller — unaffected |

## The fix (minimal, 1 file, +33 / −7)

`lib/reqmgrsystems/reqMgrSystemEdit.php`, two hunks:

1. **`init_args()`** — the single guard that conflated two situations is split:
   * an **empty/absent** `doAction` is normalised to `'create'` and the form renders with
     200 (that is exactly the value the shipped form posts before its JS runs, and the
     documented expectation on the ticket);
   * a **non-empty, non-whitelisted** value keeps the unchanged `tLog(...,'ERROR')` audit
     row and is answered with a **302** to
     `gui/templates/reqmgrsystems/reqMgrSystemView.html` — the graceful handling other
     legacy 2.0.1 controllers already use (`lib/keywords/keywordsEdit.php:105`). No new
     user-facing string, therefore **no locale bundle touched**.
2. **`renderGui()`** — a defensive 3-line `is_null($opObj)` early `break`, so the 5 `case`
   values can never hit the PHP 8 `TypeError` on a null `$opObj`.

### Why this method (and what was rejected)

* *try/catch around the whole script* — broader than the defect and would silently swallow
  genuine errors for all 7 actions.
* *adding `''` to the white list* — would leave `doAction=bogus` (the crafted-value half of
  the reported symptom) still fatal.
* *a new 400 page with a new message* — needs a `lang_get()` key in all 20
  `locale/*/strings.txt` files for one line of text; the 302 achieves the same "no fatal"
  outcome with zero new strings and lands the user on a screen they can act on.
* *redirecting with 303 for POST* — considered; no functional difference today (the modern
  list screen ignores the method), left as 302 to match `keywordsEdit.php`.

### Security

Unchanged. The only newly reachable command is `create`, which performs **no** write
(`reqMgrSystemCommands.class.php:93-107` only builds a gui bean), and the whole controller
stays behind `checkRights()` → `reqmgrsystem_management` (`:177-180`, applied by
`testlinkInitPage()` at `:15` **before** `init_args()`). The write methods were already
whitelisted and reachable by URL before the fix.


## Verification

Regression suite **1627**, `bash tmp/verify_1627.sh` — **24/24 PASS** (exit 0). It creates
and deletes its own `reqmgrsystems` row, so it is re-runnable on a fresh import.

| before | after |
|---|---|
| no `doAction` → 500 / 0 B | **200 / 11488 B**, create form |
| `?doAction=` → 500 / 0 B | **200**, create form |
| `?doAction=bogus` → 500 / 0 B | **302 → `reqMgrSystemView.html`** + `ERROR` event |
| `?doAction=CREATE`, 28-char value → 500 | **302** + `ERROR` event |
| view form posted with the empty hidden `doAction` → 500 | **200**, create form |
| `?doAction=create` / `?doAction=edit&id=N` | 200, unchanged |
| `doCreate` / `doUpdate` / `doDelete` | 302 → `reqMgrSystemView.php`, row really written/renamed/deleted (DB-verified) |
| anonymous | bounced to `login.php?note=expired`, unchanged |
| Event Viewer | 0 new `E_WARNING`/`E_ERROR` rows from the controller; 3 `log_level 1` audit rows per rejected request, by design |

**Negative control:** with only the controller file reverted to
`git show 4be7ee6ff:lib/reqmgrsystems/reqMgrSystemEdit.php`, the same harness reports
**15 PASS / 9 FAIL** — it really detects the bug. `php -l` clean.

## Known, deliberately NOT fixed here (filed as new issues)

* `?doAction[]=x` is still a 500: the `TypeError` comes from the **shared** input layer
  (`lib/functions/inputparameter.inc.php:229` catches `Exception`, but the failure is a PHP
  `Error`) and therefore affects every legacy controller — a cross-cutting fix, not this
  ticket's.
* `?doAction=edit&id=<unknown id>` renders a broken form and logs 5 `E_WARNING` rows
  (`$gui->item` is `null`, unguarded `reqMgrSystemCommands.class.php:164`).
* `?doAction=delete` is whitelisted but has **no** command method → HTTP 200 with a
  0-byte body (`renderGui`'s `switch` has no `default:`). **Fixed by #1722** — see
  [Bugfix-Issue-1722-ReqMgrSystemEdit-Blank-200-Default-Render-Branch.md](Bugfix-Issue-1722-ReqMgrSystemEdit-Blank-200-Default-Render-Branch.md);
  `renderGui()`'s `$renderType` switch now has a `default:` that applies the same graceful 302.
* `?doAction=checkConnection` logs `E_WARNING Undefined array key "checkConnection"`
  (`initGuiBean`, `reqMgrSystemCommands.class.php:72`) — that is **#1628**.

## How to re-test in one command

```bash
bash tmp/verify_1627.sh        # 24/24 PASS expected, exit 0
```
