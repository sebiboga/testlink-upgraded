<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	reqSpecSearchForm.php
 * @package 	TestLink
 * @link     http://www.teamst.org/index.php
 *
 * Requirement Specification SEARCH FORM - MODERNIZED (Dashio standalone page)
 * - Refs #1825
 *
 * The legacy renderer (reqSpecSearchForm.php + gui/templates/dashio/
 * requirements/reqSpecSearchForm.tpl) has been replaced by:
 *
 *   gui/templates/requirements/reqSpecSearchForm.html   the screen
 *   api/reqspecsearchform/index.php                     the BFF (GET /init)
 *
 * and its form target, the still-legacy results renderer
 * lib/requirements/reqSpecSearch.php, has been replaced by the already-modern
 * gui/templates/requirements/searchReqSpec.html (api/requirements
 * ?action=reqspec-search) - this shim redirects to it carrying the criteria.
 *
 * The legacy controller authorized NOTHING but the session: it read
 * $gui->tproject_id from $_SESSION['testprojectID'] and listed the design-time
 * custom fields of that project to any authenticated user, right or not (same
 * class as #1696 / #1765 / #1770). The modern BFF enforces mgt_view_req (or
 * mgt_modify_req) on the ADDRESSED project before it resolves it, so the
 * endpoint is not even a test-project existence oracle.
 *
 * This file is a redirect ONLY: it holds no state and runs no legacy code
 * path. A browser navigation gets the modern criteria page; a crawler or an XHR
 * is told where the modern surface lives instead of being handed a login
 * redirect body to parse; a write verb is refused; an anonymous caller still
 * bounces to login.php?note=expired (the legacy testlinkInitPage contract).
 */
require_once('../../config.inc.php');
require_once('../functions/common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

// Legacy testlinkInitPage() contract. checkSessionValid() walks up from
// dirname(SCRIPT_FILENAME) until it finds login.php, so the relative path
// resolves from anywhere (a hand-rolled 'login.php' would 404 from
// /lib/requirements/).
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

if ($method !== 'GET' && $method !== 'HEAD') {
    tLog('BFF shim: refused ' . $method . ' on the retired legacy requirement-spec '
        . 'search form - use GET /api/reqspecsearchform/index.php?action=init '
        . '(Refs #1825).', 'WARNING');
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status'  => 'error',
        'code'    => 'wrong_method',
        'message' => 'This legacy endpoint is retired. Open '
            . '/gui/templates/requirements/reqSpecSearchForm.html',
    ));
    exit;
}

// An XHR / a crawler must not receive an HTML redirect body it will try to
// parse. A real navigation gets the modern screen.
$wantsJson = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] !== '')
    || (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] !== 'document')
    || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

if ($wantsJson) {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status'  => 'error',
        'code'    => 'retired_endpoint',
        'message' => 'This legacy endpoint no longer serves the search form. Open '
            . '/gui/templates/requirements/reqSpecSearchForm.html',
    ));
    exit;
}

// The legacy page read the test project from the SESSION only; the modern
// screen resolves param > session, so an explicit ?tproject_id= is honoured and
// a missing one falls back to the session (keeps a bookmark working).
$criteriaKeys = array('doc_id', 'name', 'reqSpecType', 'scope', 'log_message',
    'custom_field_id', 'custom_field_value', 'tplan_id');

$q = array();
$tprojectId = 0;
if (isset($_REQUEST['tproject_id']) && preg_match('/^[0-9]+$/', trim((string)$_REQUEST['tproject_id']))) {
    $tprojectId = intval(trim((string)$_REQUEST['tproject_id']));
}
if ($tprojectId <= 0 && isset($_SESSION['testprojectID'])) {
    $tprojectId = intval($_SESSION['testprojectID']);
}
if ($tprojectId > 0) {
    $q['tproject_id'] = $tprojectId;
}
if (isset($_REQUEST['tplan_id']) && intval($_REQUEST['tplan_id']) > 0) {
    $q['tplan_id'] = intval($_REQUEST['tplan_id']);
}
// Any criteria the bookmark carried is preserved, so the reader lands on a
// pre-filled form instead of an empty one.
foreach ($criteriaKeys as $k) {
    if ($k === 'tplan_id') { continue; }
    if (isset($_REQUEST[$k]) && trim((string)$_REQUEST[$k]) !== '') {
        $q[$k] = trim((string)$_REQUEST[$k]);
    }
}

$url = '/gui/templates/requirements/reqSpecSearchForm.html';
if (!empty($q)) {
    $url .= '?' . http_build_query($q);
}
header('Location: ' . $url, true, 302);
exit;