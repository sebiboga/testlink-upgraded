# Issue 426 — Audit: 8 templates existed only under tl-classic (dashio pages fatal)

Source: https://github.com/sebiboga/testlink-upgraded/issues/426
Status: **CLOSED (fix already on default branch; verification & docs completed by this run)**

## The HOW — root cause and fix approach

`TLSmarty` hardcodes its Smarty search path to `gui/templates/dashio/…`
(`lib/functions/tlsmarty.inc.php:102`), while `templateConfiguration()`
(`lib/functions/common.php:901`) derives the template name from the script
path. Eight scripts therefore rendered `.tpl` files that existed only under the
legacy `gui/templates/tl-classic/` tree → every visit died with
`Smarty: Unable to load template 'file:<name>.tpl'` (HTTP 500).

**Method chosen:** straight port (copy) of the 8 templates into dashio — the
intended approach per the audit (the sibling `resultsTCFlatLauncher.tpl` is
byte-identical between themes). This landed on the default branch as commit
`764d625e1`. Alternatives rejected: remapping via `$g_tpl` (would keep legacy
markup alive), rewriting pages as modern HTML screens (belongs to the screen
modernization workflow).

This run reproduced the failure mechanism experimentally (removed one ported
template → exact Smarty fatal + HTTP 500; restored → HTTP 200), then executed a
per-page verification matrix with legacy-UI-equivalent arguments.

## Verification results

| Page | Invocation | Result |
|---|---|---|
| Baselines L1&L2 | `baselinel1l2.php?tplan_id=2&tproject_id=1&format=0` | ✅ 200 |
| Absolute Latest (launcher) | `resultsTCAbsoluteLatest.php?…&doAction=choose` | ✅ 200 |
| Absolute Latest (matrix) | `…&doAction=result&platform_id=20&format=0` | ✅ 200 |
| Assign TC Execution | `tc_exec_assignment.php?…&doAction=std&level=testsuite&build_id=10` | ✅ 200 |
| Inventory | `inventoryView.php` | ✅ 200 |
| Req Mgmt view / edit | `reqMgrSystemView.php` · `reqMgrSystemEdit.php?doAction=create` | ✅ 200 |
| Test Automation Spec | `testAutomationSpec.php?…&format=0` | ❌ 500 via `specview.php:807` → separate root cause, tracked in #604 |

Regression suite: Suite 426 in `tmp/TLU_Test_Cases.md`.

## Screenshots


## Residual defects discovered during verification (filed separately)

- **#637** — aside-menu report links carry no query args at all (`asideMenu.php:79`): every legacy report from the modernized menu 500s or renders empty.
- **#638** — `resultsTCAbsoluteLatest.php:313`: `count(null)` TypeError when the plan has no builds.
- **#604** — pre-existing: testAutomationSpec fatals inside specview.php regardless of templates.
