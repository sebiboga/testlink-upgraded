<?php
/**
 * Requirements BFF API - Assign Requirements to Test Cases (assignReqs)
 * URL: /api/requirements/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/requirements/reqTcAssign.php (TestLink 1.9.20 behavior):
 *  - testcase mode : assign/unassign requirements to a single test case (latest version)
 *  - testsuite mode: bulk assign requirements to every test case under a test suite
 *
 * Rights: whole screen requires 'req_tcase_link_management' on the test project
 * (legacy pageAccessCheck with rightsAnd = ['req_tcase_link_management']).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('requirements.inc.php');

doSessionStart();

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
$path = preg_replace('#^/api/requirements(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

$tproject_mgr = new testproject($db);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function needTprojectId() {
    $id = intval($_GET['tproject_id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    return $id;
}

/**
 * Legacy checkRights(): rightsAnd = ['req_tcase_link_management']
 */
function needManageRight($user, $db, $tproject_id = null) {
    if ($tproject_id === null) {
        $tproject_id = needTprojectId();
    }
    if (!$user->hasRight($db, 'req_tcase_link_management', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    return $tproject_id;
}

/**
 * node_types description => id map (stable lookup instead of hardcoded ids)
 */
function nodeTypeMap($db) {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $rows = $db->get_recordset("SELECT id,description FROM node_types");
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $map[$r['description']] = intval($r['id']);
        }
    }
    return $map;
}

/**
 * Breadth-first collection of all descendant nodes of $rootId.
 * Returns [id => ['id','name','parent_id','node_type_id']]
 */
function subtreeNodes($db, $rootId) {
    $nodes = [$rootId => ['id' => intval($rootId), 'name' => null, 'parent_id' => 0, 'node_type_id' => 0]];
    $frontier = [intval($rootId)];
    while (count($frontier)) {
        $in = implode(',', array_map('intval', $frontier));
        $rows = $db->get_recordset(
            "SELECT id,name,parent_id,node_type_id FROM nodes_hierarchy WHERE parent_id IN ({$in})");
        $frontier = [];
        if (!is_null($rows)) {
            foreach ($rows as $r) {
                $nid = intval($r['id']);
                if (!isset($nodes[$nid])) {
                    $nodes[$nid] = [
                        'id' => $nid,
                        'name' => $r['name'],
                        'parent_id' => intval($r['parent_id']),
                        'node_type_id' => intval($r['node_type_id']),
                    ];
                    $frontier[] = $nid;
                }
            }
        }
    }
    // root name
    $ri = $db->get_recordset("SELECT name,parent_id,node_type_id FROM nodes_hierarchy WHERE id=" . intval($rootId));
    if (!is_null($ri) && isset($ri[0])) {
        $nodes[$rootId]['name'] = $ri[0]['name'];
        $nodes[$rootId]['parent_id'] = intval($ri[0]['parent_id']);
        $nodes[$rootId]['node_type_id'] = intval($ri[0]['node_type_id']);
    }
    return $nodes;
}

/**
 * Dotted path from project root to node id (excluding root itself)
 */
function pathOfNode($nodes, $id) {
    $parts = [];
    $cur = $id;
    while (isset($nodes[$cur]) && $nodes[$cur]['parent_id'] > 0 && isset($nodes[$nodes[$cur]['parent_id']])) {
        $parts[] = $nodes[$cur]['name'];
        $cur = $nodes[$cur]['parent_id'];
    }
    return implode(' / ', array_reverse($parts));
}

/**
 * Latest tcversion row for a test case id: ['tcversion_id','version','active','open','tc_external_id']
 */
function latestTCVersion($db, $tcaseId) {
    $tcaseId = intval($tcaseId);
    $rows = $db->get_recordset(
        " SELECT TCV.id AS tcversion_id, TCV.version, TCV.active, TCV.open, TCV.tc_external_id" .
        " FROM nodes_hierarchy NH JOIN tcversions TCV ON TCV.id = NH.id " .
        " WHERE NH.parent_id = {$tcaseId}" .
        " ORDER BY TCV.version DESC");
    if (is_null($rows) || count($rows) == 0) {
        return null;
    }
    return $rows[0];
}

/**
 * req_coverage links for a tcase + tcversion pair, keyed by req_id:
 * [req_id => ['link_id','link_status']]
 */
function coverageLinksForTCVersion($db, $tcaseId, $tcversionId) {
    $tcaseId = intval($tcaseId);
    $tcversionId = intval($tcversionId);
    $rows = $db->get_recordset(
        " SELECT RCOV.id AS link_id, RCOV.link_status, RQV.req_id " .
        " FROM {$db->tables['req_coverage']} RCOV " .
        " JOIN {$db->tables['req_versions']} RQV ON RQV.id = RCOV.req_version_id " .
        " WHERE RCOV.tcase_id = {$tcaseId} AND RCOV.tcversion_id = {$tcversionId}");
    $ret = [];
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $ret[intval($r['req_id'])] = [
                'link_id' => intval($r['link_id']),
                'link_status' => intval($r['link_status']),
            ];
        }
    }
    return $ret;
}

function reqRowToJSON($row) {
    return [
        'id' => intval($row['id']),
        'doc_id' => $row['req_doc_id'] ?? '',
        'title' => $row['title'] ?? '',
        'version' => isset($row['version']) ? intval($row['version']) : null,
    ];
}

// ---------------------------------------------------------------------------
// GET /context?tproject_id=N - project info + rights for the screen
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'context') {
    $tproject_id = needTprojectId();
    $info = $tproject_mgr->get_by_id($tproject_id);
    if (is_null($info) || !is_array($info) || !isset($info['id'])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    out([
        'status' => 'ok',
        'tproject_id' => intval($tproject_id),
        'tproject_name' => $info['name'],
        'rights' => [
            'req_tcase_link_management' =>
                (bool)$user->hasRight($db, 'req_tcase_link_management', $tproject_id),
        ],
    ]);
}

// ---------------------------------------------------------------------------
// GET /reqspecs?tproject_id=N - requirement spec combo (genComboReqSpec)
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'reqspecs') {
    $tproject_id = needTprojectId();
    needManageRight($user, $db, $tproject_id);

    $combo = $tproject_mgr->genComboReqSpec($tproject_id, 'dotted', "&nbsp;");
    $items = [];
    if (!is_null($combo)) {
        foreach ($combo as $rid => $rname) {
            $items[] = [
                'id' => intval($rid),
                'name' => trim(str_replace('&nbsp;', ' ', $rname)),
            ];
        }
    }
    out(['status' => 'ok', 'items' => $items]);
}

// ---------------------------------------------------------------------------
// GET /testcases?tproject_id=N - flat list of all test cases in the project
// (picker replacement for the legacy tree launcher listTestCases.php)
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'testcases') {
    $tproject_id = needTprojectId();
    needManageRight($user, $db, $tproject_id);

    $ntm = nodeTypeMap($db);
    $nodes = subtreeNodes($db, $tproject_id);

    $prefix = '';
    $pj = $tproject_mgr->get_by_id($tproject_id);
    if (!is_null($pj) && is_array($pj) && isset($pj['prefix'])) {
        $prefix = $pj['prefix'];
    }

    $items = [];
    foreach ($nodes as $nid => $n) {
        if ($n['node_type_id'] !== $ntm['testcase']) {
            continue;
        }
        $latest = latestTCVersion($db, $nid);
        $items[] = [
            'id' => $nid,
            'name' => $n['name'],
            'full_external_id' => ($prefix !== '' ? $prefix . '-' : '') . ($latest['tc_external_id'] ?? ''),
            'external_id' => strval($latest['tc_external_id'] ?? ''),
            'version' => $latest ? intval($latest['version']) : null,
            'path' => pathOfNode($nodes, $nid),
        ];
    }

    usort($items, function ($a, $b) {
        return strcmp($a['path'] . '~~' . $a['name'], $b['path'] . '~~' . $b['name']);
    });
    out(['status' => 'ok', 'items' => $items]);
}

// ---------------------------------------------------------------------------
// GET /testsuites?tproject_id=N - flat list of suites (bulk mode picker)
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'testsuites') {
    $tproject_id = needTprojectId();
    needManageRight($user, $db, $tproject_id);

    $ntm = nodeTypeMap($db);
    $nodes = subtreeNodes($db, $tproject_id);

    $items = [];
    foreach ($nodes as $nid => $n) {
        if ($n['node_type_id'] !== $ntm['testsuite'] || $nid == $tproject_id) {
            continue;
        }
        $items[] = [
            'id' => $nid,
            'name' => $n['name'],
            'path' => pathOfNode($nodes, $nid),
        ];
    }
    usort($items, function ($a, $b) { return strcmp($a['path'], $b['path']); });
    out(['status' => 'ok', 'items' => $items]);
}

// ---------------------------------------------------------------------------
// GET /tcase-info?id=N - title/version/executed of LATEST version
// (legacy processTestCase())
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'tcase-info') {
    $tcase_id = intval(getParam('id', 0));
    if ($tcase_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test case id']);
    }
    $nh = $db->get_recordset("SELECT name,parent_id FROM nodes_hierarchy WHERE id={$tcase_id}");
    if (is_null($nh) || !isset($nh[0])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }
    $latest = latestTCVersion($db, $tcase_id);
    if (is_null($latest)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case has no versions']);
    }
    $executed = $db->fetchFirstRowSingleColumn(
        "SELECT COUNT(*) FROM executions WHERE tcversion_id=" . intval($latest['tcversion_id']), 'COUNT(*)');
    out([
        'status' => 'ok',
        'item' => [
            'id' => $tcase_id,
            'name' => $nh[0]['name'],
            'tcversion_id' => intval($latest['tcversion_id']),
            'version' => intval($latest['version']),
            'full_external_id' => '',
            'executed' => intval($executed) > 0,
        ],
    ]);
}

// ---------------------------------------------------------------------------
// GET /reqs?req_spec_id=N&tcase_id=X(optional)
// legacy processTestCase(): get_requirements() on spec +
// getReqsOnSpecForLatestTCV() assigned + array_diff_byId unassigned
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'reqs') {
    $spec_id = intval(getParam('req_spec_id', 0));
    if ($spec_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid requirement spec id']);
    }

    // spec -> project for the rights check
    $nh = $db->get_recordset("SELECT parent_id FROM nodes_hierarchy WHERE id={$spec_id}");
    if (is_null($nh) || !isset($nh[0])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement spec not found']);
    }
    // walk up to the project node type
    $projId = 0;
    $cursor = intval($nh[0]['parent_id']);
    $guard = 0;
    while ($cursor > 0 && $guard++ < 100) {
        $row = $db->get_recordset("SELECT id,parent_id,node_type_id FROM nodes_hierarchy WHERE id={$cursor}");
        if (is_null($row) || !isset($row[0])) { break; }
        if (intval($row[0]['node_type_id']) === nodeTypeMap($db)['testproject']) {
            $projId = $cursor;
            break;
        }
        $cursor = intval($row[0]['parent_id']);
    }
    needManageRight($user, $db, $projId);

    $req_spec_mgr = new requirement_spec_mgr($db);
    $all = $req_spec_mgr->get_requirements($spec_id);
    $allOut = [];
    if (!is_null($all)) {
        foreach ($all as $r) { $allOut[] = reqRowToJSON($r); }
    }

    $assignedOut = [];
    $unassignedOut = $allOut;

    $tcase_id = intval(getParam('tcase_id', 0));
    if ($tcase_id > 0) {
        $latest = latestTCVersion($db, $tcase_id);
        if (!is_null($latest)) {
            $fx = ['link_status' => [LINK_TC_REQ_OPEN, LINK_TC_REQ_CLOSED_BY_EXEC]];
            $assigned = $req_spec_mgr->getReqsOnSpecForLatestTCV($spec_id, $tcase_id, null, $fx);

            $links = coverageLinksForTCVersion($db, $tcase_id, $latest['tcversion_id']);
            $assignedIds = [];
            if (!is_null($assigned)) {
                foreach ($assigned as $r) {
                    $j = reqRowToJSON($r);
                    $j['link_id'] = $links[intval($r['id'])]['link_id'] ?? null;
                    $j['link_status'] = $links[intval($r['id'])]['link_status'] ?? null;
                    $assignedIds[] = intval($j['id']);
                    $assignedOut[] = $j;
                }
            }
            // legacy array_diff_byId()
            $unassignedOut = array_values(array_filter($allOut,
                function ($r) use ($assignedIds) { return !in_array(intval($r['id']), $assignedIds); }));
        }
    }

    usort($assignedOut, function ($a, $b) { return strcmp($a['doc_id'], $b['doc_id']); });
    out([
        'status' => 'ok',
        'all' => $allOut,
        'assigned' => $assignedOut,
        'unassigned' => $unassignedOut,
    ]);
}

// ---------------------------------------------------------------------------
// POST /assign {tcase_id, req_ids[]} - legacy doAction=assign / assign_to_tcase()
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1 && $segments[0] === 'assign') {
    $body = getBody();
    $tcase_id = intval($body['tcase_id'] ?? 0);
    $reqIds = array_map('intval', (array)($body['req_ids'] ?? []));
    if ($tcase_id <= 0 || count($reqIds) === 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Nothing selected']);
    }

    // rights via the testcase's project
    $nh = $db->get_recordset("SELECT parent_id FROM nodes_hierarchy WHERE id={$tcase_id}");
    if (is_null($nh) || !isset($nh[0])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }
    $tsMgr = new testsuite($db);
    $tcaseMgr = new testcase($db);
    $tcase = $tcaseMgr->get_by_id($tcase_id);
    if (is_null($tcase) || count($tcase) == 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }
    $first = current($tcase);
    $tpid = intval($first['testproject_id'] ?? 0);
    if ($tpid <= 0) {
        // walk up through parents
        $cursor = intval($nh[0]['parent_id']);
        $guard = 0;
        $ntm = nodeTypeMap($db);
        while ($cursor > 0 && $guard++ < 200) {
            $row = $db->get_recordset("SELECT id,parent_id,node_type_id FROM nodes_hierarchy WHERE id={$cursor}");
            if (is_null($row) || !isset($row[0])) { break; }
            if (intval($row[0]['node_type_id']) === $ntm['testproject']) { $tpid = $cursor; break; }
            $cursor = intval($row[0]['parent_id']);
        }
    }
    needManageRight($user, $db, $tpid);

    $req_mgr = new requirement_mgr($db);
    $failed = [];
    foreach ($reqIds as $rid) {
        $res = $req_mgr->assign_to_tcase($rid, $tcase_id, $userId);
        if (!$res) {
            $failed[] = $rid;
        }
    }
    out(['status' => 'ok', 'failed' => $failed, 'assigned_qty' => count($reqIds) - count($failed)]);
}

// ---------------------------------------------------------------------------
// POST /unassign {link_ids[]} - legacy doAction=unassign /
// delReqVersionTCVersionLinkByID()
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1 && $segments[0] === 'unassign') {
    $body = getBody();
    $linkIds = array_map('intval', (array)($body['link_ids'] ?? []));
    if (count($linkIds) === 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Nothing selected']);
    }

    // rights: resolve project of first link's test case
    $inLinks = implode(',', $linkIds);
    $row = $db->get_recordset(
        " SELECT DISTINCT NHTC.id AS tcase_id FROM {$db->tables['req_coverage']} RCOV" .
        " JOIN nodes_hierarchy NHTC ON NHTC.id = RCOV.tcase_id" .
        " WHERE RCOV.id IN ({$inLinks})");
    if (is_null($row) || count($row) === 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Link(s) not found']);
    }
    $tcaseMgr = new testcase($db);
    $tcase = $tcaseMgr->get_by_id(intval($row[0]['tcase_id']));
    $tpid = 0;
    if (!is_null($tcase) && count($tcase) > 0) {
        $first = current($tcase);
        $tpid = intval($first['testproject_id'] ?? 0);
    }
    needManageRight($user, $db, $tpid);

    $req_mgr = new requirement_mgr($db);
    $failed = [];
    foreach ($linkIds as $lid) {
        $res = $req_mgr->delReqVersionTCVersionLinkByID($lid);
        if (!$res) {
            $failed[] = $lid;
        }
    }
    out(['status' => 'ok', 'failed' => $failed, 'unassigned_qty' => count($linkIds) - count($failed)]);
}

// ---------------------------------------------------------------------------
// GET /suite-tcases?tsuite_id=N - deep test case set of a suite
// (legacy getTargetTestCases()/get_testcases_deep(), used for the bulk warning)
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'suite-tcases') {
    $tsuite_id = intval(getParam('tsuite_id', 0));
    if ($tsuite_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test suite id']);
    }
    $tsMgr = new testsuite($db);
    $ids = $tsMgr->get_testcases_deep($tsuite_id, 'only_id');
    $out2 = [];
    if (!is_null($ids) && count($ids)) {
        $in = implode(',', array_map('intval', $ids));
        $rows = $db->get_recordset("SELECT id,name FROM nodes_hierarchy WHERE id IN ({$in})");
        if (!is_null($rows)) {
            foreach ($rows as $r) {
                $out2[] = ['id' => intval($r['id']), 'name' => $r['name']];
            }
        }
        usort($out2, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    }
    out(['status' => 'ok', 'qty' => count($out2), 'items' => $out2]);
}

// ---------------------------------------------------------------------------
// POST /bulkassign {tsuite_id, req_ids[], tcase_ids[](optional)}
// legacy doAction=bulkassign / bulkAssignLatestREQVTCV()
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1 && $segments[0] === 'bulkassign') {
    $body = getBody();
    $tsuite_id = intval($body['tsuite_id'] ?? 0);
    $reqIds = array_map('intval', (array)($body['req_ids'] ?? []));
    if ($tsuite_id <= 0 || count($reqIds) === 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Nothing selected']);
    }

    $nh = $db->get_recordset("SELECT parent_id FROM nodes_hierarchy WHERE id={$tsuite_id}");
    if (is_null($nh) || !isset($nh[0])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test suite not found']);
    }
    // resolve owning project walking up
    $ntm = nodeTypeMap($db);
    $tpid = 0;
    $cursor = intval($nh[0]['parent_id']);
    $guard = 0;
    while ($cursor > 0 && $guard++ < 200) {
        $row = $db->get_recordset("SELECT id,parent_id,node_type_id FROM nodes_hierarchy WHERE id={$cursor}");
        if (is_null($row) || !isset($row[0])) { break; }
        if (intval($row[0]['node_type_id']) === $ntm['testproject']) { $tpid = $cursor; break; }
        $cursor = intval($row[0]['parent_id']);
    }
    needManageRight($user, $db, $tpid);

    $tsMgr = new testsuite($db);
    $tcaseSet = $tsMgr->get_testcases_deep($tsuite_id, 'only_id');
    if (is_null($tcaseSet)) {
        $tcaseSet = [];
    }
    // optional explicit subset (legacy intersects with the tree filter selection)
    $explicit = array_map('intval', (array)($body['tcase_ids'] ?? []));
    if (count($explicit) > 0) {
        $tcaseSet = array_intersect((array)$tcaseSet, $explicit);
    }

    if (!is_null($tcaseSet) && count($tcaseSet)) {
        $req_mgr = new requirement_mgr($db);
        $counter = $req_mgr->bulkAssignLatestREQVTCV($reqIds, $tcaseSet, $userId);
        out(['status' => 'ok', 'assigned_qty' => intval($counter), 'tcase_qty' => count($tcaseSet)]);
    }
    out(['status' => 'ok', 'assigned_qty' => 0, 'tcase_qty' => 0]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown endpoint']);
