<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * LEGACY Test Cases with Custom Fields report endpoint - SUPERSEDED,
 * Refs #1205/#1206/#1207/#1208/#1209.
 *
 * In 1.9.20 this controller rendered the "Test Cases with Custom Fields"
 * report (the legacy template gui/templates/dashio/results/testCasesWithCF.tpl
 * was REMOVED with this retirement, Refs #1209): for a test plan,
 * every execution that has at least one execution-scope custom field value or
 * non-empty execution notes, with the linked Test Suite path, the execution
 * history / execute / edit operations, the version, platform, build, tester,
 * timestamp, status, notes and one column per linked custom field. It was an
 * ExtTable with a group-by-build toolbar.
 *
 * On 2.0.1 the report is fully served by the modern screen
 * gui/templates/results/tcasesWithCF.html + BFF
 * api/reports/index.php?action=tcases_with_cf, which reuses the same
 * cfield_mgr::get_linked_cfields_at_execution() calls and enforces the
 * testplan_metrics right on the OWNING test project and test plan. The modern
 * screen also forwards the clicked row's build/platform context to the
 * execution window (Refs #1207) and renders the report info note +
 * "Generated on" timestamp (Refs #1208).
 *
 * This controller is therefore kept as a session-guarded, non-mutating
 * launcher: an authenticated browser navigation is 302-redirected to the
 * modern screen (preserving the legacy ?tproject_id= / ?tplan_id= deep-link
 * context), an anonymous caller is bounced to the login screen by
 * checkSessionValid() exactly like the legacy testlinkInitPage() contract, and
 * any write verb / XHR caller is answered with a machine-readable 405 instead
 * of a legacy HTML body.
 *
 * @package    TestLink
 * @filesource testCasesWithCF.php
 */

require_once('../../config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

/**
 * Session gate, parity with the legacy testlinkInitPage() call: an anonymous
 * caller is bounced to the login screen preserving its destination.
 */
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'modern_endpoint_only',
        'message' => 'the report is served by /api/reports/index.php?action=tcases_with_cf'));
    exit;
}

// An XHR / non-document caller keeps the hard-fail contract: the legacy answer
// was a full HTML page, so handing a 302 to a script that expected markup would
// parse the redirect target as data. A document navigation is identified by
// Sec-Fetch-Dest: document OR an Accept header that asks for text/html (same
// logic as lib/results/keywordBarChart.php, Refs #1719).
$accept = strtolower((string)(isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : ''));
$isXhr = stripos((string)(isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : ''), 'XMLHttpRequest') !== false;
$dest = strtolower((string)(isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? $_SERVER['HTTP_SEC_FETCH_DEST'] : ''));
$isDocument = $dest === 'document' || strpos($accept, 'text/html') !== false;
if ($isXhr || $dest === 'empty' || $dest === 'image'
    || (!$isDocument && strpos($accept, 'image/') !== false)) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'modern_endpoint_only',
        'message' => 'the report is served by /api/reports/index.php?action=tcases_with_cf'));
    exit;
}

// is_scalar guards so a repeated parameter (?tplan_id[]=x) can never raise an
// "Array to string conversion" E_WARNING while being cast (Refs #1810).
$tplanId = (isset($_REQUEST['tplan_id']) && is_scalar($_REQUEST['tplan_id']))
    ? intval($_REQUEST['tplan_id']) : 0;
$tprojectId = (isset($_REQUEST['tproject_id']) && is_scalar($_REQUEST['tproject_id']))
    ? intval($_REQUEST['tproject_id']) : 0;
// Session fallbacks, parity with the legacy init_args() (testCasesWithCF.php:146/157).
if ($tprojectId <= 0) {
    $tprojectId = intval(isset($_SESSION['testprojectID']) ? $_SESSION['testprojectID'] : 0);
}
if ($tplanId <= 0) {
    $tplanId = intval(isset($_SESSION['testplanID']) ? $_SESSION['testplanID'] : 0);
}

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '../';
$url = $base . 'gui/templates/results/tcasesWithCF.html';
$qs = array();
if ($tprojectId > 0) {
    $qs[] = 'tproject_id=' . $tprojectId;
}
if ($tplanId > 0) {
    $qs[] = 'tplan_id=' . $tplanId;
}
if (count($qs) > 0) {
    $url .= '?' . implode('&', $qs);
}

header('Location: ' . $url, true, 302);
exit;
