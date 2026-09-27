<?php
/**
 * Direct Links Frameset Gateway - legacy shim (Refs #1677).
 *
 * This entry point is MODERNIZED:
 *   - api/ltx/index.php                   -> BFF resolver (auth, rights, JSON)
 *   - gui/templates/links/ltxDirectLink.html -> resolver screen (Dashio)
 *
 * WHAT THE LEGACY SCRIPT DID
 * --------------------------
 * `ltx.php?item=<exec|xta2m>&...` was a TWO-STEP Smarty FRAMESET:
 *   Step 1 (outer frame) init_args() + checkTestPlan() then `main.tpl`, i.e.
 *           navBar + asideMenu + an inner iframe pointing back at ltx.php
 *           with `&load=1`;
 *   Step 2 (inner frame) `launch_inner_exec()` / `launch_inner_xta2m()`
 *           re-resolved the context and displayed `frmInner.tpl` (exec
 *           navigator + execSetResults) or `workframe.tpl` (single frame),
 *           pointing at the still-legacy `lib/execute/execSetResults.php`
 *           and `lib/execute/execNavigator.php`.
 *
 * It was the LAST root-level legacy frameset gateway still rendering Smarty
 * (`main.tpl` / `frmInner.tpl` / `workframe.tpl`); its siblings are already
 * modernized - `linkto.php` -> gui/templates/links/directLink.html
 * (Refs #1532/#1542), `lnl.php` -> gui/templates/links/publicLink.html
 * (Refs #1541), `ltcp.php` -> gui/templates/testcases/tcLaunchPrint.html
 * (Refs #1623).
 *
 * The 2.0.1 shell (navBar + asideMenu + content iframe) is served by
 * index.php, so the outer frameset is simply dropped: the modern screen
 * resolves the same item and hands over to the ALREADY-MODERN screens
 * `gui/templates/execute/execSetResults.html`,
 * `gui/templates/execute/execNavigator.html` and
 * `gui/templates/results/assignedTcOverview.html`.
 *
 * This shim keeps every existing deep link working unchanged:
 *   ltx.php?item=exec&build_id=N&feature_id=M
 *   ltx.php?item=exec&build_id=N&tplan_id=M&tcversion_id=V&platform_id=P
 *   ltx.php?item=exec&load=1&...
 *   ltx.php?item=xta2m&user_id=U&tplan_id=M[&build_id=N]
 * The whole query string is forwarded unchanged; the BFF owns the item
 * whitelist, the `testplan_execute` right check and the xta2m "assigned to
 * ME" self-check (all of which the legacy inner frame skipped - the fix
 * documented in api/ltx/index.php).
 *
 * Unlike ltcp.php/lnl.php this gateway is NOT public: ltx.php ran
 * testlinkInitPage($db, true), so an anonymous deep link was bounced to the
 * login page. That contract is preserved here - the session guard runs
 * BEFORE the redirect, so a bookmarked ltx.php link still lands on
 * login.php?note=expired&destination=... instead of on a screen that would
 * immediately answer 401.
 */

// use output buffer to prevent headers/data from being sent before cookies
// are set, else the session guard cannot start the session (legacy line 23)
ob_start();

// some session and settings stuff from original ltx.php
require_once('lib/functions/configCheck.php');
checkConfiguration();
require_once('config.inc.php');
require_once('common.php');
// legacy contract: anon -> login.php (preserved verbatim, incl. the
// destination= round trip testlinkInitPage builds)
testlinkInitPage($db, true);

// Strip control characters that could poison the Location header.
$qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
$qs = str_replace(["\r", "\n"], '', $qs);

header('Location: ' . TL_BASE_HREF . 'gui/templates/links/ltxDirectLink.html' .
       ($qs !== '' ? '?' . $qs : ''), true, 302);
ob_end_flush();
exit();
