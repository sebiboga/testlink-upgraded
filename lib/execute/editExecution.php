<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	editExecution.php
 *
 * LEGACY EXECUTION EDIT POPUP - REDIRECT SHIM (Refs #1911).
 *
 * In 1.9.20 this controller rendered the "Edit Execution" popup
 * (gui/templates/dashio/execute/editExecution.tpl): it edited the execution
 * **notes** and the execution-level **custom fields** of an existing execution
 * row, reached from execTest / execHistory / execSetResults through
 * openExecEditWindow(). The write path was `doUpdate()`:
 *   updateExecutionNotes($db, $exec_id, $notes)
 *   cfield_mgr->execution_values_to_db($request, $tcversion_id, $exec_id, $tplan_id)
 * behind the `testplan_execute` AND `exec_edit_notes` rights check.
 *
 * On 2.0.1 the screen is fully modernized:
 *   gui/templates/execute/editExecution.html
 *   api/executionedit/index.php  (GET ?action=init&exec_id=N,
 *                                 POST ?action=update)
 * The BFF enforces the same two rights on the OWNING test project, blocks
 * writes to a closed build, and proves every custom-field name belongs to a
 * field linked to the test case version.
 *
 * This controller is therefore kept as a session-guarded, non-mutating
 * launcher: an authenticated browser navigation is 302-redirected to the modern
 * popup (preserving the legacy ?exec_id= / ?tcversion_id= / ?tplan_id= /
 * ?tproject_id= deep-link context), an anonymous caller is bounced to the login
 * screen by checkSessionValid() exactly like the legacy testlinkInitPage()
 * contract, and any write verb / XHR caller is answered with a machine-readable
 * 405 instead of a legacy HTML body.
 *
 * @package    TestLink
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
        'message' => 'the popup is served by gui/templates/execute/editExecution.html; writes go to /api/executionedit/index.php?action=update'));
    exit;
}

// An XHR / non-document caller keeps the hard-fail contract: the legacy answer
// was a full HTML page, so handing a 302 to a script that expected markup would
// parse the redirect target as data. A document navigation is identified by
// Sec-Fetch-Dest: document OR an Accept header that asks for text/html (same
// logic as lib/results/testCasesWithCF.php, Refs #1719).
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
        'message' => 'the popup is served by gui/templates/execute/editExecution.html'));
    exit;
}

// is_scalar guards so a repeated parameter (?exec_id[]=x) can never raise an
// "Array to string conversion" E_WARNING while being cast (Refs #1810).
$execId = (isset($_REQUEST['exec_id']) && is_scalar($_REQUEST['exec_id']))
    ? intval($_REQUEST['exec_id']) : 0;
$tcversionId = (isset($_REQUEST['tcversion_id']) && is_scalar($_REQUEST['tcversion_id']))
    ? intval($_REQUEST['tcversion_id']) : 0;
$tplanId = (isset($_REQUEST['tplan_id']) && is_scalar($_REQUEST['tplan_id']))
    ? intval($_REQUEST['tplan_id']) : 0;
$tprojectId = (isset($_REQUEST['tproject_id']) && is_scalar($_REQUEST['tproject_id']))
    ? intval($_REQUEST['tproject_id']) : 0;

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '../';
$url = $base . 'gui/templates/execute/editExecution.html';
$qs = array();
if ($execId > 0) {
    $qs[] = 'exec_id=' . $execId;
}
if ($tcversionId > 0) {
    $qs[] = 'tcversion_id=' . $tcversionId;
}
if ($tplanId > 0) {
    $qs[] = 'tplan_id=' . $tplanId;
}
if ($tprojectId > 0) {
    $qs[] = 'tproject_id=' . $tprojectId;
}
if (count($qs) > 0) {
    $url .= '?' . implode('&', $qs);
}

header('Location: ' . $url, true, 302);
exit;
