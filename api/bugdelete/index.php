<?php
/**
 * api/bugdelete — Bug Delete (unlink) popup BFF (Refs #1559)
 *
 * Replaces the last standalone lib/execute/* controller without a dedicated
 * modern twin: the bug-unlink popup lib/execute/bugDelete.php (+ legacy
 * dashio bugDelete.tpl). Legacy parity:
 *   - opening the popup with exec_id + bug_id already set deletes the link
 *     immediately (GET init with bug_id -> auto delete), mirrors the legacy
 *     "delete on load" behavior used by the old parent window
 *     (testlink_library.js deleteBug()).
 *   - the delete uses write_execution_bug(..., just_delete=true) and writes
 *     the exact legacy AUDIT events
 *     audit_executionbug_deleted[_no_platform].
 * New (superset) affordance: opening with exec_id only lists the linked bugs
 * of the execution so the user picks which one to unlink.
 *
 * Routes:
 *   GET  ?action=init&exec_id=N[&tcstep_id=N][&bug_id=X]
 *        -> execution context + linked-bug list (auto-deletes when bug_id
 *           is present). 401 anon, 403 wrong rights, 404 unknown exec.
 *   POST ?action=delete {exec_id,tcstep_id,bug_id}
 *        -> unlink the bug. Same guards + 400 missing params.
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

function out($data) {
    echo json_encode($data);
    exit;
}

function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function bugExecNotFound() {
    http_response_code(404);
    out(['status' => 'error', 'message' => 'Execution not found']);
}

function bugDenyForbidden() {
    http_response_code(403);
    out(['status' => 'error',
         'message' => 'You do not have rights to delete bugs on this execution']);
}

// Resolve the execution to its owning test plan + test project (like
// api/execnotes) and check the legacy bugDelete.php right: testplan_execute
// (legacy checkRights used hasRightOnProj() against the session context;
// here we probe the execution's OWNING project — a superset that keeps the
// popup safe when opened against a foreign/other-project execution).
function resolveBugExecCtx($execId) {
    global $db;
    if (!ctype_digit(strval($execId)) || intval($execId) <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid execution id']);
    }
    $rs = get_execution($db, $execId);
    if (!$rs || count($rs) === 0) {
        bugExecNotFound();
    }
    $row = $rs[0];
    $tplanId = intval($row['testplan_id'] ?? 0);
    if ($tplanId <= 0) {
        bugExecNotFound();
    }
    $tables = tlObjectWithDB::getDBTables(array('testplans', 'testprojects'));
    $tpRs = $db->get_recordset(
        "SELECT tp.testproject_id FROM {$tables['testplans']} tp " .
        "WHERE tp.id=" . $tplanId);
    if (!$tpRs || count($tpRs) === 0) {
        bugExecNotFound();
    }
    return array('row' => $row,
                 'testplan_id' => $tplanId,
                 'testproject_id' => intval($tpRs[0]['testproject_id']));
}

// Linked-bug list of an execution. Reuses the legacy BTS-aware listing
// (get_bugs_for_exec) when the project has an issue tracker, with the exact
// same raw-execution_bugs fallback used by api/execute for no-BTS projects.
function listExecBugs($execId, $tprojectId) {
    global $db;
    $bugs = [];
    $useITS = false;
    $its = null;

    $tprojectMgr = new testproject($db);
    $info = $tprojectMgr->get_by_id($tprojectId);
    $useITS = !empty($info['issue_tracker_enabled'])
        && config_get('exec_cfg')->features->issue_tracker->enabled;

    if ($useITS) {
        try {
            $itMgr = new tlIssueTracker($db);
            $its = $itMgr->getInterfaceObject($tprojectId);
        } catch (Exception $e) {
            $its = null;
        }
    }

    if ($useITS && !is_null($its)) {
        try {
            $dummy = get_bugs_for_exec($db, $its, $execId);
            foreach ($dummy as $bugId => $bi) {
                $bugs[] = [
                    'id' => strval($bugId),
                    'link_to_bts' => strval($bi['link_to_bts'] ?? ''),
                    'bug_url' => (string)$its->buildViewBugURL($bugId),
                    'tcstep_id' => intval($bi['tcstep_id'] ?? 0),
                    'step_number' => intval($bi['step_number'] ?? 0),
                ];
            }
        } catch (Exception $e) {
            // BTS not reachable — keep empty
        }
        return $bugs;
    }

    // Graceful fallback: raw bug strings when no BTS is configured
    try {
        $tables = tlObjectWithDB::getDBTables(array('execution_bugs', 'tcsteps'));
        $brs = $db->get_recordset(
            "SELECT eb.bug_id, eb.tcstep_id, s.step_number " .
            "FROM {$tables['execution_bugs']} eb " .
            "LEFT JOIN {$tables['tcsteps']} s ON s.id = eb.tcstep_id " .
            "WHERE eb.execution_id = {$execId} " .
            "ORDER BY s.step_number ASC, eb.bug_id ASC");
        if (!is_null($brs)) {
            foreach ($brs as $br) {
                $bugs[] = [
                    'id' => strval($br['bug_id']),
                    'link_to_bts' => '',
                    'tcstep_id' => intval($br['tcstep_id'] ?? 0),
                    'step_number' => intval($br['step_number'] ?? 0),
                ];
            }
        }
    } catch (Exception $e) {
        // ignore
    }
    return $bugs;
}

// Build the popup context block (audit JOINs mirror the legacy bugDelete.php
// audit message sourcing).
function bugExecContext($execId) {
    global $db;
    $ctx = resolveBugExecCtx($execId);
    $ainfo = get_execution($db, $execId, ['output' => 'audit']);
    $a = ($ainfo && count($ainfo) > 0) ? $ainfo[0] : [];
    $row = $ctx['row'];
    return [
        'exec_id' => intval($row['id']),
        'tcversion_id' => intval($row['tcversion_id']),
        'testplan_id' => $ctx['testplan_id'],
        'testproject_id' => $ctx['testproject_id'],
        'execution_ts' => strval($row['execution_ts'] ?? ''),
        'status_char' => strval($row['status'] ?? ''),
        'build_name' => strval($a['build_name'] ?? ''),
        'platform_name' => strval($a['platform_name'] ?? ''),
        'testplan_name' => strval($a['testplan_name'] ?? ''),
        'testcase_name' => strval($a['testcase_name'] ?? ''),
        'testproject_name' => strval($a['testproject_name'] ?? ''),
    ];
}

// The exact legacy unlink + audit log (bugDelete.php:22-43). Returns the
// localized success message (bugdeleting_was_ok).
function unlinkExecBug($execId, $bugId, $tcstepId) {
    global $db;
    if (!write_execution_bug($db, $execId, $bugId, $tcstepId, true)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Bug unlink failed']);
    }
    $ainfo = get_execution($db, $execId, ['output' => 'audit']);
    $a = ($ainfo && count($ainfo) > 0) ? $ainfo[0] : [];
    if (($a['platform_name'] ?? '') === '') {
        $auditMsg = TLS('audit_executionbug_deleted_no_platform', $bugId,
                        $execId,
                        strval($a['testcase_name'] ?? ''),
                        strval($a['testproject_name'] ?? ''),
                        strval($a['testplan_name'] ?? ''),
                        strval($a['build_name'] ?? ''));
    } else {
        $auditMsg = TLS('audit_executionbug_deleted', $bugId, $execId,
                        strval($a['testcase_name'] ?? ''),
                        strval($a['testproject_name'] ?? ''),
                        strval($a['testplan_name'] ?? ''),
                        strval($a['platform_name'] ?? ''),
                        strval($a['build_name'] ?? ''));
    }
    logAuditEvent($auditMsg, 'DELETE', $execId, 'executions');
    return lang_get('bugdeleting_was_ok');
}

$action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])
        ? strtolower(trim((string) $_REQUEST['action'])) : '';
$method = $_SERVER['REQUEST_METHOD'];

// GET ?action=init
if ($action === 'init') {
    if ($method !== 'GET') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'GET required']);
    }
    $execId = intval($_REQUEST['exec_id'] ?? 0);
    $ctx = bugExecContext($execId);
    if (!$user->hasRight($db, 'testplan_execute',
                          $ctx['testproject_id'], $ctx['testplan_id'])) {
        bugDenyForbidden();
    }

    $bugId = isset($_REQUEST['bug_id']) && is_scalar($_REQUEST['bug_id'])
        ? trim((string) $_REQUEST['bug_id']) : '';
    $tcstepId = intval($_REQUEST['tcstep_id'] ?? 0);

    // Legacy parity: the old parent (testlink_library.js deleteBug) always
    // opens the popup with exec_id + bug_id — bugDelete.php deletes on load.
    if ($bugId !== '') {
        $msg = unlinkExecBug($execId, $bugId, $tcstepId);
        $still = listExecBugs($execId, $ctx['testproject_id']);
        out([
            'status' => 'ok',
            'action' => 'auto_delete',
            'message' => $msg,
            'context' => $ctx,
            'deleted_bug' => $bugId,
            'bugs' => $still,
        ]);
    }

    // Standalone affordance: show the linked bugs so the user chooses.
    $bugs = listExecBugs($execId, $ctx['testproject_id']);
    out([
        'status' => 'ok',
        'action' => 'list',
        'context' => $ctx,
        'bugs' => $bugs,
    ]);
}

// POST ?action=delete
if ($action === 'delete') {
    if ($method !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = getBody();
    if (!is_array($payload)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid JSON body']);
    }
    $execId = intval($payload['exec_id'] ?? 0);
    $ctx = bugExecContext($execId);
    if (!$user->hasRight($db, 'testplan_execute',
                          $ctx['testproject_id'], $ctx['testplan_id'])) {
        bugDenyForbidden();
    }
    $bugId = isset($payload['bug_id']) && is_scalar($payload['bug_id'])
        ? trim((string) $payload['bug_id']) : '';
    if ($bugId === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing bug id']);
    }
    $tcstepId = intval($payload['tcstep_id'] ?? 0);

    // 404 when the bug is not actually linked to this execution — reporting
    // success for a no-op delete (e.g. a second concurrent submission) must
    // never happen.
    $tables = tlObjectWithDB::getDBTables('execution_bugs');
    $chk = $db->get_recordset(
        "SELECT execution_id FROM {$tables['execution_bugs']} " .
        "WHERE execution_id={$execId} AND tcstep_id={$tcstepId} " .
        "AND bug_id='" . $db->prepare_string($bugId) . "'");
    if (is_null($chk) || count($chk) === 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Bug not linked to this execution']);
    }

    $msg = unlinkExecBug($execId, $bugId, $tcstepId);
    $bugs = listExecBugs($execId, $ctx['testproject_id']);
    out([
        'status' => 'ok',
        'action' => 'delete',
        'message' => $msg,
        'context' => $ctx,
        'deleted_bug' => $bugId,
        'bugs' => $bugs,
    ]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unknown or missing action']);