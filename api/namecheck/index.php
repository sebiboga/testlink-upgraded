<?php
/**
 * Duplicate Node Name Check BFF API
 * URL: /api/namecheck/
 * Plain PHP, no framework. Refs #1724.
 *
 * Modern replacement for the two legacy 1.9.20 AJAX name-uniqueness backends:
 *
 *  1) lib/ajax/checkNodeDuplicateName.php  (the GENERIC node check that served
 *     the test suite / test case forms) - it had **no rights check at all**
 *     ("Importante NOTICE: no check on user rights is done"), no project
 *     scope and no same-origin proof, so any authenticated session could probe
 *     the existence of a node name under an arbitrary parent_id belonging to
 *     any test project.
 *  2) lib/ajax/checkDuplicateName.php       (the older test-case-only check) -
 *     gated on the GLOBAL mgt_view_tc right, so a user holding it on one test
 *     project could enumerate names inside every other test project.
 *
 * This endpoint re-implements the same semantics (tree::nodeNameExists(),
 * lib/functions/tree.class.php:1382) but:
 *   - requires a live session (401) and a valid session window
 *     (bffEnforceSession, 401 session_expired);
 *   - requires mgt_view_tc OR mgt_modify_tc on the **OWNING** test project of
 *     the addressed parent, never on a project supplied by the caller alone
 *     (403) - this is the hole the legacy endpoint had;
 *   - requires bffSameOriginGuard() (403 on cross-site XHR);
 *   - PROVES the parent node really belongs to the resolved test project
 *     before answering (404 otherwise), so it is not a cross-project
 *     existence oracle;
 *   - returns the colliding sibling rows, not just the legacy one-line
 *     message (additive; the legacy message is preserved verbatim in
 *     `message`).
 *
 * The check is a WARNING only, never a save gate - exact legacy behaviour
 * (neither lib/ajax/checkNodeDuplicateName.php nor the sibling
 * lib/ajax/checkTCaseDuplicateName.php blocked the form submit).
 *
 * Routes:
 *   GET /?action=init&tproject_id=N
 *   GET /?action=check&node_type=testsuite|testcase&name=..&parent_id=M
 *                [&node_id=K][&tproject_id=N]
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

function nout($data, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo json_encode($data);
    exit;
}

// Auth FIRST, verb second: an anonymous caller must not be able to learn the
// route's verb policy, and it keeps the order used by the peer BFFs
// (api/tcreorder/index.php).
$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    nout(['status' => 'error', 'code' => 'not_authenticated',
          'message' => 'Not authenticated'], 401);
}
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    nout(['status' => 'error', 'code' => 'not_authenticated',
          'message' => 'User not found'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    nout(['status' => 'error', 'code' => 'method_not_allowed',
          'message' => 'Method not allowed'], 405);
}

// Session inactivity window - the BFF counterpart of the legacy
// testlinkInitPage() -> checkSessionValid() contract.
bffEnforceSession($db);

/**
 * Scalar GET readers. An array-typed parameter (?name[]=x) is NOT coerced:
 * strval() on an array emits a PHP 8 "Array to string conversion" E_WARNING
 * that would land in the Event Viewer, and intval() on an array silently
 * yields 1. Both helpers therefore degrade to a well-defined value.
 */
function nGetStr($key) {
    return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : '';
}

function nGetInt($key, $default = 0) {
    if (!isset($_GET[$key])) { return intval($default); }
    return is_scalar($_GET[$key]) ? intval($_GET[$key]) : 0;
}

$action = nGetStr('action');

/**
 * Walk nodes_hierarchy upwards until the node whose type is a test project.
 * Returns the test project id, or null when the chain does not reach one.
 * Mirrors the helper of the same name in api/testcases/index.php.
 */
function ncOwningProject(&$db, $nodeId, $testprojectTypeId) {
    $nhTables = tlObjectWithDB::getDBTables('nodes_hierarchy');
    $nh = $nhTables['nodes_hierarchy'];
    $walk = intval($nodeId);
    $projectTypeId = intval($testprojectTypeId);
    $guard = 64;
    while ($walk > 0 && $guard-- > 0) {
        // Test the node's OWN type first: a test SUITE is checked against the
        // test project root as its parent, and that root IS the chain end -
        // walking past it would read parent_id = 0 and wrongly report the
        // project as "not found" (bug #1725).
        // The "is a test project" id comes from the node_types table, never a
        // hardcoded 1, so a non-default node_types fixture still works.
        // fetchFirstRow (NOT fetchFirstRowSingleColumn): two columns are
        // needed, one query per hop instead of two.
        $row = $db->fetchFirstRow(
            "SELECT parent_id, node_type_id FROM $nh WHERE id = " . intval($walk));
        if (empty($row) || !array_key_exists('parent_id', $row)) { return null; }
        if (intval($row['node_type_id']) === $projectTypeId) { return $walk; }
        $parent = intval($row['parent_id']);
        if ($parent == 0) { return null; }
        $walk = $parent;
    }
    return null;
}

/** node_type description => id, straight from the node_types table. */
function ncNodeTypes(&$db) {
    $m = new tree($db);
    $map = $m->get_available_node_types();
    return is_array($map) ? $map : [];
}

if (!in_array($action, ['init', 'check'], true)) {
    nout(['status' => 'error', 'code' => 'unknown_action',
          'message' => 'Unknown action'], 400);
}

// ---------------------------------------------------------------------------
// GET ?action=init&tproject_id=N
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tprojId = nGetInt('tproject_id', 0);
    if ($tprojId <= 0) {
        $tprojId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojId <= 0) {
        nout(['status' => 'error', 'code' => 'no_project',
              'message' => 'No test project selected'], 400);
    }

    // Rights are evaluated BEFORE the existence test, so a session with no
    // rights anywhere cannot enumerate which test project ids exist by
    // telling 403 apart from 404. hasRight() answers 'no' for an unknown
    // project, which is exactly what we want here.
    $canView  = ($user->hasRight($db, 'mgt_view_tc', $tprojId) === 'yes');
    $canModify = ($user->hasRight($db, 'mgt_modify_tc', $tprojId) === 'yes');
    if (!$canView && !$canModify) {
        nout(['status' => 'error', 'code' => 'no_permission',
              'message' => 'No permission'], 403);
    }

    $tprojectMgr = new testproject($db);
    $tprojInfo = $tprojectMgr->get_by_id($tprojId);
    if (is_null($tprojInfo)) {
        nout(['status' => 'error', 'code' => 'project_not_found',
              'message' => 'Test project not found'], 404);
    }

    $typeMap = ncNodeTypes($db);

    // Parent candidates: the test project root + every test suite of the
    // project, so the screen can check a suite name at project level and a
    // test case name inside any suite without hand-typing ids.
    $containers = [];
    $rootName = isset($tprojInfo['name']) ? $tprojInfo['name'] : '';
    $containers[] = [
        'id' => $tprojId,
        'name' => $rootName,
        'node_type' => 'testproject',
        'is_root' => true,
    ];
    $suiteRows = $tprojectMgr->gen_combo_test_suites($tprojId);
    if (is_array($suiteRows)) {
        foreach ($suiteRows as $sid => $sname) {
            $containers[] = [
                'id' => intval($sid),
                'name' => $sname,
                'node_type' => 'testsuite',
                'is_root' => false,
            ];
        }
    }

    $cfgCheckNames = config_get('check_names_for_duplicates');
    $cfgActionOnDup = config_get('action_on_duplicate_name');

    nout([
        'status' => 'ok',
        'context' => [
            'tproject_id' => $tprojId,
            'tproject_name' => $rootName,
            'tc_prefix' => (string) $tprojectMgr->getTestCasePrefix($tprojId),
        ],
        'node_types' => [
            ['key' => 'testsuite', 'id' => intval($typeMap['testsuite'] ?? 0),
             'label' => lang_get('testsuite')],
            ['key' => 'testcase', 'id' => intval($typeMap['testcase'] ?? 0),
             'label' => lang_get('testcase')],
        ],
        'containers' => $containers,
        'config' => [
            'check_names_for_duplicates' => (string) $cfgCheckNames,
            'action_on_duplicate_name' => (string) $cfgActionOnDup,
        ],
        'grants' => [
            'can_view' => $canView,
            'can_modify' => $canModify,
        ],
    ]);
}

// ---------------------------------------------------------------------------
// GET ?action=check&node_type=..&name=..&parent_id=M[&node_id=K][&tproject_id=N]
// ---------------------------------------------------------------------------
$nodeType = nGetStr('node_type');
$name = trim(nGetStr('name'));
$parentId = nGetInt('parent_id', 0);
$nodeId = nGetInt('node_id', 0);

if ($name === '') {
    nout(['status' => 'error', 'code' => 'missing_name',
          'message' => 'Missing name'], 400);
}
if (mb_strlen($name, 'UTF-8') > 100) {
    nout(['status' => 'error', 'code' => 'name_too_long',
          'message' => 'Name is limited to 100 characters'], 400);
}
// Allowlist: the route contract is testsuite|testcase. node_types also holds
// testproject, testplan, build, ... and accepting them would make meaningless
// "name uniqueness" questions answerable.
$typeMap = ncNodeTypes($db);
if (!in_array($nodeType, ['testsuite', 'testcase'], true)
    || !isset($typeMap[$nodeType])) {
    nout(['status' => 'error', 'code' => 'unknown_node_type',
          'message' => 'Unknown node type'], 400);
}
if ($parentId <= 0 && $nodeId <= 0) {
    nout(['status' => 'error', 'code' => 'missing_context',
          'message' => 'parent_id or node_id required'], 400);
}

$nhTables = tlObjectWithDB::getDBTables('nodes_hierarchy');
$nh = $nhTables['nodes_hierarchy'];

// Resolve the effective parent: when only node_id was supplied the legacy
// checkNodeDuplicateName.php left it to tree::nodeNameExists() to look the
// parent up; we do it here so the owning project can be proven first.
if ($parentId <= 0) {
    $p = $db->fetchFirstRowSingleColumn(
        "SELECT parent_id FROM $nh WHERE id = " . intval($nodeId),
        'parent_id');
    if (is_null($p)) {
        nout(['status' => 'error', 'code' => 'node_not_found',
              'message' => 'Node not found'], 404);
    }
    $parentId = intval($p);
}

// Prove ownership BEFORE answering: the parent must live under the test
// project the rights are checked on. This is the exact hole of the legacy
// endpoint, which answered for any parent_id of any project.
$owningTproj = ncOwningProject($db, $parentId, $typeMap['testproject'] ?? 0);
if (is_null($owningTproj) || $owningTproj <= 0) {
    nout(['status' => 'error', 'code' => 'parent_not_found',
          'message' => 'Parent node not found'], 404);
}

// Rights on the OWNING project come before any other answer, so a session
// with no rights anywhere cannot enumerate which node ids exist by telling
// 403 apart from 404.
$canView  = ($user->hasRight($db, 'mgt_view_tc', $owningTproj) === 'yes');
$canModify = ($user->hasRight($db, 'mgt_modify_tc', $owningTproj) === 'yes');
if (!$canView && !$canModify) {
    nout(['status' => 'error', 'code' => 'no_permission',
          'message' => 'No permission'], 403);
}

// A test CASE always lives inside a test SUITE, never directly under the test
// project root. Answering "Available" for that combination would be a green
// verdict about a question that cannot be true, so refuse it explicitly
// instead - the user picked the wrong parent.
if ($nodeType === 'testcase' && $parentId === $owningTproj) {
    nout(['status' => 'error', 'code' => 'testcase_needs_suite',
          'message' => 'A test case must be checked inside a test suite, not at test project level'], 400);
}

$requestedTproj = nGetInt('tproject_id', 0);
if ($requestedTproj > 0 && $requestedTproj !== $owningTproj) {
    nout(['status' => 'error', 'code' => 'project_mismatch',
          'message' => 'Parent node does not belong to the requested test project'], 400);
}

// The parent must be a real node of that project, otherwise nodeNameExists()
// would happily count rows under a parent that does not exist.
$parentExists = $db->fetchFirstRowSingleColumn(
    "SELECT id FROM $nh WHERE id = " . intval($parentId), 'id');
if (is_null($parentExists)) {
    nout(['status' => 'error', 'code' => 'parent_not_found',
          'message' => 'Parent node not found'], 404);
}

// The self-exclusion (edit mode) is only honoured when node_id really is a
// child of the addressed parent. Otherwise an id borrowed from another
// project would silently suppress a genuine collision.
if ($nodeId > 0) {
    $nodeParent = $db->fetchFirstRowSingleColumn(
        "SELECT parent_id FROM $nh WHERE id = " . intval($nodeId), 'parent_id');
    if (is_null($nodeParent) || intval($nodeParent) !== $parentId) {
        $nodeId = 0;
    }
}

$treeMgr = new tree($db);
$nodeTypeId = intval($typeMap[$nodeType]);

try {
    $check = $treeMgr->nodeNameExists(
        $name,
        $nodeTypeId,
        $nodeId > 0 ? $nodeId : null,
        $parentId
    );
} catch (\Throwable $e) {
    nout(['status' => 'error', 'code' => 'server_error',
          'message' => 'Name check failed'], 500);
}

$exists = !empty($check['status']);

// Additive: list the colliding siblings so the screen can name the node the
// user is colliding with (the legacy endpoint only returned the message).
$collisions = [];
if ($exists) {
    $sql = "SELECT NH.id, NH.name, NH.node_type_id, NH.parent_id"
         . " FROM $nh NH"
         . " WHERE NH.node_type_id = " . intval($nodeTypeId)
         . " AND NH.name = '" . $db->prepare_string($name) . "'"
         . " AND NH.parent_id = " . $db->prepare_int($parentId);
    if ($nodeId > 0) {
        $sql .= " AND NH.id <> " . $db->prepare_int($nodeId);
    }
    $sql .= " ORDER BY NH.id";
    $rows = $db->get_recordset($sql);
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $collisions[] = [
                'id' => intval($r['id']),
                'name' => $r['name'],
                'parent_id' => intval($r['parent_id']),
            ];
        }
    }
}

$parentName = (string) $db->fetchFirstRowSingleColumn(
    "SELECT name FROM $nh WHERE id = " . $db->prepare_int($parentId), 'name');

nout([
    'status' => 'ok',
    'name' => $name,
    'node_type' => $nodeType,
    'node_type_id' => $nodeTypeId,
    'parent_id' => $parentId,
    'parent_name' => $parentName,
    'node_id' => $nodeId,
    'tproject_id' => $owningTproj,
    'exists' => $exists,
    'duplicate_count' => count($collisions),
    // Legacy message preserved verbatim (lang_get('name_already_exists')).
    'message' => isset($check['msg']) ? $check['msg'] : '',
    'collisions' => $collisions,
    'grants' => [
        'can_view' => $canView,
        'can_modify' => $canModify,
    ],
]);
