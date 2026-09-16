<?php
/**
 * Copy & Execute Task Assignment BFF API
 * URL: /api/execassignmentcopy/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/plan/buildCopyExecTaskAssignment.php (TestLink 1.9.20 behavior):
 * copy all TESTER execution assignments from a source build onto an existing
 * target build. The target build's current tester assignments are removed
 * first (legacy doAction=copy: delete_by_build_id + copy_assignments), then
 * the source build's assignments are duplicated onto the target.
 *
 * Rights (same as legacy screen): testplan_planning on the test project that
 * owns the target build, checked on every route (pageAccessCheck rightsAnd).
 *
 * Legacy defects fixed here (upgraded schema):
 *   - Builds are scoped to the Test Project (#503/#834, no builds.testplan_id):
 *     the legacy screen called get_builds_for_html_options($tplan_id=0), which
 *     resolves testproject_id = 0 and therefore ALWAYS returned an empty source
 *     list ("no_builds_available_for_tester_copy"). Sources are now resolved by
 *     the target build's testproject_id.
 *   - POST re-checks rights and validates that the source build belongs to the
 *     same test project before the destructive delete+copy. The legacy code
 *     trusted build_id/source_build_id blindly.
 *
 * Routes:
 *   GET  /init?build_id=<target>
 *   POST /copy  {build_id, source_build_id}
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
$path = preg_replace('#^/api/execassignmentcopy(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function getBody() {
    static $body = null;
    if ($body === null) {
        $j = json_decode(file_get_contents('php://input'), true);
        $body = is_array($j) ? $j : [];
    }
    return $body;
}
function getParam($key, $default = null) {
    if (isset($_GET[$key])) { return $_GET[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    $body = getBody();
    if (isset($body[$key])) { return $body[$key]; }
    return $default;
}

/** Legacy right enforced on every route (buildCopyExecTaskAssignment.php). */
function canManage(&$user, &$db, $tprojectId) {
    return (bool)$user->hasRight($db, 'testplan_planning', $tprojectId);
}

function deny() {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'Insufficient rights']);
}

/** Resolve the target build + its owning test project (project-scoped). */
function resolveTargetBuild(&$db, $buildId) {
    $buildMgr = new build($db);
    $b = $buildMgr->get_by_id($buildId);
    if (!$b) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Invalid Build ID']);
    }
    $tprojectId = intval($b['testproject_id'] ?? 0);
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build has no owning test project']);
    }
    return ['build' => $b, 'tproject_id' => $tprojectId];
}

/**
 * Candidate source builds for the target: same test project, active + open,
 * target excluded, newest first, each annotated with its tester-assignment
 * count (legacy getBuildDomainForGUI ordering / labeling).
 */
function listSourceBuilds(&$db, $tprojectId, $targetBuildId, &$assignmentMgr) {
    $tables = tlObjectWithDB::getDBTables(['builds']);
    $sql = " SELECT id,name FROM {$tables['builds']} " .
           " WHERE testproject_id = " . intval($tprojectId) .
           " AND active = 1 AND is_open = 1 " .
           " AND id <> " . intval($targetBuildId) .
           " ORDER BY id DESC";
    $rows = $db->fetchRowsIntoMap($sql, 'id');
    $items = [];
    if (!is_null($rows)) {
        foreach ($rows as $bid => $r) {
            $items[] = [
                'id' => intval($bid),
                'name' => $r['name'],
                'assignments' => intval(
                    $assignmentMgr->get_count_of_assignments_for_build_id($bid)),
            ];
        }
    }
    return $items;
}

$buildMgr = new build($db);
$tplanMgr = new testplan($db);
$assignmentMgr = $tplanMgr->assignment_mgr;

/* ------------------------------------------------------------------ */
/* Routes                                                              */
/* ------------------------------------------------------------------ */

// GET /init?build_id=<target> -> target + source candidates
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'init') {
    $buildId = intval(getParam('build_id', 0));
    if ($buildId <= 0) {
        out(['status' => 'error', 'message' => 'build_id is required'], 400);
    }
    $target = resolveTargetBuild($db, $buildId);
    if (!canManage($user, $db, $target['tproject_id'])) {
        deny();
    }

    $tpMgr = new testproject($db);
    $tpInfo = $tpMgr->tree_manager->get_node_hierarchy_info(
        $target['tproject_id']);

    $sources = listSourceBuilds($db, $target['tproject_id'], $buildId,
        $assignmentMgr);

    // legacy: default the source selector to the newest build
    $selected = count($sources) > 0 ? intval($sources[0]['id']) : 0;

    out(['status' => 'ok', 'data' => [
        'target' => [
            'id' => intval($buildId),
            'name' => $target['build']['name'],
            'is_open' => intval($target['build']['is_open'] ?? 0),
            'release_date' => (isset($target['build']['release_date'])
                && $target['build']['release_date'])
                ? substr($target['build']['release_date'], 0, 10) : '',
            'assignments' => intval(
                $assignmentMgr->get_count_of_assignments_for_build_id($buildId)),
        ],
        'project' => [
            'id' => intval($target['tproject_id']),
            'name' => is_null($tpInfo) ? '' : $tpInfo['name'],
        ],
        'sources' => $sources,
        'selected_source_id' => $selected,
        'can_copy' => count($sources) > 0,
    ]]);
}

// POST /copy -> delete target tester assignments, copy from source
if ($method === 'POST' && count($segments) === 1 && $segments[0] === 'copy') {
    $body = getBody();
    $buildId = intval($body['build_id'] ?? 0);
    $sourceId = intval($body['source_build_id'] ?? 0);
    if ($buildId <= 0 || $sourceId <= 0) {
        out(['status' => 'error',
             'message' => 'build_id and source_build_id are required'], 400);
    }
    if ($buildId === $sourceId) {
        out(['status' => 'error',
             'message' => 'Source and target build must differ'], 400);
    }

    $target = resolveTargetBuild($db, $buildId);
    if (!canManage($user, $db, $target['tproject_id'])) {
        deny();
    }

    // source must be an active/open build of the SAME project
    $src = $buildMgr->get_by_id($sourceId);
    if (!$src
        || intval($src['testproject_id'] ?? 0) !== $target['tproject_id']) {
        out(['status' => 'error',
             'message' => 'Source build does not belong to this test project'],
            400);
    }
    if (intval($src['active'] ?? 0) !== 1 || intval($src['is_open'] ?? 0) !== 1) {
        out(['status' => 'error',
             'message' => 'Source build is not an active/open build'], 400);
    }

    $available = intval(
        $assignmentMgr->get_count_of_assignments_for_build_id($sourceId));
    if ($available <= 0) {
        out(['status' => 'error',
             'message' => 'Source build has no tester assignments'], 400);
    }

    // legacy doAction=copy: drop old tester assignments, then duplicate
    $assignmentMgr->delete_by_build_id($buildId);
    $assignmentMgr->copy_assignments($sourceId, $buildId, intval($userId));

    out(['status' => 'ok', 'data' => [
        'target' => [
            'id' => intval($buildId),
            'assignments' => intval(
                $assignmentMgr->get_count_of_assignments_for_build_id($buildId)),
        ],
        'source' => [
            'id' => intval($sourceId),
            'assignments' => $available,
        ],
        'copied' => $available,
    ]]);
}

out(['status' => 'error', 'message' => 'Unknown route'], 404);
