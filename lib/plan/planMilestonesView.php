<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * Test Plan Milestones - LEGACY VIEW CONTROLLER REDIRECT SHIM (Refs #1970).
 *
 * The list/view screen is now fully modernized:
 *   gui/templates/plans/planMilestones.html
 *   api/milestones/index.php
 *
 * This controller is kept as a session-guarded, non-mutating launcher:
 * an authenticated browser navigation is 302-redirected to the modern screen
 * (preserving the legacy ?tplan_id= / ?tproject_id= context), anonymous
 * callers are bounced to the login screen, and non-GET/XHR callers receive
 * a 405 response.
 */
require_once('../../config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

if (!checkSessionValid($db)) {
    exit;
}

$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'modern_endpoint_only',
        'message' => 'the screen is served by gui/templates/plans/planMilestones.html'));
    exit;
}

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
        'message' => 'the screen is served by gui/templates/plans/planMilestones.html'));
    exit;
}

$tplanId = (isset($_REQUEST['tplan_id']) && is_scalar($_REQUEST['tplan_id']))
    ? intval($_REQUEST['tplan_id']) : 0;
$tprojectId = (isset($_REQUEST['tproject_id']) && is_scalar($_REQUEST['tproject_id']))
    ? intval($_REQUEST['tproject_id']) : 0;

$base = str_replace(array("\r", "\n"), '', strval($_SESSION['basehref'] ?? ''));
if (preg_match('~^https?://[^/]+(/.+)$~i', $base, $m)) {
    $base = rtrim($m[1], '/') . '/';
}
if ($base === '' || $base[0] !== '/' || strpos($base, '//') === 0) {
    $base = '/';
}
$url = $base . 'gui/templates/plans/planMilestones.html';
$qs = array();
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
