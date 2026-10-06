<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later. 
 *
 * LEGACY EXECUTION NOTES EDITOR - REDIRECT SHIM (Refs #1857)
 *
 * The screen itself is modernized: gui/templates/execute/execNotes.html
 * backed by api/execnotes/index.php.
 */
require_once('../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../../api/_guard.php');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Legacy testlinkInitPage() contract: no session, no data.
if (empty($_SESSION['userID']) || intval($_SESSION['userID']) <= 0) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'not_authenticated',
        'message' => 'Not authenticated',
    ));
    exit;
}

$execId = isset($_GET['exec_id']) ? trim((string)$_GET['exec_id']) : '';
if (isset($_REQUEST['exec_id']) && $execId === '') {
    $execId = trim((string)$_REQUEST['exec_id']);
}
$execIdInt = 0;
if ($execId !== '' && preg_match('/^[0-9]+$/', $execId)) {
    $execIdInt = intval($execId);
}

$isAjax = (strcasecmp(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')), 'XMLHttpRequest') === 0);

// Browser navigation: hand over to the modern screen
if (!$isAjax && ($method === 'GET' || $method === 'HEAD')) {
    $basehref = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
    $url = $basehref . 'gui/templates/execute/execNotes.html';
    if ($execIdInt > 0) {
        $url .= '?exec_id=' . $execIdInt;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

// For AJAX requests (legacy callers), forward to the BFF in-process to preserve session
if ($isAjax && $method === 'GET') {
    if ($execIdInt <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'status' => 'error',
            'code' => 'bad_param',
            'message' => 'Missing or invalid exec_id',
        ));
        exit;
    }
    $_SERVER['PATH_INFO'] = '/' . $execIdInt;
    $_GET['exec_id'] = $execIdInt;
    require __DIR__ . '/../../api/execnotes/index.php';
    exit;
}

if ($method === 'PUT' || $method === 'POST') {
    if ($execIdInt <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'status' => 'error',
            'code' => 'bad_param',
            'message' => 'Missing or invalid exec_id',
        ));
        exit;
    }
    $_SERVER['PATH_INFO'] = '/' . $execIdInt;
    $_GET['exec_id'] = $execIdInt;
    require __DIR__ . '/../../api/execnotes/index.php';
    exit;
}

header('Allow: GET, HEAD, PUT, POST');
http_response_code(405);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array(
    'status' => 'error',
    'code' => 'method_not_allowed',
    'message' => 'Method not allowed',
));
exit;
