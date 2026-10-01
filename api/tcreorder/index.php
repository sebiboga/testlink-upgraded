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
 * 404 unknown-or-foreign node / container (one opaque answer, whatever the
 *     reason). 403 test project unknown OR no rights - one opaque answer too.
 * 405 non-GET on write.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();
// Legacy parity: testlinkInitPage() ran checkSessionValid() on EVERY page, so
// an idle tab was thrown back to the login screen. This endpoint WRITES, so
// without this a tab left open past sessionInactivityTimeout would keep
// reordering test cases (the issue #1614 class). It must run after $db
// exists - checkSessionValid() takes the handle by reference.
bffEnforceSession($db);

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

/**
 * Node type ids, resolved from the node_types table by DESCRIPTION instead of
 * being hardcoded, so a renamed or localized description can never silently
 * re-route the screen (the same defensive approach api/suiteview uses).
 */
function tcreoNodeTypes(&$db)
{
    static $n = null;
    if ($n === null) {
        $T = tlObjectWithDB::getDBTables(array('node_types'));
        $rows = $db->get_recordset("SELECT id, description FROM {$T['node_types']}");
        $n = array();
        if (!is_null($rows)) {
            foreach ($rows as $r) {
                $n[strtolower((string)$r['description'])] = intval($r['id']);
            }
        }
        // The numeric fallbacks are only used if the table cannot be read at
        // all, which is the 1.9.20 ordering.
        $n += array('testproject' => 1, 'testsuite' => 2, 'testcase' => 3);
    }
    return $n;
}

function tcreoNodeTypeTestproject(&$db) { $n = tcreoNodeTypes($db); return $n['testproject']; }
function tcreoNodeTypeTestsuite(&$db)  { $n = tcreoNodeTypes($db); return $n['testsuite']; }
function tcreoNodeTypeTestcase(&$db)  { $n = tcreoNodeTypes($db); return $n['testcase']; }

/**
 * Table names for this endpoint.
 *
 * 2.0.1 has no table-prefix global at all, so interpolating one raised
 * "Undefined global variable" on EVERY query - a 1.2k-row E_WARNING storm in
 * the events table. tlObjectWithDB::getDBTables() is the canonical accessor
 * and the only one that honours a configured DB prefix, so it is resolved
 * once here and cached for the request.
 */
function tcreoTables()
{
    static $t = null;
    if ($t === null) {
        $t = tlObjectWithDB::getDBTables(
            array('nodes_hierarchy', 'tcversions', 'testprojects'));
    }
    return $t;
}

function out($data, $code = null)
{
    if (!is_null($code)) {
        http_response_code($code);
    }
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
 * The ONE opaque answer for "this container is not available to you".
 *
 * A container that does not exist, a container of the wrong node type, an
 * orphaned container and a container belonging to a project the caller has no
 * rights on must be indistinguishable. Otherwise the endpoint is an existence +
 * ownership oracle over the whole nodes_hierarchy id space: any authenticated
 * session could sweep container ids and learn which ones exist and which project
 * owns them, WITHOUT holding any right (issue #1761). Every such branch routes
 * through this function so the answers cannot drift apart.
 */
function tcreoNoSuchContainer()
{
    out(array('status' => 'error', 'code' => 'not_found',
              'message' => 'Container not found'), 404);
}

/**
 * Resolve + authorize the test project of the reorder screen.
 *
 * The project is ALWAYS re-derived from the addressed container when one is
 * given, so a caller can never present project A's rights while mutating
 * project B's tree.
 *
 * ORDERING IS THE SECURITY PROPERTY (issue #1761): every answer that could
 * distinguish "not yours" from "not there" - the cross-project refusal AND the
 * rights refusal - is the same opaque 404, and both are decided from the node
 * id the CALLER supplied. The guard is armed whenever a container id was
 * supplied, not only when the caller also named a project: a caller holding no
 * rights anywhere must not learn that an id exists either, and that is the case
 * that actually matters. Do not move a 403 in front of them, and do not
 * reintroduce a message that names the container, its node type or its owner.
 */
function tcreoProject(&$db, &$user, $requestedId, $containerId = 0)
{
    $tprojectId = intval($requestedId);
    $leakGuard = false;

    if ($containerId > 0) {
        $info = tcreoNodeInfo($db, $containerId);
        if (is_null($info)) {
            tcreoNoSuchContainer();
        }
        // Only a test suite (or the project root itself) may host test cases.
        // The message is the same as for an id that exists nowhere, on purpose:
        // naming the node type would turn this into a "does this id exist at all
        // and what is it" oracle over every nodes_hierarchy id of the install.
        if (intval($info['node_type_id']) != tcreoNodeTypeTestsuite($db) &&
            intval($info['node_type_id']) != tcreoNodeTypeTestproject($db)) {
            tcreoNoSuchContainer();
        }
        // tcreoOwningProject() walks parent_id to the node_type 1 root. If that
        // walk cannot prove ownership it returns 0 and the container is orphaned:
        // never assume it belongs to its own parent, which would check rights
        // against the wrong project. tcreoNodeInfo() already resolved the owner,
        // so this costs no extra query.
        $owner = intval($info['testproject_id']);
        if ($owner <= 0) {
            tcreoNoSuchContainer();
        }

        // The container's real owner always wins and a request naming a
        // different project is refused instead of being silently retargeted -
        // a rights check alone would let an admin unknowingly reorder another
        // project's test cases. The refusal is 404 with the very same message as
        // an id that exists nowhere, NOT 403: the container id came from the
        // caller, so a 403 here would confirm that this id is a real test suite
        // living in another project, which is exactly the oracle #1761 is about
        // (it used to answer 403 BEFORE any right was checked, so the oracle was
        // open to a session holding no right at all).
        if (intval($requestedId) > 0 && intval($requestedId) !== $owner) {
            tcreoNoSuchContainer();
        }
        // A container id was supplied, so EVERY refusal below is opaque: a 403
        // would confirm that this id is a real test suite of a project the
        // caller may not even be allowed to look at. This is armed whether or
        // not the caller named a project, because naming project A does not
        // entitle anybody to A's structure: a user with no rights on A at all
        // could otherwise sweep container ids and learn A's tree.
        $leakGuard = true;
        $tprojectId = $owner;
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
    /* Refs #1761: a project id the CALLER named is the caller's own business, so
       the refusal is uniform - 403 whether the project does not exist or exists
       without the right. Answering 404 for "does not exist" next to 403 for
       "exists but not yours" would make the PROJECT id an existence oracle (the
       same rule as #1759 case M21). A caller that does hold the right still gets
       its 200 below. */
    if (is_null($tproject) || !$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
        if ($leakGuard) {
            /* The project in play was derived from a caller-supplied node id, so
               403 here would confirm that node id exists somewhere. Same answer
               as a node id that exists nowhere. */
            tcreoNoSuchContainer();
        }
        out(array('status' => 'error', 'code' => 'forbidden',
                  'message' => 'Insufficient rights on this test project'), 403);
    }

    return array($tprojectId, $tprojectMgr, $tproject);
}

/**
 * Node row for any node id, plus its owning test project id.
 *
 * 2.0.1 nodes_hierarchy has no testproject_id column, so ownership is proved
 * by walking parent_id upwards until node_type_id = 1 is reached. The walk is
 * bounded by the depth guard below so a corrupted parent_id cycle cannot spin.
 */
function tcreoNodeInfo(&$db, $nodeId)
{
    $T = tcreoTables();
    static $cache = array();
    $nodeId = intval($nodeId);
    if ($nodeId <= 0) {
        return null;
    }
    if (array_key_exists($nodeId, $cache)) {
        return $cache[$nodeId];
    }

    $row = $db->get_recordset(
        "SELECT id, name, parent_id, node_type_id, node_order" .
        " FROM {$T['nodes_hierarchy']} WHERE id = {$nodeId}");

    $info = null;
    if (!is_null($row) && count($row) > 0) {
        $info = $row[0];
        $info['testproject_id'] = tcreoOwningProject($db, $info);
    }
    $cache[$nodeId] = $info;
    return $info;
}

/** Walk up nodes_hierarchy until the test project root (type 1) is reached. */
function tcreoOwningProject(&$db, $node)
{
    $T = tcreoTables();
    if (intval($node['node_type_id']) == tcreoNodeTypeTestproject($db)) {
        return intval($node['id']);
    }

    $parentId = intval($node['parent_id']);
    $guard = 0;
    while ($parentId > 0 && $guard < 64) {
        $guard++;
        $row = $db->get_recordset(
            "SELECT id, parent_id, node_type_id FROM {$T['nodes_hierarchy']}" .
            " WHERE id = {$parentId}");
        if (is_null($row) || count($row) == 0) {
            return 0;
        }
        if (intval($row[0]['node_type_id']) == tcreoNodeTypeTestproject($db)) {
            return intval($row[0]['id']);
        }
        $parentId = intval($row[0]['parent_id']);
    }
    return 0;
}

/**
 * Prove a node is a TEST CASE living directly under the given container of the
 * given project. Returns the node row or answers 404.
 *
 * $nodeId is CALLER-SUPPLIED and this runs after the project was authorized, so
 * every failure answers the same 404: distinguishing "no such node" from "not a
 * test case" from "lives in another container" from "owned by another project"
 * would let a caller entitled on ONE project map the node type and the owning
 * project of every id in nodes_hierarchy by brute force - the same oracle as
 * #1761, reached through node_id instead of container_id. Do not split these
 * messages apart again.
 */
function tcreoRequireChildTestcase(&$db, $nodeId, $containerId, $tprojectId)
{
    $info = tcreoNodeInfo($db, $nodeId);
    if (is_null($info)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Node not found'), 404);
    }
    if (intval($info['node_type_id']) != tcreoNodeTypeTestcase($db)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Node not found'), 404);
    }
    if (intval($info['parent_id']) != intval($containerId)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Node not found'), 404);
    }
    if (intval($info['testproject_id']) !== intval($tprojectId)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Node not found'), 404);
    }
    return $info;
}

/** Prove the container node + return the ordered direct test-case children. */
function tcreoContainerChildren(&$db, $containerId, $tprojectId)
{
    $T = tcreoTables();
    $container = tcreoNodeInfo($db, $containerId);
    // Same uniformity as tcreoRequireChildTestcase(), and for the same reason:
    // container_id is caller-supplied. tcreoProject() has already proven all of
    // this by the time we get here, so these are defence in depth - keep them
    // indistinguishable anyway.
    if (is_null($container)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Container not found'), 404);
    }
    if (intval($container['node_type_id']) != tcreoNodeTypeTestsuite($db) &&
        intval($container['node_type_id']) != tcreoNodeTypeTestproject($db)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Container not found'), 404);
    }
    if (intval($container['testproject_id']) !== intval($tprojectId)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Container not found'), 404);
    }

    // 2.0.1: a test case NODE id is the test case id, and its external id
    // lives in the tcversions rows whose node parent is that test case.
    $sql = "SELECT NH.id, NH.name, NH.node_order," .
           " (SELECT MAX(TCV.tc_external_id) FROM {$T['tcversions']} TCV" .
           "   JOIN {$T['nodes_hierarchy']} VNH ON VNH.id = TCV.id" .
           "   WHERE VNH.parent_id = NH.id) AS tc_external_id" .
           " FROM {$T['nodes_hierarchy']} NH" .
           " WHERE NH.parent_id = " . intval($containerId) .
           " AND NH.node_type_id = " . tcreoNodeTypeTestcase($db) .
           " ORDER BY NH.node_order, NH.id";
    $rows = $db->get_recordset($sql);
    return array($container, is_null($rows) ? array() : $rows);
}

/**
 * Every test suite of the project, for the container selector.
 *
 * 2.0.1 nodes_hierarchy has no testproject_id column, so the suites are found
 * by walking DOWN from the project root and keeping the node_type 2 nodes.
 * The walk is breadth-first and bounded, and every visited node is re-proven
 * to belong to this project, so a node re-parented under a foreign project
 * (something the legacy unauthenticated drag-drop could actually do) can never
 * leak into this list.
 */
function tcreoSuitesOf(&$db, $tprojectId)
{
    $T = tcreoTables();
    $tprojectId = intval($tprojectId);
    $out = array();
    $queue = array($tprojectId);
    $seen = array($tprojectId => true);
    $guard = 0;

    while (!empty($queue) && $guard < 2000) {
        $guard++;
        $id = intval(array_shift($queue));
        // parent_id is REQUIRED: tcreoOwningProject() walks up from it to prove
        // the node really lives under this test project.
        $rows = $db->get_recordset(
            "SELECT id, name, node_type_id, parent_id FROM {$T['nodes_hierarchy']}" .
            " WHERE parent_id = {$id} ORDER BY node_order, id");
        if (is_null($rows)) {
            continue;
        }
        foreach ($rows as $r) {
            $childId = intval($r['id']);
            if (isset($seen[$childId])) {
                continue;
            }
            $seen[$childId] = true;
            if (intval(tcreoOwningProject($db, $r)) !== $tprojectId) {
                continue;
            }
            if (intval($r['node_type_id']) == tcreoNodeTypeTestsuite($db)) {
                $out[] = array('id' => $childId, 'name' => $r['name']);
            }
            $queue[] = $childId;
        }
    }

    usort($out, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

/**
 * 2.0.1 stores the test project NAME in the nodes_hierarchy row (id =
 * testprojects.id), not in testprojects - reading ->name off the
 * testproject object would emit an undefined-property warning and render an
 * empty header.
 */
function tcreoProjectName(&$db, $tprojectId)
{
    $T = tcreoTables();
    $row = $db->get_recordset(
        "SELECT name FROM {$T['nodes_hierarchy']} WHERE id = " .
        intval($tprojectId) . " AND node_type_id = " . tcreoNodeTypeTestproject($db));
    if (!is_null($row) && count($row) > 0) {
        return (string)$row[0]['name'];
    }
    return '';
}

/** The external-id prefix is the only column testprojects still owns. */
function tcreoProjectPrefix(&$db, $tprojectId)
{
    $T = tcreoTables();
    $row = $db->get_recordset(
        "SELECT prefix FROM {$T['testprojects']} WHERE id = " .
        intval($tprojectId));
    if (!is_null($row) && count($row) > 0) {
        return (string)$row[0]['prefix'];
    }
    return '';
}

/**
 * Criterion the legacy screen honours, normalised to the two values this
 * screen offers.
 *
 * The shipped config value is the string 'EXTERNAL_ID'
 * (config.inc.php: $tlCfg->testcase_reorder_by = 'EXTERNAL_ID'), NOT
 * 'EXTERNALID' - matching only on 'EXTERNALID' meant the criterion silently
 * degraded to NAME on every install, i.e. the screen reported the opposite of
 * what 1.9.20 did (containerEdit.php: reorderTestCasesByExtID vs
 * reorderTestCasesDictionary). NAME is therefore the opt-in.
 */
function tcreoSortCriterion()
{
    $c = strtoupper((string)config_get('testcase_reorder_by'));
    return ($c === 'NAME') ? 'NAME' : 'EXTERNALID';
}

function tcreoRowsPayload(&$db, $rows)
{
    $out = array();
    $n = 0;
    foreach ($rows as $r) {
        $n++;
        $out[] = array(
            'node_id' => intval($r['id']),
            // 2.0.1: the test case NODE id is the test case id
            'tcase_id' => intval($r['id']),
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

    list($tprojectId, $tprojectMgr, $tproject) =
        tcreoProject($db, $user, $requestedProject, $containerId);

    // Default container: the test project root itself, i.e. the test cases that
    // sit directly under the project. 2.0.1 has one id space for both
    // (testprojects.id == the node_type 1 node id), so the project id is the
    // container id.
    if ($containerId <= 0) {
        $containerId = $tprojectId;
    }

    list($container, $children) = tcreoContainerChildren($db, $containerId, $tprojectId);

    $criterion = tcreoSortCriterion();
    $paged = $children;

    out(array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => $tprojectId,
            'tproject_name' => tcreoProjectName($db, $tprojectId),
            'tproject_prefix' => tcreoProjectPrefix($db, $tprojectId),
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
}
list($container, $children) = tcreoContainerChildren($db, $containerId, $tprojectId);

// Ids before the write, so a request that ends up being a no-op is reported as
// no_change instead of a misleading 'ok' (and the screen then shows no toast).
$beforeIds = array();
foreach ($children as $r) {
    $beforeIds[] = intval($r['id']);
}

/** Emit the result of a write, flagging a genuine no-op. */
function tcreoWriteResult(&$db, $containerId, $tprojectId, $beforeIds, $extra = array())
{
    list($container2, $after) = tcreoContainerChildren($db, $containerId, $tprojectId);
    $afterIds = array();
    foreach ($after as $r) {
        $afterIds[] = intval($r['id']);
    }
    $changed = ($beforeIds !== $afterIds);
    $payload = array('status' => $changed ? 'ok' : 'no_change',
                     'testcases' => tcreoRowsPayload($db, $after));
    if (!$changed) {
        $payload['message'] = 'Order already up to date';
    }
    return array_merge($payload, $extra);
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
            // Unreachable in practice: tcreoRequireChildTestcase() above already
            // proved the node is a test case of this container, which is exactly
            // what $children holds. Kept identical to its message anyway - this
            // is the same caller-supplied node_id surface as #1761.
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Node not found'), 404);
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

    out(tcreoWriteResult($db, $containerId, $tprojectId, $beforeIds));
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
        if ($key === 'sort_name') {
            // Legacy parity: natsort() over strtolower(name) - a plain
            // strcasecmp() put "TC 10" before "TC 2".
            $c = strnatcasecmp((string)$a[$key], (string)$b[$key]);
        } else {
            // Legacy used ORDER BY tc_external_id, which sorts the VARCHAR
            // column as text. Numeric ids are compared numerically on purpose:
            // users store plain numbers, and a text sort of '2' vs '10' is
            // never what anyone means. Non-numeric ext ids ('TC-A') still fall
            // back to a natural text compare.
            $ea = (string)$a[$key];
            $eb = (string)$b[$key];
            $bothNumeric = ($ea !== '' && $eb !== '' && is_numeric($ea) && is_numeric($eb));
            $c = $bothNumeric
                ? ((float)$ea < (float)$eb ? -1 : ((float)$ea > (float)$eb ? 1 : 0))
                : strnatcasecmp($ea, $eb);
        }
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

    out(tcreoWriteResult($db, $containerId, $tprojectId, $beforeIds,
                         array('reorder_by' => $by)));
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

    out(tcreoWriteResult($db, $containerId, $tprojectId, $beforeIds));
}

out(array('status' => 'error', 'code' => 'unknown_action',
          'message' => 'Unknown action'), 400);
