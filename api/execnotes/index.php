<?php
/**
 * api/execnotes — Execution Notes BFF (Refs #1551)
 *
 * Replaces the legacy standalone Execution Notes flow
 * (lib/execute/execNotes.php = edit, lib/execute/getExecNotes.php = view).
 *
 * Routes:
 *   GET  /api/execnotes/{exec_id}  -> execution + notes (readonly view)
 *   PUT  /api/execnotes/{exec_id}  -> update the free-form execution notes
 *
 * Session-based auth, JSON I/O. No Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

require_once(__DIR__ . '/../../lib/functions/exec.inc.php');

header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/execnotes(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) {
    echo json_encode($data);
    exit;
}
function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function execNotFound() {
    http_response_code(404);
    out(['status' => 'error', 'message' => 'Execution not found']);
}

// GET /api/execnotes/{exec_id}
if ($method === 'GET' && count($segments) === 1 && ctype_digit($segments[0])) {
    $execId = intval($segments[0]);
    $rs = get_execution($db, $execId);
    if (!$rs || count($rs) === 0) {
        execNotFound();
    }
    $audit = get_execution($db, $execId, ['output' => 'audit']);
    $auditRow = ($audit && count($audit) > 0) ? $audit[0] : [];
    $row = $rs[0];

    out([
        'status' => 'ok',
        'execution' => [
            'id' => intval($row['id']),
            'tcversion_id' => intval($row['tcversion_id']),
            'testplan_id' => intval($row['testplan_id']),
            'build_id' => intval($row['build_id']),
            'notes' => $row['notes'] ?? '',
            'execution_ts' => $row['execution_ts'] ?? '',
            'status_ss' => $row['status_ss'] ?? '',
            'build_name' => $auditRow['build_name'] ?? '',
            'platform_name' => $auditRow['platform_name'] ?? '',
            'testplan_name' => $auditRow['testplan_name'] ?? '',
            'testcase_name' => $auditRow['testcase_name'] ?? '',
            'testproject_name' => $auditRow['testproject_name'] ?? '',
        ],
    ]);
}

// PUT /api/execnotes/{exec_id}
if ($method === 'PUT' && count($segments) === 1 && ctype_digit($segments[0])) {
    $execId = intval($segments[0]);
    $rs = get_execution($db, $execId);
    if (!$rs || count($rs) === 0) {
        execNotFound();
    }

    $body = getBody();
    $notes = isset($body['notes']) ? trim((string)$body['notes']) : '';

    $tables = tlObjectWithDB::getDBTables('executions');
    $sql = "UPDATE {$tables['executions']} " .
           " SET notes='" . $db->prepare_string($notes) . "' " .
           " WHERE id=" . intval($execId);
    $db->exec_query($sql);

    out(['status' => 'ok', 'message' => 'Notes saved', 'id' => $execId, 'notes' => $notes]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unsupported request']);