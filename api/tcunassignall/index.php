<?php
/**
 * Remove all tester assignments from a Build - BFF API
 * URL: /api/tcunassignall/index.php
 * Plain PHP, no framework.
 *
 * Mirrors lib/plan/tc_exec_unassign_all.php (TestLink 1.9.20):
 *   - info     : execution-assignment count for one build plus whether a
 *                "remove all" action is available (legacy
 *                assignment_mgr::get_count_of_assignments_for_build_id()
 *                with the default testcase_execution type filter)
 *   - unassign : deletes every tester assignment of the build (legacy
 *                confirmed=yes round-trip -> assignment_mgr
 *                ::delete_by_build_id(build_id))
 *
 * Rights (same as the legacy controller): testplan_planning on the owning
 * test project (pageAccessCheck rightsAnd gate in tc_exec_unassign_all.php).
 * The build row is itself project-scoped (builds.testproject_id), so the
 * owning project is resolved build-first and only falls back to the session
 * test project when the build cannot be matched (404 in that case - legacy
 * would silently count nothing).
 *
 * Endpoints (JSON out):
 *   GET  ?action=info&build_id=<id>[&tproject_id=<pid>]
 *   POST ?action=unassign&build_id=<id>[&tproject_id=<pid>]
 *        (body or query args accepted; same-origin guard enforced)
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'User not found'));
    exit;
}

function out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function bffBody()
{
    static $body = null;
    if ($body === null) {
        $j = json_decode(file_get_contents('php://input'), true);
        $body = is_array($j) ? $j : array();
    }
    return $body;
}

/** Get a parameter from GET/POST query string or JSON body. */
function getParam($key, $default = null)
{
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    $body = bffBody();
    if (isset($body[$key])) {
        return $body[$key];
    }
    return $default;
}

/**
 * Resolve the owning test project of a build:
 *  explicit tproject_id arg > session testprojectID > build row.
 * Returns [projectId, buildInfo] or 0 on a build that cannot be resolved.
 */
function resolveBuildContext($db, $buildMgr)
{
    $tprojectId = intval(getParam('tproject_id', 0));
    $buildId = intval(getParam('build_id', 0));
    if ($buildId <= 0) {
        out(array('status' => 'error', 'message' => 'Invalid build id'), 400);
    }

    if ($tprojectId <= 0 && isset($_SESSION['testprojectID'])) {
        $tprojectId = intval($_SESSION['testprojectID']);
    }

    $opt = array();
    if ($tprojectId > 0) {
        $opt['tproject_id'] = $tprojectId;
    }
    $buildInfo = $buildMgr->get_by_id($buildId, $opt);
    if (!is_array($buildInfo) || !isset($buildInfo['name'])) {
        // retry unscoped so a valid build of another project is still
        // detected (deep link without project context); rights + 404 are
        // then resolved against the build's own project
        $buildInfo = $buildMgr->get_by_id($buildId);
        if (!is_array($buildInfo) || !isset($buildInfo['name'])) {
            out(array('status' => 'error', 'message' => 'Build not found'), 404);
        }
    }

    if ($tprojectId <= 0 && isset($buildInfo['testproject_id'])) {
        $tprojectId = intval($buildInfo['testproject_id']);
    }
    if ($tprojectId <= 0) {
        out(array('status' => 'error', 'message' => 'Cannot resolve the owning test project'), 400);
    }

    if (isset($buildInfo['testproject_id']) &&
        $tprojectId != intval($buildInfo['testproject_id'])) {
        out(array('status' => 'error', 'message' => 'Build does not belong to the given test project'), 404);
    }

    return array($tprojectId, $buildInfo);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

$buildMgr = new build($db);
$assignMgr = new assignment_mgr($db);

if ($action === 'info' && $method === 'GET') {
    list($tprojectId, $buildInfo) = resolveBuildContext($db, $buildMgr);

    // legacy gate: testplan_planning on the owning project
    if (!$user->hasRight($db, 'testplan_planning', $tprojectId)) {
        out(array('status' => 'error', 'message' => 'Insufficient rights'), 403);
    }

    $buildId = intval($buildInfo['id']);
    $count = intval($assignMgr->get_count_of_assignments_for_build_id($buildId));

    out(array(
        'status' => 'ok',
        'build_id' => $buildId,
        'build_name' => (string)$buildInfo['name'],
        'tproject_id' => $tprojectId,
        'count' => $count,
        'can_remove' => $count > 0,
    ));
}

if ($action === 'unassign' && $method === 'POST') {
    list($tprojectId, $buildInfo) = resolveBuildContext($db, $buildMgr);

    // legacy gate: testplan_planning on the owning project
    if (!$user->hasRight($db, 'testplan_planning', $tprojectId)) {
        out(array('status' => 'error', 'message' => 'Insufficient rights'), 403);
    }

    $buildId = intval($buildInfo['id']);
    $count = intval($assignMgr->get_count_of_assignments_for_build_id($buildId));
    if ($count > 0) {
        $assignMgr->delete_by_build_id($buildId);
    }

    out(array(
        'status' => 'ok',
        'build_id' => $buildId,
        'build_name' => (string)$buildInfo['name'],
        'tproject_id' => $tprojectId,
        'removed_count' => $count,
    ));
}

out(array('status' => 'error', 'message' => 'Unknown route'), 404);