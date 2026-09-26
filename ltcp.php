<?php
/**
 * Launch TestCase Print - public share-link entry point (legacy shim).
 *
 * Refs #1623. This entry point is MODERNIZED:
 *   - api/tcprintlaunch/index.php        -> BFF resolver (auth + resolution, JSON)
 *   - gui/templates/testcases/tcLaunchPrint.html -> resolver screen
 *
 * The legacy implementation resolved the share link inline and redirected
 * into the STILL-LEGACY lib/testcases/tcPrint.php renderer:
 *
 *   ltcp.php?apikey=<32-char user key>&testcase=<PREFIX>-<NUM>-<VERSION>
 *
 * It answered its 6 failure modes with raw, untranslated English markers
 * echoed straight into the page (LTCP-01 .. LTCP-05 plus a bare empty body
 * for a missing version). The modern screen renders the same outcomes through
 * localized cards and hands over to the already-modern print screen
 * gui/templates/testcases/tcPrint.html, so no lib/**.php page is rendered
 * any more.
 *
 * This shim keeps every existing share link working unchanged: it only
 * forwards the browser to the modern screen, preserving the whole query
 * string. It deliberately performs NO authentication and NO redirect
 * (ltcp.php is a public, apikey-authenticated gateway - the BFF owns the
 * rights check), exactly like the lnl.php publicLink shim (Refs #1541).
 */

// some session and settings stuff from original ltcp.php
require_once('config.inc.php');
require_once('reports.cfg.php');
require_once('common.php');
// keeps legacy parity: bounce an uninstalled instance to the installer
// instead of failing with a raw PHP fatal (legacy ltcp.php lines 19-20)
require_once('lib/functions/configCheck.php');
checkConfiguration();

// strip any control characters that could poison a Location header
$qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
$qs = str_replace(["\r", "\n"], '', $qs);

header('Location: ' . TL_BASE_HREF . 'gui/templates/testcases/tcLaunchPrint.html' .
       ($qs !== '' ? '?' . $qs : ''), true, 302);
exit();
