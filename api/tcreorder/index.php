<?php
/**
 * Test Specification Reorder / Move - BFF API
 * URL: /api/tcreorder/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Modernizes the two legacy reorder surfaces of the Test Specification area,
 * which had NO modern twin and were the last still-legacy code paths of the
 * legacy ExtJS tree frame (lib/testcases/listTestCases.php + tcTree.tpl):
 *
 *   1. lib/ajax/dragdroptprojectnodes.php
 *        doAction=changeParent -> tree::change_parent()
 *        doAction=doReorder   -> tree::change_order_bulk()
 *      The legacy AJAX endpoint performed NEITHER a rights check NOR a
 *      project-ownership check: any authenticated user - including one with
 *      no test-case management right at all - could re-parent any node of any
 *      test project (or of a REQUIREMENT tree, or a TEST PLAN) and could set an
 *      arbitrary node_order for any comma separated list of node ids. This BFF
 *      closes that hole: mgt_modify_tc is checked on the OWNING project and
 *      every submitted node id is proven to be a test case of the addressed
 *      container inside that project before a single row is written.
 *
 *   2. the legacy "Reorder Test Cases" toolbar of lib/testcases/containerEdit.php
 *        btn_reorder_testcases_alpha / btn_reorder_testcases_externalid
 *        driven by config_get('testcase_reorder_by') (NAME | EXTERNALID)
 *      Re-implemented as ?action=sort, honouring the very same config key.
 *
 * Reorder primitives are the legacy ones (tree::change_child_order() and
 * tree::change_order_bulk()), so node_order values stay byte-compatible with
 * 1.9.20 and with the modern test-spec tree ordering.
 *
 * Endpoints (JSON out):
 *   GET  ?action=init&tproject_id=<pid>[&container_id=<cid>]
 *   POST ?action=move   {tproject_id, container_id, node_id, position: top|bottom|up|down}
 *   POST ?action=sort   {tproject_id, container_id, by: NAME|EXTERNALID}
 *   POST ?action=reorder{tproject_id, container_id, nodelist: "1,2,3"}
 *
 * Legacy parity notes:
 *   - The legacy tree excluded nothing for test cases in edit_mode, but every
 *     other caller of change_child_order() (containerEdit move/copy,
 *     tcEdit move/copy) passed
 *     $exclude_node_types = array('testplan','requirement','requirement_spec')
 *     so the sibling list is never polluted by plan / requirement nodes. The
 *     modern list is restricted to node_type_id = 3 (test case) children, which
 *     is strictly stronger and renders the same rows.
 *   - config_get('testcase_reorder_by') decides the sort criterion and the
 *     localized toolbar label, exactly as containerEdit.php:614 did.
 *
 * Status contract: 401 anon / 403 no-right (+ CSRF) / 400 bad param /
 * 404 unknown-or-foreign node / container / project / 405 non-GET on write.
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

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'User found'));
    exit;
}

define('NODE_TYPE_TESTPROJECT', 1);
define('NODE_TYPE_TESTSUITE', 2);
define('NODE_TYPE_TESTCASE', 3);

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

function getParam($key, $default = null)
{
    $b = bffBody();
    if (isset($b[$key])) {
        return $b[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

function getInt($key, $default = 0)
{
    $v = getParam($key, $default);
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    if (is_string($v)) {
        $v = trim($v);
    }
    if ($v === '' || $v === null) {
        return $default;
    }
    if (!is_numeric($v)) {
        return $default;
    }
    return intval($v);
}

function getStr($key, $default = '')
{
    $v = getParam($key, $default);
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    return trim((string)$v);
}

/**
 * Resolve + authorize the test project of the reorder screen.
 *
 * The project is ALWAYS re-derived from the addressed container when one is
 * given, so a caller can never present project A's rights while mutating
 * project B's tree.
 */
function tcreoProject(&$db, &$user, $requestedId, $containerId = 0)
{
    $tprojectId = intval($requestedId);

    if ($containerId > 0) {
        $info = tcreoNodeInfo($db, $containerId);
        if (is_null($info)) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Container not found'), 404);
        }
        // Only a test suite (or the project root itself) may host test cases.
        if ($info['node_type_id'] != NODE_TYPE_TESTSUITE &&
            $info['node_type_id'] != NODE_TYPE_TESTPROJECT) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Container is not a test suite'), 404);
        }
        $owner = intval($info['testproject_id'] ? $info['testproject_id'] : $info['parent_id']);
        if ($owner > 0) {
            $tprojectId = $owner;
        }
    }

    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        out(array('status' => 'error', 'code' => 'no_context',
                  'message' => 'No test project in context'), 400);
    }

    $tprojectMgr = new testproject($db);
    $tproject = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($tproject)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Test project not found'), 404);
    }

    if (!$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
        out(array('status' => 'error', 'code' => 'forbidden',
                  'message' => 'Insufficient rights on this test project'), 403);
    }

    return array($tprojectId, $tprojectMgr, $tproject);
}

/** node row + its owning test project id, for any node id. */
function tcreoNodeInfo(&$db, $nodeId)
{
    static $cache = array();
    $nodeId = intval($nodeId);
    if ($nodeId <= 0) {
        return null;
    }
    if (array_key_exists($nodeId, $cache)) {
        return $cache[$nodeId];
    }

    $sql = "SELECT NH.id, NH.parent_id, NH.node_type_id, NH.name, NH.node_order," .
           " NH.testcase_id, NH.tcversion_id, NH.testsuite_id" .
           " FROM {$GLOBALS['dbprefix']}nodes_hierarchy NH" .
           " WHERE NH.id = {$nodeId}";
    $row = $db->get_recordset($sql);
    $info = null;
    if (!is_null($row) && count($row) > 0) {
        $info = $row[0];
        $info['testproject_id'] = tcreoOwningProject($db, $info);
    }
    $cache[$nodeId] = $info;
    return $info;
}

/** Walk up nodes_hierarchy until the test project root is reached. */
function tcreoOwningProject(&$db, $node)
{
    if ($node['node_type_id'] == NODE_TYPE_TESTPROJECT) {
        return intval($node['id']);
    }
    $tprojectMgr = new testproject($db);
    $info = $tprojectMgr->tree_manager->get_node_hierarchy_info(
        array(intval($node['id'])), null, array('nodeType' => null));
    if (!is_null($info) && isset($info[intval($node['id'])])) {
        $chain = $info[intval($node['id'])];
        // the root of the returned chain is the test project
        $first = reset($chain);
        if (is_array($first) && isset($first['id']) &&
            intval($first['node_type_id']) == NODE_TYPE_TESTPROJECT) {
            return intval($first['id']);
        }
    }
    return 0;
}

/**
 * Prove a node is a TEST CASE living directly under the given container of the
 * given project. Returns the node row or answers 404.
 */
function tcreoRequireChildTestcase(&$db, $nodeId, $containerId, $tprojectId)
{
    $info = tcreoNodeInfo($db, $nodeId);
    if (is_null($info)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Node not found'), 404);
    }
    if ($info['node_type_id'] != NODE_TYPE_TESTCASE) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Node is not a test case'), 404);
    }
    if (intval($info['parent_id']) != intval($containerId)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Node does not belong to this container'), 404);
    }
    $owner = intval($info['testproject_id']);
    if ($owner !== intval($tprojectId)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Node belongs to another test project'), 404);
    }
    return $info;
}

/** Prove the container node + return the ordered direct test-case children. */
function tcreoContainerChildren(&$db, $containerId, $tprojectId)
{
    $container = tcreoNodeInfo($db, $containerId);
    if (is_null($container)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Container not found'), 404);
    }
    if ($container['node_type_id'] != NODE_TYPE_TESTSUITE &&
        $container['node_type_id'] != NODE_TYPE_TESTPROJECT) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Container is not a test suite'), 404);
    }
    $owner = intval($container['testproject_id']);
    if ($owner !== intval($tprojectId)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Container belongs to another test project'), 404);
    }

    $sql = "SELECT NH.id, NH.name, NH.node_order, NH.testcase_id, TC.tc_external_id" .
           " FROM {$GLOBALS['dbprefix']}nodes_hierarchy NH" .
           " LEFT JOIN {$GLOBALS['dbprefix']}testcases TC ON TC.id = NH.testcase_id" .
           " WHERE NH.parent_id = " . intval($containerId) .
           " AND NH.node_type_id = " . NODE_TYPE_TESTCASE .
           " ORDER BY NH.node_order, NH.id";
    $rows = $db->get_recordset($sql);
    return array($container, is_null($rows) ? array() : $rows);
}

/** test suite list of the project, for the container selector. */
function tcreoSuitesOf(&$db, $tprojectId)
{
    $sql = "SELECT NH.id, NH.name FROM {$GLOBALS['dbprefix']}nodes_hierarchy NH" .
           " WHERE NH.node_type_id = " . NODE_TYPE_TESTSUITE .
           " AND NH.testproject_id = " . intval($tprojectId) .
           " ORDER BY NH.name";
    $rows = $db->get_recordset($sql);
    if (is_null($rows)) {
        return array();
    }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array('id' => intval($r['id']), 'name' => $r['name']);
    }
    return $out;
}

function tcreoSortCriterion()
{
    $c = strtoupper((string)config_get('testcase_reorder_by'));
    return ($c === 'EXTERNALID') ? 'EXTERNALID' : 'NAME';
}

function tcreoRowsPayload(&$db, $rows)
{
    $out = array();
    $n = 0;
    foreach ($rows as $r) {
        $n++;
        $out[] = array(
            'node_id' => intval($r['id']),
            'tcase_id' => intval($r['testcase_id']),
            'name' => $r['name'],
            'external_id' => isset($r['tc_external_id']) ? (string)$r['tc_external_id'] : '',
            'position' => $n,
        );
    }
    return $out;
}

$action = getStr('action', 'init');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'GET' && $action === 'init') {
    out(array('status' => 'error', 'message' => 'Method not allowed'), 405);
}

if ($action === 'init') {
    $requestedProject = getInt('tproject_id', 0);
    $containerId = getInt('container_id', 0);

    if ($containerId <= 0) {
        // Default container: the project root (test cases directly under it)
        $tprojectMgrTmp = new testproject($db);
        $tprojectTmp = intval($requestedProject) > 0
            ? $tprojectMgrTmp->get_by_id(intval($requestedProject))
            : null;
        if (!is_null($tprojectTmp)) {
            $containerId = intval($tprojectTmp->id);
        }
    }

    list($tprojectId, $tprojectMgr, $tproject) =
        tcreoProject($db, $user, $requestedProject, $containerId);

    list($container, $children) = tcreoContainerChildren($db, $containerId, $tprojectId);

    $criterion = tcreoSortCriterion();
    $paged = $children;

    out(array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => $tprojectId,
            'tproject_name' => $tproject->name,
            'tproject_prefix' => $tproject->prefix,
            'tplan_id' => intval($_SESSION['testplanID'] ?? 0),
        ),
        'container' => array(
            'id' => intval($container['id']),
            'name' => $container['name'],
            'node_type' => intval($container['node_type_id']),
        ),
        'suites' => tcreoSuitesOf($db, $tprojectId),
        'reorder_by' => $criterion,
        'count' => count($paged),
        'testcases' => tcreoRowsPayload($db, $paged),
        'rights' => array('mgt_modify_tc' => true),
    ));
}

if ($method !== 'POST') {
    out(array('status' => 'error', 'message' => 'Method not allowed'), 405);
}

$requestedProject = getInt('tproject_id', 0);
$containerId = getInt('container_id', 0);
list($tprojectId, $tprojectMgr, $tproject) =
    tcreoProject($db, $user, $requestedProject, $containerId);

if ($containerId <= 0) {
    $containerId = $tprojectId;
    list($container, $children) = tcreoContainerChildren($db, $containerId, $tprojectId);
} else {
    list($container, $children) = tcreoContainerChildren($db, $containerId, $tprojectId);
}

$treeMgr = new tree($db);
$excludeNodeTypes = array('testplan' => 1, 'requirement' => 1, 'requirement_spec' => 1);

if ($action === 'move') {
    $nodeId = getInt('node_id', 0);
    $position = strtolower(getStr('position', ''));
    if (!in_array($position, array('top', 'bottom', 'up', 'down'), true)) {
        out(array('status' => 'error', 'code' => 'bad_param',
                  'message' => 'Invalid position'), 400);
    }
    tcreoRequireChildTestcase($db, $nodeId, $containerId, $tprojectId);

    if ($position === 'top' || $position === 'bottom') {
        $treeMgr->change_child_order($containerId, $nodeId, $position, $excludeNodeTypes);
    } else {
        // 'up' / 'down' - swap with the neighbour, expressed through the very
        // same legacy primitive (change_order_bulk) the drag-drop used.
        $ids = array();
        foreach ($children as $r) {
            $ids[] = intval($r['id']);
        }
        $idx = array_search($nodeId, $ids, true);
        if ($idx === false) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Node does not belong to this container'), 404);
        }
        $swap = ($position === 'up') ? $idx - 1 : $idx + 1;
        if ($swap < 0 || $swap >= count($ids)) {
            out(array('status' => 'no_change', 'message' => 'Already at the boundary',
                      'testcases' => tcreoRowsPayload($db, $children)), 200);
        }
        $tmp = $ids[$idx];
        $ids[$idx] = $ids[$swap];
        $ids[$swap] = $tmp;
        $treeMgr->change_order_bulk($ids);
    }

    list($container2, $after) = tcreoContainerChildren($db, $containerId, $tprojectId);
    out(array('status' => 'ok', 'testcases' => tcreoRowsPayload($db, $after)));
}

if ($action === 'sort') {
    $by = strtoupper(getStr('by', ''));
    if (!in_array($by, array('NAME', 'EXTERNALID'), true)) {
        out(array('status' => 'error', 'code' => 'bad_param',
                  'message' => 'Invalid sort criterion'), 400);
    }

    $rows = $children;
    $key = ($by === 'NAME') ? 'sort_name' : 'sort_ext';
    foreach ($rows as $k => $r) {
        $rows[$k]['sort_name'] = (string)$r['name'];
        $rows[$k]['sort_ext'] = isset($r['tc_external_id']) ? (string)$r['tc_external_id'] : '';
    }
    usort($rows, function ($a, $b) use ($key) {
        $c = strcasecmp((string)$a[$key], (string)$b[$key]);
        if ($c !== 0) {
            return $c;
        }
        return strnatcasecmp((string)$a['name'], (string)$b['name']);
    });

    $ids = array();
    foreach ($rows as $r) {
        $ids[] = intval($r['id']);
    }
    if (count($ids) > 0) {
        $treeMgr->change_order_bulk($ids);
    }

    list($container2, $after) = tcreoContainerChildren($db, $containerId, $tprojectId);
    out(array('status' => 'ok', 'reorder_by' => $by,
              'testcases' => tcreoRowsPayload($db, $after)));
}

if ($action === 'reorder') {
    $raw = getParam('nodelist', '');
    if (is_array($raw)) {
        $list = $raw;
    } else {
        $list = explode(',', (string)$raw);
    }

    $ids = array();
    foreach ($list as $v) {
        $v = trim((string)$v);
        if ($v === '') {
            continue;
        }
        if (!ctype_digit($v)) {
            out(array('status' => 'error', 'code' => 'bad_param',
                      'message' => 'Node list must be numeric ids'), 400);
        }
        $ids[] = intval($v);
    }
    if (count($ids) < 2) {
        out(array('status' => 'error', 'code' => 'bad_param',
                  'message' => 'At least two node ids are required'), 400);
    }
    if (count(array_unique($ids)) !== count($ids)) {
        out(array('status' => 'error', 'code' => 'bad_param',
                  'message' => 'Duplicate node ids in list'), 400);
    }

    // Every id must be a test case of THIS container of THIS project, and the
    // list must be a permutation of exactly the container's test cases.
    // This is the check the legacy drag-drop endpoint never performed.
    $known = array();
    foreach ($children as $r) {
        $known[] = intval($r['id']);
    }
    sort($known);
    $given = $ids;
    sort($given);
    if ($known !== $given) {
        foreach ($ids as $id) {
            tcreoRequireChildTestcase($db, $id, $containerId, $tprojectId);
        }
        out(array('status' => 'error', 'code' => 'bad_param',
                  'message' => 'Node list must contain every test case of the container exactly once'), 400);
    }

    $treeMgr->change_order_bulk($ids);

    list($container2, $after) = tcreoContainerChildren($db, $containerId, $tprojectId);
    out(array('status' => 'ok', 'testcases' => tcreoRowsPayload($db, $after)));
}

out(array('status' => 'error', 'code' => 'unknown_action',
          'message' => 'Unknown action'), 400);
