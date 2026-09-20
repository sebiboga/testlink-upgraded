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

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
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

// Resolve an execution to its owning test plan + test project and check the
// legacy rights (parity with execNotes.php, which was opened from the
// rights-gated execSetResults flow; api/execute resolves the same grants):
//   view -> exec_edit_notes OR exec_ro_access OR testplan_execute
//   edit -> exec_edit_notes (the dedicated legacy right)
$execTplanTables = tlObjectWithDB::getDBTables(array('executions', 'testplans'));

function resolveExecCtx($execId, $tables) {
    global $db;
    $rs = get_execution($db, $execId);
    if (!$rs || count($rs) === 0) {
        execNotFound();
    }
    $tplanId = intval($rs[0]['testplan_id']);
    $tpRs = $db->get_recordset(
        "SELECT testproject_id FROM {$tables['testplans']} WHERE id=" . intval($tplanId));
    if (!$tpRs || count($tpRs) === 0) {
        execNotFound();
    }
    return array('row' => $rs[0], 'testplan_id' => $tplanId,
                 'testproject_id' => intval($tpRs[0]['testproject_id']));
}

function denyForbidden() {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'You do not have rights on this execution']);
}

function getExecNotes($execId) {
    global $user, $db, $execTplanTables;
    $ctx = resolveExecCtx($execId, $execTplanTables);
    $viewGrant = $user->hasRight($db, 'exec_edit_notes', $ctx['testproject_id'], $ctx['testplan_id'])
        || $user->hasRight($db, 'exec_ro_access', $ctx['testproject_id'], $ctx['testplan_id'])
        || $user->hasRight($db, 'testplan_execute', $ctx['testproject_id'], $ctx['testplan_id']);
    if (!$viewGrant) {
        denyForbidden();
    }
    $audit = get_execution($db, $execId, ['output' => 'audit']);
    $auditRow = ($audit && count($audit) > 0) ? $audit[0] : [];
    $row = $ctx['row'];

    out([
        'status' => 'ok',
        'execution' => [
            'id' => intval($row['id']),
            'tcversion_id' => intval($row['tcversion_id']),
            'testplan_id' => intval($row['testplan_id']),
            'build_id' => intval($row['build_id']),
            'notes' => $row['notes'] ?? '',
            'execution_ts' => $row['execution_ts'] ?? '',
            'status_char' => $row['status'] ?? '',
            'build_name' => $auditRow['build_name'] ?? '',
            'platform_name' => $auditRow['platform_name'] ?? '',
            'testplan_name' => $auditRow['testplan_name'] ?? '',
            'testcase_name' => $auditRow['testcase_name'] ?? '',
            'testproject_name' => $auditRow['testproject_name'] ?? '',
        ],
    ]);
}

function putExecNotes($execId) {
    global $user, $db, $execTplanTables;
    $ctx = resolveExecCtx($execId, $execTplanTables);
    if (!$user->hasRight($db, 'exec_edit_notes', $ctx['testproject_id'], $ctx['testplan_id'])) {
        denyForbidden();
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

// GET /api/execnotes/{exec_id}
if ($method === 'GET' && count($segments) === 1 && ctype_digit($segments[0])) {
    getExecNotes(intval($segments[0]));
}

// PUT /api/execnotes/{exec_id}
if ($method === 'PUT' && count($segments) === 1 && ctype_digit($segments[0])) {
    putExecNotes(intval($segments[0]));
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unsupported request']);