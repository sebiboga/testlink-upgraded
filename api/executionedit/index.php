<?php
/**
 * Edit Execution BFF API (execution notes + custom fields popup)
 * URL: /api/executionedit/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/execute/editExecution.php (TestLink 1.9.20): the "Edit
 * Execution" popup reached from execTest.html / execHistory.html. It lets the
 * user edit the execution **notes** and the execution-level **custom fields**
 * for an existing execution row.
 *
 * Legacy write path (editExecution.php doUpdate):
 *   updateExecutionNotes($db, $exec_id, $notes);
 *   cfield_mgr->execution_values_to_db($request, $tcversion_id, $exec_id, $tplan_id);
 *
 * Legacy rights check (editExecution.php checkRights):
 *   testplan_execute AND exec_edit_notes  (both required)
 * Both are evaluated on the owning test project of the execution's test plan.
 *
 * Routes:
 *   GET  ?action=init&exec_id=N
 *        -> { status, exec_id, tcversion_id, tplan_id, tproject_id, notes,
 *             can_edit, build_is_open, exec_cfields_html, exec_cfields }
 *        Access: testplan_execute AND exec_edit_notes (403 otherwise).
 *   POST ?action=update  (JSON body: exec_id, notes, custom_field_* values)
 *        -> { status, updated, exec_id }
 *        Access: testplan_execute AND exec_edit_notes.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('exec.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

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

function out($data) { echo json_encode($data); exit; }

function getIntParam($key, $default = 0) {
    $v = $_GET[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

/**
 * Resolve an execution row (id, owning test plan + project, build open flag).
 * Returns null when not found. Mirrors the api/execute ?action=updateNotes
 * probe but also carries tcversion_id/tplan_id/project for CF rendering.
 */
function resolveExecution(&$db, $execId) {
    $t = tlObjectWithDB::getDBTables(['executions', 'testplans', 'builds']);
    $er = $db->get_recordset(
        "SELECT E.id AS exec_id, E.testplan_id, E.tcversion_id," .
        " E.build_id, B.is_open AS build_is_open" .
        " FROM {$t['executions']} E" .
        " LEFT JOIN {$t['builds']} B ON B.id = E.build_id" .
        " WHERE E.id = {$execId}");
    if (is_null($er) || count($er) == 0) {
        return null;
    }
    $row = $er[0];
    $tplan = $db->get_recordset(
        "SELECT testproject_id FROM {$t['testplans']} WHERE id = "
        . intval($row['testplan_id']));
    $row['tproject_id'] = (is_array($tplan) && count($tplan) > 0)
        ? intval($tplan[0]['testproject_id']) : 0;
    return $row;
}

/**
 * Enforce the legacy editExecution.php checkRights() gate: testplan_execute AND
 * exec_edit_notes, both evaluated on the owning test project.
 */
function hasEditExecutionRights(&$db, &$user, $tprojectId, $tplanId) {
    if ($tprojectId <= 0 || $tplanId <= 0) {
        return false;
    }
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        return false;
    }
    if (!$user->hasRight($db, 'exec_edit_notes', $tprojectId, $tplanId)) {
        return false;
    }
    return true;
}

$action = $_GET['action'] ?? '';

if ($action === 'init') {
    $execId = getIntParam('exec_id');
    if ($execId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing exec_id']);
    }

    $row = resolveExecution($db, $execId);
    if (is_null($row)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Execution not found']);
    }

    $tplanId = intval($row['testplan_id']);
    $tprojectId = intval($row['tproject_id']);
    if (!hasEditExecutionRights($db, $user, $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    $tcversionId = intval($row['tcversion_id']);
    $map = get_execution($db, $execId);
    $notes = (is_array($map) && isset($map[0]['notes']))
        ? strval($map[0]['notes']) : '';

    // execution-level custom field INPUTS (same legacy helper + contract as
    // editExecution.php): name pattern 'custom_field_<type>_<id>_<tcaseId>'
    // (4th arg '_cf', 5th arg exec_id, 6th tplan_id, 7th tproject_id).
    $execCFieldsHtml = '';
    $execCFields = [];
    try {
        $tcaseMgr = new testcase($db);
        $execCFieldsHtml = (string)$tcaseMgr->html_table_of_custom_field_inputs(
            $tcversionId, null, 'execution', '_cf', $execId, $tplanId, $tprojectId);
        $cfDefs = $tcaseMgr->get_linked_cfields_at_execution(
            $tcversionId, null, null, null, null, $tprojectId);
        if (is_array($cfDefs)) {
            foreach ($cfDefs as $cf) {
                $execCFields[] = [
                    'id' => intval($cf['id']),
                    'name' => 'custom_field_' . intval($cf['type']) . '_'
                              . intval($cf['id']) . '_' . $tcversionId,
                    'label' => strval($cf['label']),
                    'type' => intval($cf['type']),
                    'required' => intval($cf['required'] ?? 0),
                ];
            }
        }
    } catch (\Throwable $e) {
        $execCFieldsHtml = '';
        $execCFields = [];
    }

    out([
        'status' => 'ok',
        'exec_id' => $execId,
        'tcversion_id' => $tcversionId,
        'tplan_id' => $tplanId,
        'tproject_id' => $tprojectId,
        'notes' => $notes,
        'can_edit' => true,
        'build_is_open' => intval($row['build_is_open']) === 1,
        'exec_cfields_html' => $execCFieldsHtml,
        'exec_cfields' => $execCFields,
    ]);
}

if ($action === 'update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid JSON body']);
    }

    $execId = intval($payload['exec_id'] ?? 0);
    if ($execId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing exec_id']);
    }

    $row = resolveExecution($db, $execId);
    if (is_null($row)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Execution not found']);
    }

    $tplanId = intval($row['testplan_id']);
    $tprojectId = intval($row['tproject_id']);
    $tcversionId = intval($row['tcversion_id']);
    if (!hasEditExecutionRights($db, $user, $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    if (intval($row['build_is_open']) !== 1) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot edit execution of a closed build']);
    }

    // 1) update the notes (legacy updateExecutionNotes)
    $notes = strval($payload['notes'] ?? '');
    if (updateExecutionNotes($db, $execId, $notes) !== tl::OK) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Update notes failed']);
    }

    // 2) persist execution custom field values (legacy
    //    cfield_mgr->execution_values_to_db). Only accept names whose
    //    (type,id) belongs to a real execution-time custom field linked to
    //    this test case version, so a forged payload cannot write unrelated
    //    values. Populate $_REQUEST so execution_values_to_db() reads it.
    $cfPrefix = 'custom_field_';
    $allowedCf = [];
    try {
        $cfMgrMgr = new testcase($db);
        $cfDefs = $cfMgrMgr->get_linked_cfields_at_execution(
            $tcversionId, null, null, null, null, $tprojectId);
        if (is_array($cfDefs)) {
            foreach ($cfDefs as $cf) {
                $allowedCf[intval($cf['type']) . '_' . intval($cf['id'])] = 1;
            }
        }
    } catch (\Throwable $e) {
        $allowedCf = [];
    }
    $cfRequest = [];
    foreach ($payload as $pName => $pVal) {
        if (strncmp($pName, $cfPrefix, strlen($cfPrefix)) !== 0) { continue; }
        $parts = explode('_', $pName);
        if (count($parts) < 5) { continue; }
        $typeIdKey = $parts[2] . '_' . $parts[3];
        if (!isset($allowedCf[$typeIdKey])) { continue; }
        $cfRequest[$pName] = $pVal;
    }
    if (count($cfRequest) > 0) {
        foreach ($cfRequest as $k => $v) {
            $_REQUEST[$k] = $v;
        }
        $cfield_mgr = new cfield_mgr($db);
        $cfield_mgr->execution_values_to_db(
            $_REQUEST, $tcversionId, $execId, $tplanId);
    }

    out(['status' => 'ok', 'updated' => true, 'exec_id' => $execId]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
