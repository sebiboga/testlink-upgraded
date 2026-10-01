<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource getreqmonitors.php
 * Purpose: retired legacy AJAX reader of the requirement monitor set.
 *
 * It is retired here because it was never authorized:
 *
 *   1. It performed NO rights check at all - only testlinkInitPage(), i.e. a
 *      SESSION check. Any authenticated user, including one with no
 *      requirement right, could send ?item_id=<id of a requirement of ANY test
 *      project> and read back the LOGINS of every user monitoring it. Same class
 *      of bug as #1696 (getrequirementnodes.php), #1765
 *      (getreqcoveragenodes.php) and #1770 (gettprojectnodes.php).
 *   2. requirement_mgr::getReqMonitors() was called on the raw id with no
 *      project scope and no ownership proof, so the response was not even
 *      constrained to the caller's own project.
 *   3. It was the ONLY reader left in lib/ajax/requirements/ and the only
 *      requirement-monitor read path without a modern twin: the legacy screen
 *      was a Smarty include (gui/templates/dashio/requirements/reqMonitors.tpl)
 *      of reqViewVersions.tpl, and the modern Requirement Viewer inlines the
 *      list from api/requirements instead, so the endpoint had no reachable UI
 *      of its own.
 *
 * The equivalent authorized surface is the modern Requirement Monitors popup
 * (gui/templates/requirements/reqMonitors.html) backed by api/reqmonitors,
 * which enforces mgt_view_req OR mgt_modify_req on the OWNING test project,
 * proves the requirement belongs to it, and returns the logins as DATA (the
 * modern screen escapes them itself).
 *
 * Legacy deep links (GET, i.e. a bookmark) are answered with a 302 to the modern
 * popup; the read is deliberately NOT replayed, because it was never authorized.
 * A write verb is refused with 405 and a pointer to the modern endpoint, and an
 * anonymous caller still bounces to login.php?note=expired (the legacy
 * testlinkInitPage contract, still reachable from the legacy Smarty include).
 */
require_once('../../../config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

// Legacy testlinkInitPage() contract: an anonymous visitor is bounced to the
// login screen. checkSessionValid()'s own redirect is used (rather than a
// hand-rolled header()) because it walks up from dirname(SCRIPT_FILENAME)
// until it finds login.php - a relative 'login.php' would resolve against
// /lib/ajax/requirements/ and 404.
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'GET' && $method !== 'HEAD') {
    tLog('BFF shim: refused ' . $method . ' on the retired legacy requirement-monitor reader - '
        . 'read the monitor set from GET /api/reqmonitors/index.php?action=init&req_id=N instead (Refs #1780).',
        'WARNING');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status'  => 'error',
        'code'    => 'wrong_method',
        'message' => 'This legacy endpoint is retired. Use GET /api/reqmonitors/index.php?action=init&req_id=N',
    ));
    exit;
}

// A crawler / an XHR (the legacy DataTable) must not be handed an HTML redirect
// body it will try to parse as JSON. A real navigation gets the modern screen.
$wantsJson = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] !== '')
    || (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] !== 'document')
    || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

if ($wantsJson) {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status'  => 'error',
        'code'    => 'retired_endpoint',
        'message' => 'This legacy endpoint no longer serves the monitor set. Open '
            . '/gui/templates/requirements/reqMonitors.html?req_id=N',
    ));
    exit;
}

$reqId = isset($_REQUEST['item_id']) ? intval($_REQUEST['item_id']) : 0;
$tprojectId = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
if ($tprojectId <= 0 && isset($_SESSION['testprojectID'])) {
    $tprojectId = intval($_SESSION['testprojectID']);
}

$url = '/gui/templates/requirements/reqMonitors.html?req_id=' . $reqId;
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}
header('Location: ' . $url, true, 302);
exit;