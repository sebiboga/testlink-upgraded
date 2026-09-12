<?php
/**
 * Create Test Cases from Requirements BFF API
 * URL: /api/reqcreatetestcases/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modernized screen gui/templates/requirements/reqCreateTestCases.html
 * (Refs #1483). Ports lib/requirements/reqEdit.php doAction=createTestCases /
 * doCreateTestCases + gui/templates/dashio/requirements/reqCreateTestCases.tpl:
 * lists every requirement of a requirement specification with per-row
 * test-case count, current coverage and coverage %, then creates the requested
 * number of test cases per selected requirement under the auto-generated
 * test suite and links requirement versions to the new test-case versions.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&spec_id=N&tproject_id=N
 *                       -> req spec header + requirement rows (id, doc id,
 *                          title, status/type + labels, expected_coverage,
 *                          coverage, coverage_percent, needed) + domains
 *                          (status/type label maps) + req_cfg flags + rights
 *   POST ?action=create {spec_id,tproject_id,req_ids:[...],
 *                        testcase_count:{reqId:n}}
 *                       -> creates n test cases per selected requirement via
 *                          requirement_mgr::create_tc_from_requirement();
 *                          returns the legacy result message array
 *
 * Rights model (legacy reqEdit.php checkRights parity):
 *   both 'mgt_view_req' AND 'mgt_modify_req' on the OWNING test project are
 *   required for every route (401 anon / 400 no spec / 403 no right /
 *   404 unknown spec or tproject/spec mismatch).
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirements.inc.php');
require_once(__DIR__ . '/../../lib/functions/requirement_spec_mgr.class.php');
require_once(__DIR__ . '/../../lib/functions/requirement_mgr.class.php');

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

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';
$BODY = json_decode(file_get_contents('php://input'), true) ?? [];

function ctx_out($data) { echo json_encode($data); exit; }

$tprojectMgr = new testproject($db);
$reqSpecMgr  = new requirement_spec_mgr($db);
$reqMgr      = new requirement_mgr($db);

/** can view (mgt_view_req AND mgt_modify_req, legacy rightsAnd parity). */
function ctxCanAccess($user, $db, $tproject_id) {
    return $user->hasRight($db, 'mgt_view_req', $tproject_id)
        && $user->hasRight($db, 'mgt_modify_req', $tproject_id);
}

/** Spec must exist and belong to the project; returns owning project id. */
function ctxOwnedSpecTproject($specId, $providedTproject) {
    global $db;
    if ($specId <= 0) {
        http_response_code(400);
        ctx_out(['status' => 'error', 'message' => 'Invalid requirement spec id']);
    }
    $rows = $db->get_recordset(
        'SELECT testproject_id FROM req_specs WHERE id = ' . intval($specId) . ' LIMIT 1');
    if (!$rows) {
        http_response_code(404);
        ctx_out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }
    $owner = intval($rows[0]['testproject_id']);
    if ($providedTproject > 0 && $providedTproject !== $owner) {
        http_response_code(400);
        ctx_out(['status' => 'error',
                 'message' => 'Test project does not own the requirement specification']);
    }
    return $owner;
}

/** Resolve the req spec + ctx via get_by_id with a bound project (closure). */
function ctxSpecById($specId) {
    global $reqSpecMgr;
    $spec = @$reqSpecMgr->get_by_id(intval($specId));
    if (!$spec || empty($spec['id'])) {
        http_response_code(404);
        ctx_out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }
    return $spec;
}

/** Requirement rows with legacy coverage math (reqCommands::createTestCases). */
function ctxRequirementRows($specId) {
    global $reqSpecMgr, $reqMgr;

    $allReqs = @$reqSpecMgr->get_requirements($specId);
    if (is_null($allReqs)) {
        return array();
    }

    $reqCfg = config_get('req_cfg');
    $rows = array();
    foreach ($allReqs as $key => $req) {
        $count = is_null($req['id']) ? 0 : count((array)$reqMgr->get_coverage($req['id']));
        $coveragePercent = 0;
        $denominator = (intval($req['expected_coverage']) * $count);
        if ($denominator != 0) {
            $coveragePercent = round(100 / $denominator, 2);
        }
        $needed = 0;
        if ($reqCfg->expected_coverage_management
            && intval($req['expected_coverage']) >= $count) {
            $needed = intval($req['expected_coverage']) - $count;
        }
        $rows[] = array(
            'id'                => intval($req['id']),
            'req_doc_id'        => (string)$req['req_doc_id'],
            'title'             => (string)$req['title'],
            'status'            => (string)$req['status'],
            'type'              => (string)$req['type'],
            'expected_coverage' => intval($req['expected_coverage']),
            'coverage'          => $count,
            'coverage_percent'  => $coveragePercent,
            'needed'            => $needed,
        );
    }
    return $rows;
}

if ($action === '') {
    http_response_code(400);
    ctx_out(['status' => 'error', 'message' => 'Missing action']);
}

if ($method === 'GET' && $action === 'init') {
    $specId = intval($_REQUEST['spec_id'] ?? 0);
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);

    $owner = ctxOwnedSpecTproject($specId, $tprojectId);
    if (!ctxCanAccess($user, $db, $owner)) {
        http_response_code(403);
        ctx_out(['status' => 'error', 'message' => 'No permission']);
    }

    $spec = ctxSpecById($specId);
    $reqCfg = config_get('req_cfg');

    $rows = ctxRequirementRows($spec['id']);

    $exam = count($rows) ? $rows[0] : null;
    $tprojectName = testproject::getName($db, $owner);

    ctx_out([
        'status'     => 'ok',
        'tproject_id'   => $owner,
        'tproject_name' => $tprojectName,
        'spec' => [
            'id'    => intval($spec['id']),
            'doc_id' => (string)($spec['doc_id'] ?? ''),
            'title' => (string)$spec['title'],
        ],
        'requirements' => $rows,
        'expected_coverage_management' =>
            isset($reqCfg->expected_coverage_management)
                ? (intval($reqCfg->expected_coverage_management) == 1 ? true : false)
                : false,
        'rights' => [
            'manage' => ctxCanAccess($user, $db, $owner),
        ],
    ]);
}

if ($method === 'POST' && $action === 'create') {
    $specId = intval($BODY['spec_id'] ?? ($_REQUEST['spec_id'] ?? 0));
    $tprojectId = intval($BODY['tproject_id'] ?? ($_REQUEST['tproject_id'] ?? 0));

    $owner = ctxOwnedSpecTproject($specId, $tprojectId);
    if (!ctxCanAccess($user, $db, $owner)) {
        http_response_code(403);
        ctx_out(['status' => 'error', 'message' => 'No permission']);
    }

    ctxSpecById($specId);

    $reqIds = isset($BODY['req_ids']) ? (array)$BODY['req_ids'] : [];
    $reqIds = array_values(array_filter(array_map('intval', $reqIds), fn($v) => $v > 0));
    if (empty($reqIds)) {
        http_response_code(400);
        ctx_out(['status' => 'error', 'message' => 'Select at least one requirement']);
    }

    // Verify every selected requirement actually belongs to this spec (forged
    // ids must not create orphans). Both forms legacy accepts: flat
    // testcase_count and old 'testcase_count:{rid:n}' serialized_count.
    $tcCount = isset($BODY['testcase_count']) && is_array($BODY['testcase_count'])
        ? $BODY['testcase_count'] : [];

    $memberRows = $db->get_recordset(
        'SELECT id FROM requirements WHERE srs_id = ' . intval($specId));
    $members = [];
    foreach (($memberRows ?: []) as $m) { $members[] = intval($m['id']); }

    $specMap = [];
    foreach ($reqIds as $rid) {
        if (!in_array($rid, $members, true)) {
            http_response_code(404);
            ctx_out(['status' => 'error', 'message' => 'Requirement does not exist in the specification']);
        }
        $n = isset($tcCount[(string)$rid]) ? intval($tcCount[(string)$rid]) : 1;
        $specMap[$rid] = max(0, $n);
    }

    $msg = (array)$reqMgr->create_tc_from_requirement(
        $reqIds, $specId, $userId, $owner, $specMap);

    ctx_out([
        'status'   => 'ok',
        'messages' => $msg,
        'count'    => count($msg),
    ]);
}

http_response_code(400);
ctx_out(['status' => 'error', 'message' => 'Unknown action']);