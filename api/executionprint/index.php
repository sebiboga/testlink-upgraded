<?php
/**
 * Execution Print BFF API
 * URL: /api/executionprint/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/execute/execPrint.php (TestLink 1.9.20): the "Execution Print"
 * popup reached from execTest.html ("Print" on an execution row). It renders
 * a printable document for a single execution row: test-case definition
 * (summary, author, importance, preconditions, custom fields, keywords,
 * requirements), per-step execution results/notes, overall status, build,
 * tester and dates, plus the direct-linked public execution URL.
 *
 * The legacy file simply echoed `renderHTMLHeader()` + 
 * `renderExecutionForPrinting()`. Modernization keeps reusing the battle-tested
 * legacy render machinery (`lib/functions/print.inc.php`) over the network, so
 * the print body HTML is generated server-side and returned as JSON; the
 * standalone execPrint.html screen provides the Dashio shell, toolbar (Print /
 * Close) and print CSS, then invokes window.print().
 *
 * Security differences vs legacy (intentional hardening, Refs #844):
 *  - Legacy execPrint.php allowed unauthenticated access via testlinkInitPage()
 *    third arg (external-direct-link use case). The modern screen runs inside
 *    the authenticated app, so the BFF REQUIRES a session user.
 *  - Rights: testplan_execute on the owning test project (same right the
 *    execution flow itself needs, consistent with api/execute / api/executionedit).
 *  - Unknown / forged execution id -> 404 (no E_WARNING events).
 *
 * Routes:
 *   GET  ?action=print&id=N
 *        -> { status, exec_id, page_title, body_html, meta{...} }
 *        Access: authenticated + testplan_execute on owning test project.
 *   POST ?action=delete_attachment&id=N&deleteAttachmentID=M
 *        -> { status, deleted, exec_id }
 *        Access: authenticated + testplan_execute on owning test project.
 *        (POST only — a destructive action; same-origin guard applies.)
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../cfg/reports.cfg.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/print.inc.php');
if (!function_exists('deleteAttachment')) {
    require_once(__DIR__ . '/../../lib/functions/attachments.inc.php');
}

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data) { echo json_encode($data); exit; }

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'Not authenticated']);
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
}

$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

/**
 * Resolve an execution row (execution id, owning test plan + project, build).
 * Returns null when not found (no E_WARNING by probing existence first).
 */
function resolveExecution(&$db, $execId) {
    $t = tlObjectWithDB::getDBTables(['executions', 'testplans', 'builds']);
    $er = $db->get_recordset(
        "SELECT E.id AS exec_id, E.status, E.execution_ts, E.tester_id," .
        " E.tcversion_id, E.tcversion_number, E.testplan_id, E.build_id," .
        " E.platform_id, E.execution_duration, E.notes," .
        " B.name AS build_name" .
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
 * Resolve an execution and enforce testplan_execute on the owning test
 * project (the same gate as api/execute). Outputs 404/403 and exits on
 * failure; returns the resolved row on success.
 */
function resolveAndAuthorize(&$db, &$user, $execId) {
    if ($execId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing id']);
    }
    $row = resolveExecution($db, $execId);
    if (is_null($row)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Execution not found']);
    }
    $tprojectId = intval($row['tproject_id']);
    $tplanId = intval($row['testplan_id']);
    if ($tprojectId <= 0 || $tplanId <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Execution not found']);
    }
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    return $row;
}

if ($action === 'print') {
    $row = resolveAndAuthorize($db, $user, $id);
    $tprojectId = intval($row['tproject_id']);
    $tplanId = intval($row['testplan_id']);

    // Ensure basehref is available to the print renderer (it builds the
    // direct public link and any absolute asset URLs).
    setPaths();

    $bodyHtml = '';
    try {
        $bodyHtml = (string)renderExecutionForPrinting(
            $db, $_SESSION['basehref'], $id, $user);
    } catch (\Throwable $e) {
        http_response_code(500);
        out(['status' => 'error',
             'message' => 'Print generation failed']);
    }

    // Tester display name (may be absent / null).
    $testerName = '';
    if (intval($row['tester_id']) > 0) {
        $tw = tlUser::getByID($db, intval($row['tester_id']));
        if (!is_null($tw)) {
            $testerName = $tw->getDisplayName();
        }
    }

    // Execution custom fields are not ported here: renderExecutionForPrinting
    // emits execution notes + step exec status/notes via the TC render path
    // (passfail/notes/step_exec_* render options) which is the exact legacy
    // execPrint behavior — no extra fields exist at this level.

    out([
        'status' => 'ok',
        'exec_id' => $id,
        'page_title' => '',
        'body_html' => $bodyHtml,
        'meta' => [
            'status' => strval($row['status']),
            'execution_ts' => strval($row['execution_ts']),
            'build_name' => strval($row['build_name']),
            'tester' => $testerName,
            'tproject_id' => $tprojectId,
            'tplan_id' => $tplanId,
        ],
    ]);
}

if ($action === 'delete_attachment') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'Method not allowed']);
    }
    $row = resolveAndAuthorize($db, $user, $id);
    $deleteAttachmentID = isset($_GET['deleteAttachmentID'])
        ? intval($_GET['deleteAttachmentID']) : 0;
    if ($deleteAttachmentID <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing deleteAttachmentID']);
    }

    // deleteAttachment() ownership-checks the id against the session list of
    // this execution's attachments (populated by the print render), so a
    // forged id from another execution is rejected; it returns the info (or
    // null when not found / not authorized to delete). Mirrors legacy
    // execPrint's deleteAttachment() flow, but now on an explicit POST that
    // the same-origin CSS guard enforces.
    if (!deleteAttachment($db, $deleteAttachmentID)) {
        http_response_code(409);
        out(['status' => 'error', 'message' => 'Attachment not found']);
    }
    out([
        'status' => 'ok',
        'deleted' => true,
        'exec_id' => $id,
    ]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
