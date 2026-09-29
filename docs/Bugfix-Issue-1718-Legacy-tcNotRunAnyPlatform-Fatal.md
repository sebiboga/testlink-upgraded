# Issue #1718 — Legacy `tcNotRunAnyPlatform` is fatally broken: requires the removed `results.class.php` and calls a method on null

**Issue:** [#1718](https://github.com/sebiboga/testlink-upgraded/issues/1718)
**Commits:** `047111e9d` (the fix) + `7287257e4` (regression suite + code-review corrections)
**Branch:** `fix/issue-1718`
**Status:** VERIFIED-FIXED (2026-09-29)

## Symptom

`lib/results/tcNotRunAnyPlatform.php` — the 1.9.20 **"Test Report: Test Cases not run on
any Platform"** controller — could not run at all. Every request to
`/lib/results/tcNotRunAnyPlatform.php?tplan_id=<id>` as any user holding
`testplan_metrics` answered **HTTP 500 with a 0-byte body**:

```
[26/Sep/29 13:38:01][WARNING][<nosession>][GUI]
	E_WARNING
require_once(results.class.php): Failed to open stream: No such file or directory
  - in .../lib/results/tcNotRunAnyPlatform.php - Line 16
[<<][6abbbf393d625757766614][DEFAULT][/lib/results/tcNotRunAnyPlatform.php][26/Sep/29 13:38:01][26/Sep/29 13:38:01][took 0.00275 secs]
```

Note the timing: **one** request, one warning, done in **2.75 ms**. This is a hard fatal, not
a hang and not a loop — anyone chasing a "slow report" theory would have been wrong.

It went unnoticed because the report was also **missing from `cfg/reports.cfg.php`**, so nothing
in the Reports ASIDE menu ever linked it. #1717 re-registered the report pointing at a modern
screen and filed this bug.

## Investigation

**Environment.** App `http://localhost:8082` (PHP built-in server, docroot = repo root);
MariaDB `127.0.0.1:3306/testlink` (`testlink`/`testlink`), freshly imported; user `admin`
(id 1, role 8) — holds `testplan_metrics`. Dataset rebuilt for the run by
`php tmp/fixtures_1717.php`: test project 1 (prefix `TNR1717`), test plan 2, platforms
1 = Windows 11 / 2 = Linux Ubuntu 22, build 1 (active+open) + build 2 (CLOSED), 5 test cases,
4 executions, plus a no-rights user `tnrap1717norights` (role 3).

**Repro (the BFF login is CSRF-guarded, so a same-origin `Origin` header is required):**

```bash
curl -s -c cj.txt -b cj.txt http://localhost:8082/login.php -o /dev/null
curl -s -c cj.txt -b cj.txt -X POST http://localhost:8082/api/auth/login \
     -H "Origin: http://localhost:8082" -d "login=admin&password=admin"
curl -s -b cj.txt "http://localhost:8082/lib/results/tcNotRunAnyPlatform.php?tplan_id=2" \
     -o /dev/null -w "HTTP %{http_code}\n"      # → HTTP 500, 0 bytes
```

**Measured, layer by layer.**

| Layer | Measurement | Conclusion |
|---|---|---|
| server log | `logs/userlog1.log` — one `E_WARNING` at `Line 16`, request completed in 2.75 ms | request arrives, dies in the include |
| filesystem | `ls lib/functions/results.class.php` → ENOENT | the required file is gone |
| class | `grep -rn getMapOfLastResult` over the tree → **one** pre-fix *code* call site (the controller's own `:62`), no definition anywhere | the class is gone too — there is nothing to restore |
| **isolated probe** | the controller copied to a scratch file with **only** the `require_once` removed → `HTTP 500`, 0 bytes, and `E_WARNING Undefined variable $re - in .../zz_probe_1718.php - Line 62` | the second fatal is real, not a theory |
| same probe, no project selected | `HTTP 500` with `<pre>#0 ...(38): testproject->get_by_id(0)</pre>` | a **third, unreported** failure path |
| browser | the report is only reachable at `gui/templates/results/tcNotRunAnyPlatform.html`; **0 console errors** | the modern path is clean, the legacy one is invisible in normal navigation |

The probe file was deleted immediately afterwards; it never entered the tree.

## Root cause

Four **independent** hard failures, all measured before a single line was changed:

1. `:16` — `require_once('results.class.php')`. The file does not exist, and the class it
   defined has no surviving implementation, so the include is a hard fatal.
2. `:49` — `// $re = new results(...)` is **commented out**, while `:62` still runs
   `$re->getMapOfLastResult()` → `Undefined variable $re` then
   `Call to a member function getMapOfLastResult() on null`.
3. `:59` — `// $executionsMap = $re->getSuiteList();` is likewise commented out, while
   `:124` reads `$executionsMap[$suiteId]` and feeds it to `sizeOf()` at `:133`
   → `sizeOf(null)` TypeError. **Latent**: only reached once 1 and 2 are papered over.
4. `:37-40` — `$tplan_info['name']` / `$tproject_info['name']` are read with no null guard.
   `testproject::get_by_id(0)` *returns* `null` rather than throwing
   (`lib/functions/testproject.class.php:345-355` throws only for `NULL`), so the failure
   surfaces at the missing name/prefix — `:40` / `:42`. This fires *before* the `$re` fatal
   whenever no test project is selected.

**Why it breaks now:** it does not break — it never worked in 2.0.1. The 1.9.20 `results` class
and the `getMapOfLastResult()` query behind it were dropped when the report query layer was
replaced; the controller was left behind, unreachable from the menu, and it rotted unnoticed.

## The fix — retire the dead cluster

`047111e9d` deletes the three files:

| File | Lines |
|---|---|
| `lib/results/tcNotRunAnyPlatform.php` | 273 |
| `gui/templates/dashio/results/tcNotRunAnyPlatform.tpl` | 65 |
| `gui/templates/tl-classic/results/tcNotRunAnyPlatform.tpl` | 66 |

(Line counts per `git show 047111e9d --stat`. Neither `.tpl` ends in a newline, so `wc -l`
undercounts each by one.)

This is the established repo pattern for a modernized screen: the four prior retirements are
`332905e56` (cfieldsView), `596444f30` (issuetrackerView), `a406f745d` (codeTrackerView) and
`029342980` (pluginView), all `chore(<area>): delete legacy … cluster`.

**No report is lost.** Nothing in the application linked the dead file:

* `cfg/reports.cfg.php:270` — the report's `url` is `gui/templates/results/tcNotRunAnyPlatform.html`
* `lib/general/asideMenu.php:238-240` — the Reports ASIDE entry builds the same modern URL
* `lib/functions/common.php:2094-2095` — `$actions->tcNotRunAnyPlatform` is the same modern URL
* no `{include file=…tcNotRunAnyPlatform…}` anywhere, and **no cfieldsView-style hazard**:
  template ownership is 1:1, because `templateConfiguration()` derives the template from
  `basename($_SERVER['SCRIPT_NAME'])` (`lib/functions/common.php:938` → `:945` `$access_key . '.tpl'`),
  the controller was the only script named `tcNotRunAnyPlatform`, and no `cfg` remap exists
* `directLink` is `''`, the print/export gateways only ever `include lib/results/printDocument.php`,
  and the `reports_list` consumers (`api/resultsnav/index.php:173`, `api/aside/index.php:118`,
  `api/publiclink/index.php:160-161`) all read the modern URL

**No locale key was removed.** `link_report_not_run_on_any_platform` is still live
(`cfg/reports.cfg.php:269`, `lib/general/asideMenu.php:238`) and `tcversion_indicator` is live in
8 other files.

**Alternatives considered and rejected:**

* *Re-add `lib/functions/results.class.php`* — there is no surviving implementation to restore,
  and the report's semantics are already re-implemented natively on
  `tlTestPlanMetrics::getNeverRunByPlatform()`. Writing it would be new code, not a fix.
* *Reduce the file to a redirect to the modern screen* — it would have zero in-app callers
  (measured above) and is not the repo pattern.
* *Comment out the broken statements* — leaves ~200 lines of unreachable code that still
  advertises itself as a working report, which is the actual complaint.

`7287257e4` additionally rewrites the three comments that the deletion made *false*
(`lib/general/asideMenu.php`, `lib/functions/common.php`, `api/reports/index.php:4872` — they
said the dead controller "is not linked"). No logic changed in any of the three.

## Verification

`bash tmp/verify_1718.sh` → **19 assertions, 19 PASS, 0 FAIL** (exit 0).

The suite is deliberately **not** only "the file is gone" — a pure existence check would still
pass if the deletion had taken the report with it:

| Group | Cases | What it pins |
|---|---|---|
| the fix | 1-6 | legacy URL answers **404**; both `.tpl` + the `.php` deleted; **no** call site left in `lib/`; `results.class.php` still absent (the fix must not re-introduce the coupling) |
| the report survived | 7-12 | BFF `status ok`, **3 of 5** never-run, rows `TNR1717-1` / `TNR1717-4` / `TNR1717-5` — identical to the pre-fix capture |
| the guards survived | 13-16 | `400` missing ids / `400` unknown plan / `400` foreign project / `403` measured through a **real second login** as `tnrap1717norights` |
| the area survived | 17-19 | 0 new `events` rows beyond the 1-row pre-fix baseline; `php -l` on all **34** remaining `lib/results/*.php`; **`php tmp/test_1717.php` still 40/40 PASS** |

Browser verification of the surviving report (`http://localhost:8082/gui/templates/results/tcNotRunAnyPlatform.html?tproject_id=1&tplan_id=2`):
renders "Found **3** of **5** test cases in this test plan", one column per platform
(Linux Ubuntu 22 / Windows 11), priority `Medium`, `linkto.php?tprojectPrefix=TNR1717&item=testcase&id=…`
deep links, design + Execution History popups, the "About this report" legend,
`Elapsed seconds: 0.01` — **0 console errors/warnings**. The Reports ASIDE entry still resolves
to the modern screen.

**Event Viewer:** the only `log_level<16` rows from the whole session are id 3 (the original
`require_once` warning, i.e. the pre-fix evidence) and the deleted controller's own reproduction
probe. Every request after the fix added **0** rows.

## The suite caught a false PASS in this run's own verification

Case 5 (`no getMapOfLastResult() call site left in lib/`) **FAILED with 2** on its first run.
The two hits were not call sites but the #1717 explanatory comments in
`lib/general/asideMenu.php:237` and `lib/functions/common.php:2089`. The real problem was
upstream: the "PASS — 0" reported for that case during the fix phase had been produced by a
`grep` run with a stale `cd /tmp/opencode` still in effect, so `lib/` did not exist in the
current directory and `grep` matched nothing while reporting `0` — a **silent false PASS**.
The assertion now strips `//` and `/* */` before counting, which is what the check was
supposed to mean.

## Code review findings (mandatory subagent pass over `047111e9d`)

2 BLOCKERs, 4 SHOULD-FIX, 4 NITs — all resolved:

* **BLOCKER** — the CHANGELOG claimed "Regression suite 1718 8/8 PASS" while no suite existed
  (the mandatory FIX-ISSUE.md §5 deliverable). Fixed: `tmp/verify_1718.sh` + the
  `Regression — Issue #1718` entry in `tmp/TLU_Test_Cases.md`; the CHANGELOG now cites the real
  `19/19 PASS` and the harness command.
* **BLOCKER** — two deleted-file line counts were wrong (64/65 → the true 65/66, because
  `wc -l` undercounts a file with no trailing newline). Fixed.
* SHOULD-FIX — three of my own claims were imprecise and are now exact: the pre-fix run wrote
  exactly **one** warning row (`events` id 3), not "a row per request"; the `:37-40` failure
  surfaces at `:40`/`:42` because `get_by_id(0)` *returns* `null`; and there was **one** pre-fix
  code call site, not two.
* The review confirmed the deletion is safe and complete, and that the scope is exactly right:
  the single remaining `results.class.php` consumer in the tree is the unregistered
  `lib/results/priorityBarChart.php:5`.

## Also found and filed, NOT fixed here (one-bug run)

* **#1719** — `lib/results/priorityBarChart.php:5` requires the *same* removed
  `../functions/results.class.php` and instantiates the same removed `results` class at `:17`.
  Also unregistered; its line 2 even says `//@TODO this file seems not to be in use`.
* **Four now-permanently-dead locale keys** (their only users were the deleted cluster, each still
  declared in 12 `locale/*/strings.txt`): `title_test_report_not_run_on_any_platform`,
  `not_run_any_platform_status_msg`, `not_run_any_platform_no_platforms`,
  `info_tcNotRunAnyPlatform`. Reported, not removed — rewriting 12 mixed-encoding catalogues is
  exactly the drive-by work a one-bug run must not do.

## Files changed

| File | Change |
|---|---|
| `lib/results/tcNotRunAnyPlatform.php` | **deleted** (273 lines) |
| `gui/templates/dashio/results/tcNotRunAnyPlatform.tpl` | **deleted** (65 lines) |
| `gui/templates/tl-classic/results/tcNotRunAnyPlatform.tpl` | **deleted** (66 lines) |
| `lib/general/asideMenu.php` | comment only |
| `lib/functions/common.php` | comment only |
| `api/reports/index.php` | comment only (`not_run_any_platform` block header) |
| `CHANGELOG` | 2.0.1 `[KEY BUGFIX] - #1718` entry |
| `tmp/verify_1718.sh` | new regression harness (19 assertions) |
| `tmp/TLU_Test_Cases.md` | `Regression — Issue #1718` suite entry |

## RESUME

```bash
git checkout fix/issue-1718
php tmp/fixtures_1717.php          # recreate the dataset (the DB is imported fresh every run)
bash tmp/verify_1718.sh            # expect: 19 assertions, 19 PASS, 0 FAIL, exit 0
php tmp/test_1717.php              # expect: 40 assertions, 40 PASS, 0 FAIL
```

Browser: `http://localhost:8082/index.php` (admin/admin) → Metrics & Reports → Test Cases Not
Run on Any Platform. The legacy URL
`http://localhost:8082/lib/results/tcNotRunAnyPlatform.php?tplan_id=2` must answer **404**.
