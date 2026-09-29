# Issue #1722 — `reqMgrSystemEdit.php` answered HTTP 200 with a blank body for the whitelisted-but-unrenderable actions `delete` and `checkConnection`

**Issue:** [#1722](https://github.com/sebiboga/testlink-upgraded/issues/1722)
**Commits:** `f9cc73b0e` (fix) + `099a44704` (regression suite)
**Branch:** `fix/issue-1722` (pushed; the CI `merge-prs.yml` lands it)
**Status:** VERIFIED-FIXED — regression suite **31/31 PASS** (`bash tmp/verify_1722.sh`, exit 0)
**Direct successor of:** [#1627](Bugfix-Issue-1627-ReqMgrSystemEdit-DoAction-500.md), which
fixed the *non-whitelisted* `doAction` and listed this exact blank 200 under "Known,
deliberately NOT fixed here".

## Symptom

`reqMgrSystemCommands::$guiOpWhiteList` advertises **7** actions, but `renderGui()`'s
`switch ($argsObj->doAction)` handles only **5**. The remaining two — `delete` and
`checkConnection` — passed #1627's whitelist validation (they *are* whitelisted), fell out of
the switch with no branch taken, left `$renderType` at its initial `'none'`, and were then
swallowed by the second switch's `default: break;`. The request ended as
**HTTP 200 with a 0-byte body**: a blank page, and — for `delete` — **not a single Event Viewer
row**, so the failure left no trace anywhere.

## Investigation (measured before any code was touched)

| `doAction` | HTTP | bytes | `Location` | new `events` rows |
|---|---|---|---|---|
| `delete` | **200** | **0** | — | **0** (silent) |
| `checkConnection&id=2` | **200** | **0** | — | 1 (`E_WARNING`, = #1628) |
| `checkConnection&id=1` | **200** | **0** | — | 1 (#1628) |
| `create` | 200 | 11488 | — | 0 |
| `edit&id=1` | 200 | >5000 (size is fixture-dependent; see below) | — | 5 (#1721, see below) |
| `doDelete&id=1` | 302 | 0 | `…/reqMgrSystemView.php` | 0 |
| `bogusAction` | 302 | 0 | `…/reqmgrsystems/reqMgrSystemView.html` | 1 (#1627) |
| *(empty)* | 200 | 11487 | — | 0 |

In the browser, `?doAction=delete` rendered a completely empty document — the title was the raw
URL, no markup, no error, no way back.

### Reachability — crafted URL only (measured, so `minor` severity is right)

1. Neither shipped theme can post those values. The only value the edit form ever posts is
   `{$gui->operation}` (`gui/templates/tl-classic/reqmgrsystems/reqMgrSystemEdit.tpl:129,131`
   and the dashio copy at `:129,131`), and `$actionOperation` (`reqMgrSystemEdit.php:40-42`)
   only ever yields `doUpdate` / `doCreate` / `''`. A repo-wide grep for `checkConnection`
   finds **zero** occurrences in any `reqmgrsystems` `.tpl`.
2. The modernized screen does not use this controller at all: the wrench button calls
   `checkConnection(id)` (`gui/templates/reqmgrsystems/reqMgrSystemView.html:182,185,274`),
   which is served by the BFF `api/reqmgrsystems/index.php:294-330` →
   `tlReqMgrSystem::checkConnection()`.
3. `delete` has no method on the command class at all, and the themes use `doDelete`.

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | `reqMgrSystemCommands.class.php:38-39` | `guiOpWhiteList = ['checkConnection','create','edit','delete','doCreate','doUpdate','doDelete']` — 7 values |
| 2 | `reqMgrSystemEdit.php:116` | `init_args(array('doAction' => $mgr->getGuiOpWhiteList()))` (`:116`, inside `initScript()`) |
| 3 | `reqMgrSystemEdit.php:153-171` (rationale in the comment at `:137-152`) | #1627's validation **passes** for `delete` / `checkConnection`: they *are* whitelisted. It only rejects values that are **not** whitelisted |
| 4a | `reqMgrSystemEdit.php:22` | `method_exists($commandMgr,'delete')` is **false** (no `delete()` on the class) ⇒ `$op` stays `null` ⇒ no work, no log, no output |
| 4b | `reqMgrSystemCommands.class.php:223-238` | `checkConnection()` **does** run and computes `connectionStatus = ok\|ko` … |
| 5 | `reqMgrSystemEdit.php:45-75` | …but the `switch` has cases for `edit\|create\|doDelete\|doCreate\|doUpdate` **only** — `checkConnection` takes no branch, so the bean is discarded |
| 6 | `reqMgrSystemEdit.php:36` | `$renderType` therefore stays `'none'` |
| 7 | `reqMgrSystemEdit.php:89-90` (pre-fix) | `default: break;` ends the request ⇒ **HTTP 200, 0 bytes, nothing logged** |

**Why it breaks now:** step 7. The white list and the `case` list were never in sync — a
1.9.20 defect inherited as-is. What 2.0.1 changed is the *contract*: #1627 established the
house rule that "this controller cannot do what you asked" is answered with a graceful **302
back to the list** (`reqMgrSystemEdit.php:150-153`). #1722 was the one remaining way to answer
the same situation **without** that rule. `checkConnection` used to be reachable from the
legacy edit form and `delete` used to be a real method; both entry points disappeared and
nothing was updated here.

**A second, latent path into the same sink:** #1627's hardening
`if (is_null($opObj)) { break; }` (`reqMgrSystemEdit.php:54-57`) also leaves
`$renderType === 'none'`. Unreachable today (all 5 `case` values are real methods), but it is
the same defect and the same fix closes it.

## Blast radius (measured)

| query | result |
|---|---|
| `grep -rl "requested action is not renderable" lib/` after the fix | **1** file — this controller |
| whitelisted-but-unrenderable actions in this controller | **2 of 7** (`delete`, `checkConnection`) |
| modern screen | `gui/templates/reqmgrsystems/reqMgrSystemView.html` + `api/reqmgrsystems/` never call this controller — **unaffected** |
| other controllers | untouched; `lib/keywords/keywordsEdit.php:105` is the pattern that was followed, not modified |
| i18n | **no** new user-facing string ⇒ **no** locale bundle touched |

## The fix (minimal, 1 file, +17 / −1)

`lib/reqmgrsystems/reqMgrSystemEdit.php` — the `default:` branch of the **`$renderType`**
switch (the sink of step 7) now does what `init_args()` does for a non-whitelisted action:

```php
      default:
        // Refs #1722: $renderType is still 'none' here, which means this controller
        // can not render the requested action. …
        tLog('reqMgrSystemEdit.php - requested action is not renderable - Value:' .
             $argsObj->doAction . ' - File: ' . basename(__FILE__) . ' - Function: ' . __FUNCTION__,
             'ERROR');
        $base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
        header('Location: ' . $base . 'gui/templates/reqmgrsystems/reqMgrSystemView.html', true, 302);
        exit();
```

### Why this method (and what was rejected)

* **The `$renderType` switch, not the `doAction` switch.** It is the single choke point every
  unrenderable path already funnels through, so one branch closes both step 5 (no `case`) and
  the latent step-4b variant (`case` matched but `$op` is `null`). A `default:` on the outer
  switch would have left the latter still blank.
* **302 to the modernized list, byte-identical to `init_args()` (`:163-169`).** The two
  rejections then look the same to the user, to a log reader and to a test. A 4xx/500 would be
  *worse* (it turns a cosmetic dead-end into an error); `die()` with a message is worse still
  (raw text in the user's face, breaks the frame-based layout).
* **One `tLog` ERROR row.** Measured: `delete` produced **0** rows of any kind, which is why
  the blank page was undiagnosable. A one-line reason makes the next occurrence greppable, and
  it matches the sibling rejection at `init_args():166`.
* *Rejected — remove `delete`/`checkConnection` from the white list.* Would make #1722
  *unreachable* rather than graceful, and would rewrite the command class' contract (the list
  also sanitizes input for other consumers) for a `minor` dead-URL gain. Larger blast radius
  than the bug.
* *Rejected — add a `case 'checkConnection'` and render it.* The modernized list already
  renders `connection_status` from the BFF (`api/reqmgrsystems/index.php:294-330`,
  `reqMgrSystemView.html:182,185`); a second renderer would be dead code for a URL no button
  emits.
* *Rejected — also fix `initGuiBean()`'s `Undefined array key "checkConnection"`.* That is
  **#1628**, a separate defect with its own issue; mixing it in would break the
  one-bug-per-run rule.

### Security

Unchanged. The new branch performs **no** command and **no** write — it logs and redirects. The
controller still sits behind `checkRights()` → `reqmgrsystem_management`
(`reqMgrSystemEdit.php:219-222`, applied by `testlinkInitPage()` at `:15` **before**
`initScript()`), so an unauthorised user still never reaches `renderGui()`. Nothing that was
previously unreachable became reachable.

## Verification

Regression suite #1722, `bash tmp/verify_1722.sh` — **31/31 PASS** (exit 0). It creates and
deletes its own `reqmgrsystems` row, so it is re-runnable on a fresh import.

| before | after |
|---|---|
| `?doAction=delete` → 200 / 0 B / **0 events** | **302 → `reqMgrSystemView.html`**, 0 B, **1 ERROR event** |
| `?doAction=checkConnection&id=N` → 200 / 0 B | **302 → the list**, 0 B, 2 events (new reason + #1628's warning) |
| `?doAction=bogus` → 302, `white list validation failure` | **unchanged** (a non-whitelisted value never reaches `renderGui()`) |
| `?doAction=` (empty) → 200, 11487 B | **unchanged** |
| `?doAction=create` → 200, 11487 B | **unchanged** |
| `doCreate` → 302, row inserted | **unchanged** (DB-verified) |
| `edit&id=N` → 200, form pre-filled | **unchanged** (DB-verified) |
| `doUpdate` → 302, `name`+`cfg` really changed | **unchanged** (DB-verified) |
| `doDelete` → 302, `count(*)=0` | **unchanged** (DB-verified) |
| `E_WARNING` rows produced by the whole CRUD cycle | **0** |
| Event Viewer | one intentional `log_level 1` ERROR row; **no** new Warning |

**Negative control:** with only the controller file reverted to `origin/sebiboga`, the very same
harness reports **24 PASS / 7 FAIL** (exit 1). All 7 are genuine behaviour: 3 × `delete`
(status / `Location` / log), 3 × `checkConnection` (status / `Location` / log) and the 9-value
sweep, which catches all three blank-200 values (`delete`, `checkConnection&id=1`,
`delete&id=1`) in one assertion. No assertion in the harness is self-referential — a first
draft ended with a `grep` for the new log string, which could only prove the *string* existed;
it was replaced by that sweep during code review precisely so the negative control proves
behaviour. Every shared behaviour passes **before and after** — that is the anti-regression
proof that the new `default:` never fires during normal use. `php -l` is asserted
unconditionally, so the count is exactly 31 with or without `php` on the `PATH`.

## Known, deliberately NOT fixed here

* **#1721** — `?doAction=edit&id=<missing id>` still logs 5 × `E_WARNING Trying to access array
  offset on null` in the compiled `reqMgrSystemEdit.tpl.php` (unguarded
  `reqMgrSystemCommands.class.php:164`, `getByID()` → `null`). Re-confirmed during this run
  that it does **not** occur for an existing id (0 warnings for the row the harness created).
* **#1628** — `E_WARNING Undefined array key "checkConnection"` at
  `reqMgrSystemCommands.class.php:72`. Still present, now alongside the new ERROR row.

## How to re-test in one command

```bash
bash tmp/verify_1722.sh        # 31/31 PASS expected, exit 0
```
