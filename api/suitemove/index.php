<?php
/**
 * Move / Reorder Test Suites - BFF API
 * URL: /api/suitemove/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Modernizes the suite-level slice of the legacy generic tree drag-and-drop
 * endpoint lib/ajax/dragdroptreenodes.php (doAction=changeParent ->
 * tree::change_parent(), doAction=doReorder -> tree::change_order_bulk()).
 *
 * Why this endpoint is worth a screen of its own:
 *   - It was ORPHANED dead code: no template, no screen and no JS in the whole
 *     tree referenced it, so its behaviour had never been reviewed.
 *   - It called only testlinkInitPage() - NO hasRight() whatsoever - read its
 *     parameters from $_REQUEST (so a plain GET MUTATED the tree) and never
 *     proved that a submitted node id belonged to the caller's project. Any
 *     authenticated session could re-parent or renumber any nodes_hierarchy row
 *     of ANY test project (and of a requirement tree, or of a test plan).
 *   - Its two sibling endpoints already have modern screens (api/tcreorder for
 *     test cases, api/reqtreorder for requirements), but the suite-level
 *     capability had NO modern twin at all: api/tcreorder only accepts
 *     node_type_id = 3 (test case) nodes, api/reqtreorder only requirement
 *     nodes. So moving a test SUITE under another suite, or reordering suites
 *     among their siblings, was a capability with no authorized UI at all.
 *
 * Everything the legacy endpoint could do is now available, but only through a
 * POST that carries a same-origin proof and a rights check on the OWNING
 * project, and only for nodes that are proven to be test suites of that project.
 *
 * Reorder primitives are the legacy ones (tree::change_parent() and
 * tree::change_order_bulk()), so node_order values stay byte-compatible with
 * 1.9.20 and with the modern test-spec tree ordering.
 *
 * Endpoints (JSON out):
 *   GET  ?action=init&tproject_id=<pid>[&container_id=<cid>]
 *   GET  ?action=suites&tproject_id=<pid>[&container_id=<cid>]
 *   POST ?action=move    {tproject_id, node_id, new_parent_id, position: top|bottom|up|down}
 *   POST ?action=reorder {tproject_id, container_id, nodelist: "1,2,3"}
 *
 * Status contract: 401 anon / 403 no-right (+ CSRF) / 400 bad param /
 * 404 unknown-or-foreign node / container / project / 405 non-GET on write /
 * 409 cycle (a suite cannot be moved inside itself).
 *
 * Refs #1740
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
// moving test suites (the issue #1614 class).
bffEnforceSession($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
                          'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
                          'message' => 'User not found'));
    exit;
}

/**
 * Node type ids, resolved from the node_types table by DESCRIPTION instead of
 * being hardcoded, so a renamed or localized description can never silently
 * re-route the screen (same defensive approach as api/tcreorder).
 */
function suitMoveNodeTypes(&$db)
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
        // Numeric fallbacks are the 1.9.20 ordering and are only used when the
        // table cannot be read at all.
        $n += array('testproject' => 1, 'testsuite' => 2, 'testcase' => 3);
    }
    return $n;
}

function suitMoveNodeTypeTestproject(&$db) { $n = suitMoveNodeTypes($db); return $n['testproject']; }
function suitMoveNodeTypeTestsuite(&$db)  { $n = suitMoveNodeTypes($db); return $n['testsuite']; }

/**
 * Table names. 2.0.1 has no table-prefix global at all, so interpolating one
 * raised "Undefined global variable" on EVERY query; tlObjectWithDB::getDBTables()
 * is the canonical accessor and honours a configured DB prefix.
 */
function suitMoveTables()
{
    static $t = null;
    if ($t === null) {
        $t = tlObjectWithDB::getDBTables(
            array('nodes_hierarchy', 'tcversions', 'testprojects'));
    }
    return $t;
}

/**
 * The test project NAME lives in nodes_hierarchy on the project node, NOT in
 * testprojects (2.0.1 keeps only the prefix there), so it must be read from the
 * tree - $tproject->testproject_name answers an empty string.
 */
function suitMoveProjectName(&$db, $tprojectId)
{
    $T = suitMoveTables();
    $row = $db->get_recordset(
        "SELECT name FROM {$T['nodes_hierarchy']} WHERE id = " . intval($tprojectId) .
        " AND node_type_id = " . suitMoveNodeTypeTestproject($db));
    if (!is_null($row) && count($row) > 0) {
        return (string)$row[0]['name'];
    }
    return '';
}

/** The external-id prefix is the only column testprojects still owns. */
function suitMoveProjectPrefix(&$db, $tprojectId)
{
    $T = suitMoveTables();
    $row = $db->get_recordset(
        "SELECT prefix FROM {$T['testprojects']} WHERE id = " . intval($tprojectId));
    if (!is_null($row) && count($row) > 0) {
        return (string)$row[0]['prefix'];
    }
    return '';
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

/** Node row for any node id, plus its owning test project id (parent walk). */
function suitMoveNodeInfo(&$db, $nodeId)
{
    $T = suitMoveTables();
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
        $info['testproject_id'] = suitMoveOwningProject($db, $info);
    }
    $cache[$nodeId] = $info;
    return $info;
}

/** Walk up nodes_hierarchy until the test project root (type 1) is reached. */
function suitMoveOwningProject(&$db, $node)
{
    $T = suitMoveTables();
    /* isset() (not a bare read) so a projection that forgot node_type_id can
       never turn into a stream of E_WARNINGs in the Event Viewer; a missing
       type simply means "not a project root", which is the correct reading. */
    $nodeType = isset($node['node_type_id']) ? intval($node['node_type_id']) : 0;
    if ($nodeType == suitMoveNodeTypeTestproject($db)) {
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
        if (intval($row[0]['node_type_id']) == suitMoveNodeTypeTestproject($db)) {
            return intval($row[0]['id']);
        }
        $parentId = intval($row[0]['parent_id']);
    }
    return 0;
}

/** Every ancestor id of a node, root-first, itself included. */
function suitMoveChain(&$db, $nodeId)
{
    $chain = array();
    $current = intval($nodeId);
    $guard = 0;
    while ($current > 0 && $guard < 64) {
        $guard++;
        $info = suitMoveNodeInfo($db, $current);
        if (is_null($info)) {
            break;
        }
        array_unshift($chain, $info);
        if (intval($info['node_type_id']) == suitMoveNodeTypeTestproject($db)) {
            break;
        }
        $next = intval($info['parent_id']);
        if ($next == $current) {
            break;
        }
        $current = $next;
    }
    return $chain;
}

/**
 * Resolve + authorize the test project of the screen.
 *
 * The project is ALWAYS re-derived from the addressed container when one is
 * given, so a caller can never present project A's rights while mutating
 * project B's tree. The rights are checked BEFORE the project is resolved from
 * a caller-supplied id whenever a container is addressed, so this endpoint is
 * not a test-project-id existence oracle.
 *
 * Refs #1759: "resolved from a caller-supplied id" only holds for the PROJECT
 * id; the CONTAINER itself is always looked up in the tree, so a container of
 * another project is necessarily reached. Every answer about a caller-supplied
 * node id is therefore 404 with the message of a non-existent id - the caller
 * must not be able to tell "exists elsewhere" from "does not exist", nor what
 * kind of node it is. The remaining 403s are only about a project the caller
 * addressed on its own, without naming any node - insufficient rights, or the
 * session's own context.
 *
 * Refs #1779: the project in play is derived from the caller's node id
 * whenever a container is addressed, so the refusal must be opaque even when
 * the caller ALSO named that project - naming project A does not entitle
 * anybody to A's structure, and a 403 next to the 404 of an id that exists
 * nowhere is a per-id existence oracle open to a session holding no right at
 * all. Same rule as tcreoProject() (api/tcreorder, #1761).
 *
 * @param bool $leakGuard true when the effective project below was derived
 *                        from a node id the CALLER supplied. A refusal on
 *                        such a request must be a 404: the 403 would
 *                        otherwise be the oracle that node id exists
 *                        somewhere.
 */
function suitMoveProject(&$db, &$user, $requestedId, $containerId = 0, $leakGuard = false)
{
    $tprojectId = intval($requestedId);

    if ($containerId > 0) {
        $info = suitMoveNodeInfo($db, $containerId);
        if (is_null($info)) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Container not found'), 404);
        }
        // Only a test suite or the project root may host test suites. The
        // message is the same as for a non-existent id on purpose: naming the
        // node type would turn this into a "does this id exist at all and what
        // is it" oracle over every nodes_hierarchy id of the installation.
        if (intval($info['node_type_id']) != suitMoveNodeTypeTestsuite($db) &&
            intval($info['node_type_id']) != suitMoveNodeTypeTestproject($db)) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Container not found'), 404);
        }
        $owner = intval($info['testproject_id']);
        if ($owner <= 0) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Container not found'), 404);
        }
        // The container's real owner always wins and a request naming a
        // different project is refused instead of being silently retargeted.
        //
        // Refs #1759: 404, and with the very same message as a container id
        // that exists nowhere. This check runs BEFORE the rights check, so a
        // 403 here answered "this id is a real test suite that lives in
        // another project" to a caller who only has rights on the project it
        // named - one 403 per existing suite is enough to enumerate the suite
        // ids of any project of the installation. The moved node and the
        // destination of the move action already answered 404 for the same
        // reason; this was the last ownership check still answering 403.
        if (intval($requestedId) > 0 && intval($requestedId) !== $owner) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Container not found'), 404);
        }
        // The project in play is now the container's owner, i.e. it was derived
        // from a node id the CALLER supplied, so every refusal from here on has
        // to be the opaque 404 of a node id that exists nowhere - also when the
        // caller named that very project itself.
        //
        // Refs #1779: the guard used to be armed on the INVERSE predicate
        // ($requestedId <= 0), which left the node id of an unentitled project
        // probe-able to a session holding no right at all: naming project A and
        // a container of A answered 403 forbidden while an absent container
        // answered 404 not_found, so one sweep of container_id enumerated
        // every suite and project root of a project the caller may not even
        // look at. Naming project A does not entitle anybody to A's structure.
        // Accepted trade-off, identical to tcreorder #1761 case R25: a
        // view-only user of their OWN project who names a container now gets
        // the opaque 404 too; a request naming no container keeps its
        // informative 403 below.
        $leakGuard = ($containerId > 0);
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
    /* Refs #1759: a project id the caller NAMED is the caller's own business -
       answering 403 for "exists but not yours" and 404 for "does not exist"
       makes the project id an existence oracle, so the refusal is uniform and
       existence is no longer observable. A caller that does have rights still
       gets its 200 below. */
    if (is_null($tproject) || !$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
        if ($leakGuard) {
            /* The project in play was derived from a caller-supplied node id,
               so 403 here would confirm that node id exists somewhere. Same
               answer as a node id that exists nowhere. */
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Container not found'), 404);
        }
        out(array('status' => 'error', 'code' => 'forbidden',
                  'message' => 'Insufficient rights on this test project'), 403);
    }

    return array($tprojectId, $tproject);
}

/** Direct children of a container, in tree order, restricted to test suites. */
function suitMoveChildren(&$db, $containerId, $tprojectId)
{
    $T = suitMoveTables();
    $ntSuite = suitMoveNodeTypeTestsuite($db);

    $rows = $db->get_recordset(
        "SELECT id, name, parent_id, node_type_id, node_order" .
        " FROM {$T['nodes_hierarchy']}" .
        " WHERE parent_id = " . intval($containerId) .
        " AND node_type_id = {$ntSuite}" .
        " ORDER BY node_order, id");

    $outRows = array();
    if (is_null($rows)) {
        return $outRows;
    }
    foreach ($rows as $r) {
        if (intval(suitMoveOwningProject($db, $r)) !== intval($tprojectId)) {
            continue;
        }
        $outRows[] = array(
            'id'         => intval($r['id']),
            'name'       => (string)$r['name'],
            'parent_id'  => intval($r['parent_id']),
            'node_order' => intval($r['node_order']),
            'testcases'  => suitMoveTestCaseCount($db, intval($r['id'])),
            'sub_suites' => suitMoveSubSuiteCount($db, intval($r['id'])),
        );
    }
    return $outRows;
}

/** Direct test-case children of a suite. */
function suitMoveTestCaseCount(&$db, $suiteId)
{
    $T = suitMoveTables();
    $ntCase = suitMoveNodeTypes($db);
    $ntCase = isset($ntCase['testcase']) ? $ntCase['testcase'] : 3;

    $rows = $db->get_recordset(
        "SELECT COUNT(*) AS c FROM {$T['nodes_hierarchy']}" .
        " WHERE parent_id = " . intval($suiteId) .
        " AND node_type_id = {$ntCase}");
    if (is_null($rows) || count($rows) == 0) {
        return 0;
    }
    return intval($rows[0]['c']);
}

/** Direct test-suite children of a suite. */
function suitMoveSubSuiteCount(&$db, $suiteId)
{
    $T = suitMoveTables();
    $ntSuite = suitMoveNodeTypeTestsuite($db);

    $rows = $db->get_recordset(
        "SELECT COUNT(*) AS c FROM {$T['nodes_hierarchy']}" .
        " WHERE parent_id = " . intval($suiteId) .
        " AND node_type_id = {$ntSuite}");
    if (is_null($rows) || count($rows) == 0) {
        return 0;
    }
    return intval($rows[0]['c']);
}

/**
 * Every test suite of ONE project, indented by depth, for the destination
 * picker.
 *
 * 2.0.1 has no project_id column on nodes_hierarchy, so the previous version
 * selected ALL test-suite rows of the whole installation and proved each one
 * with suitMoveOwningProject() - one SELECT per ancestor, up to 64 per suite.
 * That is O(all suites x depth) queries on every init/suites call, i.e. a
 * timeout on a real installation.
 *
 * This walks DOWN from the project node instead (same shape as
 * tcreoSuitesOf(), api/tcreorder/index.php:392): the walk itself is the proof
 * of ownership, because parent_id is followed from the project node, so no
 * per-row ownership query is needed at all. Only the two children queries per
 * visited level are issued.
 *
 * @param int $excludeId when non-zero, that suite and its whole subtree are
 *                       left out (they cannot be the destination of a move).
 */
function suitMoveAllSuites(&$db, $tprojectId, $excludeId = 0)
{
    $T = suitMoveTables();
    $ntSuite = suitMoveNodeTypeTestsuite($db);
    $tprojectId = intval($tprojectId);
    $excludeId = intval($excludeId);

    $cache = array();
    $childrenOf = function ($parentId) use (&$cache, &$childrenOf, $db, $T, $ntSuite) {
        $parentId = intval($parentId);
        if (!isset($cache[$parentId])) {
            $cache[$parentId] = array();
            $rows = $db->get_recordset(
                "SELECT id, name, parent_id, node_order" .
                " FROM {$T['nodes_hierarchy']}" .
                " WHERE parent_id = {$parentId} AND node_type_id = {$ntSuite}" .
                " ORDER BY node_order, id");
            if (!is_null($rows)) {
                foreach ($rows as $r) {
                    $cache[$parentId][] = $r;
                }
            }
        }
        return $cache[$parentId];
    };

    $flat = array();
    $visited = array($tprojectId => true);

    /*
     * Depth-FIRST pre-order, because the picker renders an indented tree: a
     * suite must be followed by its own children before its siblings. The
     * $visited set makes a corrupt tree that points a suite back at one of its
     * ancestors terminate instead of looping.
     */
    $walk = function ($parentId, $depth) use (&$walk, &$flat, &$visited,
                                              $childrenOf, $excludeId) {
        if ($depth > 64) {
            return;
        }
        foreach ($childrenOf($parentId) as $r) {
            $id = intval($r['id']);
            /* Skipping the excluded suite also skips its whole subtree: it is
               not reachable through any other parent. */
            if ($id == $excludeId || isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $flat[] = array(
                'id'    => $id,
                'name'  => str_repeat('- ', $depth) . (string)$r['name'],
                'depth' => $depth,
            );
            $walk($id, $depth + 1);
        }
    };
    $walk($tprojectId, 0);

    return $flat;
}

/**
 * Prove a node is a TEST SUITE of the given project. Returns the node row or
 * answers 404. Suite-ness AND ownership are both proven here - the legacy
 * endpoint proved neither.
 */
function suitMoveRequireSuite(&$db, $nodeId, $tprojectId, $label = 'Node')
{
    $info = suitMoveNodeInfo($db, $nodeId);
    if (is_null($info)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => $label . ' not found'), 404);
    }
    if (intval($info['node_type_id']) != suitMoveNodeTypeTestsuite($db)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => $label . ' is not a test suite'), 404);
    }
    if (intval($info['testproject_id']) !== intval($tprojectId)) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => $label . ' belongs to another test project'), 404);
    }
    return $info;
}

/** Write a dense 1..n node_order for the suites of a container. */
function suitRenumberSuites(&$db, $containerId, $tprojectId)
{
    $children = suitMoveChildren($db, $containerId, $tprojectId);
    if (count($children) < 2) {
        return $children;
    }
    $ids = array();
    foreach ($children as $c) {
        $ids[] = intval($c['id']);
    }
    $treeMgr = new tree($db);
    $treeMgr->change_order_bulk($ids);
    return suitMoveChildren($db, $containerId, $tprojectId);
}

$action = getStr('action', 'init');
$isWrite = in_array($action, array('move', 'reorder'), true);

if ($isWrite && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(array('status' => 'error', 'code' => 'method_not_allowed',
              'message' => 'This action requires POST'), 405);
}

switch ($action) {
    // ---------------------------------------------------------------- init
    case 'init': {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            out(array('status' => 'error', 'code' => 'method_not_allowed',
                      'message' => 'init requires GET'), 405);
        }
        $requestedId = getInt('tproject_id', 0);
        $containerId = getInt('container_id', 0);

        /* Refs #1759: \$containerId is a caller-supplied node id, so the
           resolver arms its leak guard from the CONTAINER and every refusal
           about it is the opaque 404 (Refs #1779). */
        list($tprojectId, $tproject) = suitMoveProject($db, $user, $requestedId, $containerId);

        $T = suitMoveTables();
        $container = null;
        if ($containerId <= 0) {
            // Default container: the project root, i.e. the top level of the tree.
            $rootRows = $db->get_recordset(
                "SELECT id, name FROM {$T['nodes_hierarchy']}" .
                " WHERE node_type_id = " . suitMoveNodeTypeTestproject($db) .
                " AND id = " . intval($tprojectId));
            if (!is_null($rootRows) && count($rootRows) > 0) {
                $containerId = intval($rootRows[0]['id']);
                $container = array(
                    'id'   => $containerId,
                    'name' => (string)$rootRows[0]['name'],
                    'root' => true,
                );
            }
        } else {
            $info = suitMoveNodeInfo($db, $containerId);
            $container = array(
                'id'   => $containerId,
                'name' => (string)$info['name'],
                'root' => intval($info['node_type_id']) == suitMoveNodeTypeTestproject($db),
            );
        }

        $children = $containerId > 0
            ? suitMoveChildren($db, $containerId, $tprojectId)
            : array();

        out(array(
            'status'     => 'ok',
            'context'    => array(
                'tproject_id'   => intval($tprojectId),
                'tproject_name' => suitMoveProjectName($db, $tprojectId),
                'prefix'        => suitMoveProjectPrefix($db, $tprojectId),
                'can_modify'    => $user->hasRight($db, 'mgt_modify_tc', $tprojectId),
            ),
            'container'  => $container,
            'suites'     => $children,
            'all_suites' => suitMoveAllSuites($db, $tprojectId),
        ));
        break;
    }

    // -------------------------------------------------------------- suites
    case 'suites': {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            out(array('status' => 'error', 'code' => 'method_not_allowed',
                      'message' => 'suites requires GET'), 405);
        }
        $excludeId = getInt('exclude_id', 0);
        list($tprojectId, $tproject) = suitMoveProject($db, $user, getInt('tproject_id', 0), 0);
        out(array(
            'status'     => 'ok',
            'context'    => array(
                'tproject_id'   => intval($tprojectId),
                'tproject_name' => suitMoveProjectName($db, $tprojectId),
                'prefix'        => suitMoveProjectPrefix($db, $tprojectId),
            ),
            'all_suites' => suitMoveAllSuites($db, $tprojectId, $excludeId),
        ));
        break;
    }

    // ---------------------------------------------------------------- move
    case 'move': {
        $tprojectId = getInt('tproject_id', 0);
        $nodeId = getInt('node_id', 0);
        $position = strtolower(getStr('position', 'bottom'));
        $newParentId = getInt('new_parent_id', 0);

        if ($nodeId <= 0) {
            out(array('status' => 'error', 'code' => 'bad_request',
                      'message' => 'Missing node_id'), 400);
        }
        if (!in_array($position, array('top', 'bottom', 'up', 'down'), true)) {
            out(array('status' => 'error', 'code' => 'bad_request',
                      'message' => 'Invalid position'), 400);
        }

        // The owning project is re-derived from the node itself, then the
        // requested project (if any) must match it, so a caller cannot present
        // project A's rights while moving a suite of project B.
        $nodeInfo = suitMoveNodeInfo($db, $nodeId);
        if (is_null($nodeInfo)) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Suite not found'), 404);
        }
        $owner = intval($nodeInfo['testproject_id']);
        if ($owner <= 0) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Suite has no owning test project'), 404);
        }
        if ($tprojectId > 0 && $tprojectId !== $owner) {
            /* 404, not 403: a 403 would tell a caller who has rights on project
               A that node N exists inside another project B. */
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Suite has no owning test project'), 404);
        }

        /* Refs #1759: \$owner was derived from the caller-supplied node_id, so
           a request that named no test project would otherwise answer 403 for
           the very id it is probing.
           Refs #1779: \$owner is ALWAYS derived from the caller-supplied
           node_id here (node_id is mandatory in this action), so the guard is
           unconditional - naming that project as well must not turn the node id
           back into an existence oracle. */
        list($tprojectId, $tproject) =
            suitMoveProject($db, $user, $owner, 0, true);
        $nodeInfo = suitMoveRequireSuite($db, $nodeId, $tprojectId, 'Suite');

        $oldParentId = intval($nodeInfo['parent_id']);

        // up/down are pure re-orderings: they ignore new_parent_id.
        if ($position === 'up' || $position === 'down') {
            $siblings = suitMoveChildren($db, $oldParentId, $tprojectId);
            $ids = array();
            foreach ($siblings as $s) {
                $ids[] = intval($s['id']);
            }
            $pos = array_search(intval($nodeId), $ids, true);
            if ($pos === false) {
                out(array('status' => 'error', 'code' => 'not_found',
                          'message' => 'Suite is not a child of its container'), 404);
            }
            $swapWith = ($position === 'up') ? $pos - 1 : $pos + 1;
            if ($swapWith < 0 || $swapWith >= count($ids)) {
                out(array('status' => 'no_change',
                          'message' => 'Suite is already at the boundary'));
            }
            $tmp = $ids[$pos];
            $ids[$pos] = $ids[$swapWith];
            $ids[$swapWith] = $tmp;

            $treeMgr = new tree($db);
            $treeMgr->change_order_bulk($ids);

            out(array(
                'status'    => 'ok',
                'changed'   => true,
                'container' => array('id' => $oldParentId),
                'suites'    => suitMoveChildren($db, $oldParentId, $tprojectId),
            ));
            exit;
        }

        // top/bottom: a real re-parent. The destination must be given.
        if ($newParentId <= 0) {
            /* getInt() maps "abc"/"-1" to 0, so an explicitly supplied but
               unusable destination must be refused HERE: falling back to the
               old parent would silently turn it into an in-container reorder. */
            $rawDest = trim((string)($_REQUEST['new_parent_id'] ?? ''));
            if ($rawDest !== '' && !preg_match('/^\d+$/', $rawDest)) {
                out(array('status' => 'error', 'code' => 'bad_request',
                          'message' => 'Invalid destination test suite'), 400);
            }
            $newParentId = $oldParentId;
        }

        // A no-op is decided on the POSITION IN THE ORDERED CHILD LIST, never
        // on the stored node_order: 2.0.1 assigns the new suite the next
        // free counter value (the first suite of a project can legitimately
        // carry node_order 37), so comparing node_order against 1 refused
        // every legitimate "move to first" on a real installation.
        $oldSiblings = suitMoveChildren($db, $oldParentId, $tprojectId);
        $oldPos = null;
        foreach ($oldSiblings as $i => $s) {
            if (intval($s['id']) === intval($nodeId)) {
                $oldPos = $i;
                break;
            }
        }
        if ($oldPos === null) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Suite is not a child of its container'), 404);
        }
        if ($newParentId == $oldParentId) {
            if (($position === 'bottom' && $oldPos === count($oldSiblings) - 1) ||
                ($position === 'top' && $oldPos === 0)) {
                out(array('status' => 'no_change',
                          'message' => 'The suite is already at that position'));
            }
        }
        $parentInfo = suitMoveNodeInfo($db, $newParentId);
        if (is_null($parentInfo)) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Destination not found'), 404);
        }
        $isProjectRoot = intval($parentInfo['node_type_id']) == suitMoveNodeTypeTestproject($db);
        if (!$isProjectRoot && intval($parentInfo['node_type_id']) != suitMoveNodeTypeTestsuite($db)) {
            out(array('status' => 'error', 'code' => 'bad_request',
                      'message' => 'Destination is not a test suite'), 400);
        }
        if (intval(suitMoveOwningProject($db, $parentInfo)) !== intval($tprojectId)) {
            /* 404 for the same existence-leak reason as the moved node: a 403
               here tells the caller that the destination id exists inside a
               project they have no rights on. */
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Destination has no owning test project'), 404);
        }

        // Cycle guard: a suite cannot become its own descendant, which would
        // detach the whole subtree from the project root (and make the subtree
        // unreachable for every later request, since ownership is proved by
        // walking parent_id up to the project node). The DESTINATION's
        // ancestor chain is the one that must be walked - the moved node's own
        // chain only ever contains its ancestors and can never contain the
        // destination. The legacy endpoint had no such check at all.
        if ($newParentId == $nodeId) {
            out(array('status' => 'error', 'code' => 'cycle',
                      'message' => 'A suite cannot be moved inside itself'), 409);
        }
        foreach (suitMoveChain($db, $newParentId) as $anc) {
            if (intval($anc['id']) === intval($nodeId)) {
                out(array('status' => 'error', 'code' => 'cycle',
                          'message' => 'A suite cannot be moved inside one of its own sub-suites'), 409);
            }
        }

        $treeMgr = new tree($db);

        // change_parent() is the legacy primitive; it only rewrites parent_id, so
        // the ordering of the new parent is fixed right afterwards with the very
        // same legacy reorder primitive, keeping node_order dense 1..n on both
        // sides (legacy left holes behind, which made the next drag ambiguous).
        $treeMgr->change_parent($nodeId, $newParentId);

        $siblings = suitMoveChildren($db, $newParentId, $tprojectId);
        $ids = array();
        foreach ($siblings as $s) {
            $ids[] = intval($s['id']);
        }
        $pos = array_search(intval($nodeId), $ids, true);
        if ($pos !== false) {
            $ids = array_values($ids);
            array_splice($ids, $pos, 1);
            if ($position === 'top') {
                array_unshift($ids, intval($nodeId));
            } else {
                $ids[] = intval($nodeId);
            }
            $treeMgr->change_order_bulk($ids);
        }

        // Keep the source container's numbering dense too.
        if ($oldParentId != $newParentId && $oldParentId > 0) {
            suitRenumberSuites($db, $oldParentId, $tprojectId);
        }

        out(array(
            'status'    => 'ok',
            'changed'   => true,
            'container' => array('id' => $newParentId),
            'suites'    => suitMoveChildren($db, $newParentId, $tprojectId),
        ));
        break;
    }

    // ------------------------------------------------------------- reorder
    case 'reorder': {
        $tprojectId = getInt('tproject_id', 0);
        $containerId = getInt('container_id', 0);
        $nodelist = getStr('nodelist', '');

        if ($containerId <= 0) {
            out(array('status' => 'error', 'code' => 'bad_request',
                      'message' => 'Missing container_id'), 400);
        }
        $ids = array();
        foreach (explode(',', $nodelist) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (!preg_match('/^\d+$/', $raw)) {
                out(array('status' => 'error', 'code' => 'bad_request',
                          'message' => 'Invalid node list'), 400);
            }
            $ids[] = intval($raw);
        }
        if (count($ids) < 2) {
            out(array('status' => 'error', 'code' => 'bad_request',
                      'message' => 'A reorder needs at least two suites'), 400);
        }
        if (count(array_unique($ids)) !== count($ids)) {
            out(array('status' => 'error', 'code' => 'bad_request',
                      'message' => 'The node list contains duplicates'), 400);
        }

        /* Refs #1759/#1779: same reason as the init action - the resolver
           arms its own leak guard from the caller-supplied container id. */
        list($tprojectId, $tproject) =
            suitMoveProject($db, $user, $tprojectId, $containerId);

        $current = suitMoveChildren($db, $containerId, $tprojectId);
        // Two different orders are needed: the TREE order (to prove the write
        // actually changed something) and the id-sorted list (to prove the
        // submitted list is exactly the child set). Comparing the tree order
        // against a SORTED list reported every real reorder as a no-op whenever
        // the new order happened to be id-sorted.
        $currentOrder = array();
        $currentIds = array();
        foreach ($current as $i => $c) {
            $currentOrder[$i] = intval($c['id']);
            $currentIds[] = intval($c['id']);
        }
        sort($currentIds);

        $submitted = $ids;
        sort($submitted);
        if ($currentIds !== $submitted) {
            out(array('status' => 'error', 'code' => 'bad_request',
                      'message' => 'The node list is not the exact child list of the container'), 400);
        }

        $newOrder = array();
        foreach ($ids as $i => $id) {
            suitMoveRequireSuite($db, $id, $tprojectId, 'Suite');
            $newOrder[$i] = $id;
        }

        /* No-op decided BEFORE the write: answering after change_order_bulk()
           reported a failure for a request that had already mutated the DB. */
        if ($newOrder === $currentOrder) {
            out(array('status' => 'no_change', 'message' => 'The order did not change'));
        }

        $treeMgr = new tree($db);
        $treeMgr->change_order_bulk($newOrder);

        $after = suitMoveChildren($db, $containerId, $tprojectId);
        $afterIds = array();
        foreach ($after as $c) {
            $afterIds[] = intval($c['id']);
        }
        out(array(
            'status'    => 'ok',
            'changed'   => true,
            'container' => array('id' => $containerId),
            'suites'    => $after,
        ));
        break;
    }

    default:
        out(array('status' => 'error', 'code' => 'unknown_action',
                  'message' => 'Unknown action'), 400);
}
