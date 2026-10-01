<?php
/**
 * Test Case Tree Navigator BFF API
 * URL: /api/tcprojecttree/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Modern, rights-checked replacement of the 1.9.20 test-case tree loader
 * lib/ajax/gettprojectnodes.php (Refs #1770).
 *
 * Legacy behaviour being ported
 * -----------------------------
 * lib/ajax/gettprojectnodes.php (221 lines) was the ExtJS lazy loader behind
 * tlTestCaseFilterControl::build_tree_menu(), i.e. the LEFT FRAME of the 1.9.20
 * work areas Add/Remove Test Cases (planAddTC_m1.tpl), Update Test Plan TC
 * assignments (planUpdateTC.tpl), Test Urgency (planUrgency.tpl) and Execution
 * Assignment (tc_exec_assignment.tpl).
 *
 * Contract of the loader, node by node:
 *   ?root_node=<tproject_id>[&node=<expanded id>][&filter_node=<id>]
 *              [&show_tcases=1|0][&tcprefix=P][&operation=manage|print]
 *   -> JSON array of ExtJS tree nodes, children of <node> (default: the project
 *      node itself), each with text / id / leaf / cls / position /
 *      testlink_node_type / testlink_node_name / forbidden_parent / href.
 *
 * The tree shape:
 *   - every child of the expanded node whose node_type_id is NOT one of
 *     testcase_version (4), testplan (5), requirement_spec (6), requirement (8)
 *     and - when &show_tcases=0 - not testcase (3);
 *   - ordered by nodes_hierarchy.node_order;
 *   - a testproject / testsuite node is a folder (leaf = false) and gets a
 *     recursive test case count appended to its label: "Name (n)";
 *   - a testcase node is a leaf, and - when config treemenu_show_testcase_id is
 *     on - its label is prefixed with "<tcprefix><tc_external_id>:";
 *   - &filter_node=<id> restricts the ROOT children to that single node (the
 *     "filter by node" gesture of the legacy workframes);
 *   - the ExtJS href (EP / ETS / ET, or TPROJECT_PTP / TPROJECT_PTS for
 *     &operation=print) is replaced here by real deep links into the modern
 *     Dashio screens.
 *
 * Security holes inherited from the loader (closed here)
 * -----------------------------------------------------
 * 1. lib/ajax/gettprojectnodes.php performed NO rights check at all - only
 *    testlinkInitPage(), which validates the SESSION. root_node / node /
 *    filter_node came straight out of $_REQUEST and the SQL only filtered on
 *    `NHA.parent_id = <parent>`, with no project scope and no node-type gate, so
 *    any authenticated user could read the suite names, test case names and
 *    tc_external_ids of ANY test project in the installation (same class as
 *    bug #1696 on getrequirementnodes.php and #1695's loader, and the sibling
 *    of the coverage loader recorded in #1765). Here the rights are mgt_view_tc
 *    OR mgt_modify_tc on the addressed project, checked BEFORE the project is
 *    resolved (so a bogus id and a foreign id both answer an identical 403 and
 *    the endpoint is not a test-project-id oracle), and every node id is proven
 *    to live under that same project root before any name is returned.
 * 2. The loader echoed htmlspecialchars()'d names into an ExtJS node `text`
 *    field that ExtJS renders as HTML, so the escaping was load-bearing. This
 *    BFF returns names as DATA; the modern screen escapes them itself.
 * 3. getAllTCasesID() built its `parent_id IN (...)` list by string
 *    concatenation of ids read from the DB and recursed on it - unbounded work
 *    driven by the size of the whole specification. Here the count is a single
 *    recursive CTE per request and the recursion is depth-bounded.
 *
 * node_type_id map (node_types table): 1 testproject, 2 testsuite,
 * 3 testcase, 4 testcase_version, 5 testplan, 6 requirement_spec,
 * 7 requirement, 8 requirement_version.
 * Note (Refs #1660 / #1678): nodes_hierarchy.parent_id is NULLABLE, so every
 * walk has to treat a NULL / 0 parent as "root reached".
 *
 * Endpoints (JSON in/out, GET only - this screen is READ-ONLY on purpose: the
 * write gestures live in gui/templates/testcases/tcReorder.html (#1660) and
 * gui/templates/testcases/containerMoveTC.html (#1724), whose legacy
 * drag-and-drop sources are now non-mutating shims):
 *   GET ?action=projects
 *        -> the test projects the caller may read, so the screen can offer a
 *           readable-project switcher (the legacy frame always used the session
 *           project and had no switcher at all).
 *   GET ?action=init&tproject_id=N[&filter_node=M][&show_tcases=0|1]
 *        -> context (project, prefix, counters, user, treemenu_show_testcase_id)
 *           + the project node + its top-level suites.
 *   GET ?action=children&tproject_id=N&node_id=M[&filter_node=K][&show_tcases=0|1]
 *        -> the lazy-load contract of lib/ajax/gettprojectnodes.php: the
 *           children of M, proven to be a test suite (or the project node) of N.
 *
 * Status codes: 200 ok / 400 bad param / 401 anonymous or expired session,
 * 403 no right, 404 unknown project or foreign node, 405 wrong verb,
 * 500 guarded.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../config_db.inc.php');
require_once('common.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data) { echo json_encode($data); exit; }

function failOut($code, $message, $machine = '')
{
    http_response_code($code);
    $p = array('status' => 'error', 'message' => $message);
    if ($machine !== '') { $p['code'] = $machine; }
    out($p);
}

set_exception_handler(function ($e) {
    error_log('api/tcprojecttree: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(array('status' => 'error', 'code' => 'server_error',
        'message' => 'Internal error'));
    exit;
});

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    http_response_code(401);
    out(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'Not authenticated'));
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'User not found'));
}

// Refs #1614: the legacy loader went through testlinkInitPage(), which runs
// checkSessionValid() on EVERY request, so a stale session was bounced to
// login.php?note=expired. A BFF must answer instead of redirecting.
bffEnforceSession($db);

$tprojMgr = new testproject($db);

define('NODE_TYPE_TESTPROJECT', 1);
define('NODE_TYPE_TESTSUITE',  2);
define('NODE_TYPE_TESTCASE',   3);

/**
 * node_types descriptions the legacy loader excluded from the tree:
 * testcase_version (4), testplan (5), requirement_spec (6),
 * requirement_version (8).
 */
$excludedWhere = '(4, 5, 6, 8)';

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : 'init';

function param($key, $default = 0)
{
    return array_key_exists($key, $_REQUEST) ? $_REQUEST[$key] : $default;
}

/**
 * Strict positive id parameter. A non-numeric or non-positive value is a caller
 * bug, never something to degrade silently into "use the session project" - that
 * is how a stale deep link turns into a read of the wrong project.
 */
function needIntParam($key, $machine)
{
    if (!array_key_exists($key, $_REQUEST)) {
        failOut(400, 'Missing ' . $key, 'missing_' . $machine);
    }
    $raw = trim((string)$_REQUEST[$key]);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        failOut(400, 'Invalid ' . $key, 'invalid_' . $machine);
    }
    $v = intval($raw);
    if ($v <= 0) {
        failOut(400, 'Invalid ' . $key, 'invalid_' . $machine);
    }
    return $v;
}

function canViewTc($user, $tproject_id)
{
    return (bool)$user->hasRight($GLOBALS['db'], 'mgt_view_tc', $tproject_id) ||
           (bool)$user->hasRight($GLOBALS['db'], 'mgt_modify_tc', $tproject_id);
}

/**
 * Validate the requested test project id WITHOUT disclosing whether it exists.
 *
 * The rights check runs BEFORE the existence lookup: with the lookup first, a
 * bogus id answered 404 and a foreign real one answered 403, which lets ANY
 * authenticated user enumerate the ids of every test project. Here both answer
 * the same 403 no_right, and only a caller who may read test cases ever learns
 * that the project is missing. (Refs #1697, same trap as api/reqspectreelist.)
 */
function needTprojectId()
{
    global $tprojMgr, $user;
    $tid = needIntParam('tproject_id', 'tproject');
    if (!canViewTc($user, $tid)) {
        failOut(403, 'You are not authorized to view test cases', 'no_right');
    }
    $proj = $tprojMgr->get_by_id($tid);
    if (is_null($proj) || !isset($proj['name'])) {
        failOut(404, 'Test project does not exist', 'tproject_not_found');
    }
    return $tid;
}

/**
 * Root test project id of an arbitrary hierarchy node, walking parent_id up.
 *
 * This is the ownership proof the legacy loader never performed. parent_id is
 * NULLABLE in 2.0.1 (Refs #1660) and NULL / 0 means "root reached" (Refs #1678),
 * so the walk stops on both. Returns 0 when the chain is broken or cyclic.
 */
function rootProjectIdOf($nodeId)
{
    global $db;
    $current = intval($nodeId);
    $guard = 0;
    while ($current > 0 && $guard < 64) {
        $rows = $db->get_recordset(
            'SELECT node_type_id, parent_id FROM nodes_hierarchy WHERE id = ' . $current);
        if (!$rows || !$rows[0]) {
            return 0;
        }
        if (intval($rows[0]['node_type_id']) === NODE_TYPE_TESTPROJECT) {
            return $current;
        }
        $current = intval($rows[0]['parent_id']);
        $guard++;
    }
    return 0;
}

/**
 * A node id must be a real test project / test suite / test case node whose ROOT
 * ancestor is the addressed test project. A foreign node of a foreign project
 * fails exactly like a non-existent node (identical 404), so the endpoint is
 * not a node-existence oracle either.
 */
function needOwnedNode($nodeId, $tproject_id, $allowedTypes)
{
    $nid = intval($nodeId);
    if ($nid <= 0) {
        failOut(400, 'Invalid node id', 'invalid_node');
    }
    $rows = $GLOBALS['db']->get_recordset(
        'SELECT node_type_id FROM nodes_hierarchy WHERE id = ' . $nid);
    if (!$rows || !$rows[0]) {
        failOut(404, 'Node not found', 'node_not_found');
    }
    if (!in_array(intval($rows[0]['node_type_id']), $allowedTypes, true)) {
        failOut(404, 'Node not found', 'node_not_found');
    }
    if (rootProjectIdOf($nid) !== intval($tproject_id)) {
        failOut(404, 'Node not found', 'node_not_found');
    }
    return $nid;
}

/** &show_tcases is a legacy boolean: absent / 1 = with test cases, 0 = without. */
function showTestCases()
{
    if (!array_key_exists('show_tcases', $_REQUEST)) {
        return 1;
    }
    $raw = trim((string)$_REQUEST['show_tcases']);
    if ($raw === '') {
        return 1;
    }
    if (!preg_match('/^[01]$/', $raw)) {
        failOut(400, 'Invalid show_tcases', 'invalid_show_tcases');
    }
    return intval($raw);
}

/** Legacy filter_node: restrict the ROOT children to a single node id. */
function filterNodeId()
{
    if (!array_key_exists('filter_node', $_REQUEST)) {
        return 0;
    }
    $raw = trim((string)$_REQUEST['filter_node']);
    if ($raw === '') {
        return 0;
    }
    if (!preg_match('/^[0-9]+$/', $raw)) {
        failOut(400, 'Invalid filter_node', 'invalid_filter_node');
    }
    $v = intval($raw);
    return ($v > 0) ? $v : 0;
}

/**
 * Recursive test case count per suite - the "(n)" the legacy loader appended to
 * every folder label via getAllTCasesID(). One recursive CTE per request
 * instead of one query per level per suite.
 */
function suiteCaseCounts($parentId)
{
    global $db;
    $pid = intval($parentId);
    if ($pid <= 0) {
        return array();
    }
    $rows = $db->get_recordset(
        'WITH RECURSIVE subtree AS (' .
        '  SELECT NH.id, NH.parent_id, NH.node_type_id' .
        '    FROM nodes_hierarchy NH' .
        '   WHERE NH.parent_id = ' . $pid .
        '     AND NH.node_type_id IN (' . NODE_TYPE_TESTSUITE . ', ' . NODE_TYPE_TESTCASE . ')' .
        '  UNION ALL' .
        '  SELECT NH.id, NH.parent_id, NH.node_type_id' .
        '    FROM nodes_hierarchy NH' .
        '    JOIN subtree S ON NH.parent_id = S.id' .
        '   WHERE NH.node_type_id IN (' . NODE_TYPE_TESTSUITE . ', ' . NODE_TYPE_TESTCASE . ')' .
        ')' .
        ' SELECT id, node_type_id FROM subtree');

    $cases = 0;
    $suites = array();
    foreach (($rows ? $rows : array()) as $r) {
        if (intval($r['node_type_id']) === NODE_TYPE_TESTCASE) {
            $cases++;
        } else {
            $suites[] = intval($r['id']);
        }
    }
    if (!$suites) {
        return array($pid => $cases);
    }
    $out = array($pid => $cases);
    foreach ($suites as $sid) {
        $sub = suiteCaseCounts($sid);
        foreach ($sub as $k => $v) {
            $out[$k] = $v;
        }
    }
    return $out;
}

/**
 * Latest-version tc_external_id per test case node of the displayed level.
 *
 * The legacy SELECT was "SELECT DISTINCT tc_external_id, NHA.parent_id ...
 * WHERE NHB.parent_id = <parent> AND NHA.node_type_id = 4" consumed by
 * fetchRowsIntoMap(..., 'parent_id'), i.e. the FIRST row per test case - which
 * MariaDB is free to pick from any of its versions. This resolves the highest
 * version id per test case, so the label is deterministic.
 */
function latestExternalIds($parentId)
{
    global $db;
    $pid = intval($parentId);
    if ($pid <= 0) {
        return array();
    }
    $rows = $db->get_recordset(
        'SELECT V.parent_id AS tcase_id, TCV.tc_external_id' .
        ' FROM tcversions TCV' .
        ' JOIN nodes_hierarchy V ON V.id = TCV.id' .
        '   AND V.node_type_id = ' . (NODE_TYPE_TESTCASE + 1) .
        ' WHERE V.parent_id = ' . $pid .
        '   AND V.id = (SELECT MAX(V2.id) FROM tcversions TCV2' .
        '                 JOIN nodes_hierarchy V2 ON V2.id = TCV2.id' .
        '                WHERE V2.parent_id = V.parent_id)');

    $out = array();
    foreach (($rows ? $rows : array()) as $r) {
        $out[intval($r['tcase_id'])] = (string)$r['tc_external_id'];
    }
    return $out;
}

/** Deep link into the modern screens, replacing the legacy ExtJS href. */
function nodeLinks($type, $id, $tproject_id)
{
    if ($type === 'testsuite') {
        return array(
            'open' => '/gui/templates/testcases/testSpec.html?container_id=' . intval($id)
                . '&tproject_id=' . intval($tproject_id),
            'type_label' => 'testsuite',
        );
    }
    if ($type === 'testcase') {
        return array(
            'open' => '/gui/templates/testcases/tcView.html?tcase_id=' . intval($id)
                . '&tproject_id=' . intval($tproject_id),
            'type_label' => 'testcase',
        );
    }
    return array('open' => '', 'type_label' => 'testproject');
}

/**
 * Children of a display node - the lazy-load answer, node by node faithful to
 * display_children() in the legacy loader.
 *
 * $parentId is already proven to belong to the addressed project by
 * needOwnedNode(); $rootId is the project id (needed for the deep links and for
 * the legacy filter_node rule, which only applies at the root level).
 */
function nodeChildren($parentId, $rootId, $withCases, $filterNode, $showCaseIds, $prefix)
{
    global $db, $excludedWhere;

    $pid = intval($parentId);
    $rootId = intval($rootId);

    $sql = 'SELECT NH.id, NH.parent_id, NH.node_type_id, NH.name, NH.node_order' .
           ' FROM nodes_hierarchy NH' .
           ' WHERE NH.parent_id = ' . $pid .
           '   AND NH.node_type_id NOT IN ' . $excludedWhere;
    if (!$withCases) {
        $sql .= ' AND NH.node_type_id <> ' . NODE_TYPE_TESTCASE;
    }
    // Legacy rule: filter_node narrows the ROOT children only.
    if ($filterNode > 0 && $pid === $rootId) {
        $sql .= ' AND NH.id = ' . intval($filterNode);
    }
    $sql .= ' ORDER BY NH.node_order ASC, NH.id ASC';

    $rows = $db->get_recordset($sql);
    if (!$rows) {
        return array();
    }

    $counts = suiteCaseCounts($pid);
    $externals = $withCases ? latestExternalIds($pid) : array();

    $out = array();
    foreach ($rows as $r) {
        $ntype = intval($r['node_type_id']);
        $nid = intval($r['id']);
        $name = (string)$r['name'];

        if ($ntype === NODE_TYPE_TESTPROJECT) {
            $type = 'testproject';
        } elseif ($ntype === NODE_TYPE_TESTSUITE) {
            $type = 'testsuite';
        } elseif ($ntype === NODE_TYPE_TESTCASE) {
            $type = 'testcase';
        } else {
            // A node type the legacy tree could carry but never branch (e.g. a
            // re-parented container under child_requirements_mgmt): keep it
            // visible rather than dropping it, but it is not a suite nor a case.
            $type = 'node';
        }

        $entry = array(
            'id'            => $nid,
            'parent_id'     => intval($r['parent_id']),
            'node_type_id'  => $ntype,
            'node_type'     => $type,
            'name'          => $name,
            'label'         => $name,
            'node_order'    => intval($r['node_order']),
            'leaf'          => ($type === 'testcase') ? 1 : 0,
            'cls'           => ($type === 'testcase') ? 'leaf' : 'folder',
            // Legacy: testproject / testsuite / testcase were the only
            // forbidden_parent values; kept so the payload is comparable.
            'forbidden_parent' => 'none',
            'tcase_qty'     => ($type === 'testsuite')
                ? (isset($counts[$nid]) ? intval($counts[$nid]) : 0) : null,
            'tc_external_id' => null,
        );

        if ($type === 'testcase') {
            $ext = isset($externals[$nid]) ? $externals[$nid] : '';
            $entry['tc_external_id'] = $ext;
            if ($showCaseIds) {
                $entry['label'] = $prefix . $ext . ':' . $name;
            }
        }

        $links = nodeLinks($type, $nid, $rootId);
        $entry['open_url'] = $links['open'];
        $entry['open_label_key'] = 'tcpt.open_' . $links['type_label'];

        $out[] = $entry;
    }
    return $out;
}

/** Total number of suite and test case nodes under a project root. */
function projectTotals($tproject_id)
{
    global $db;
    $counts = suiteCaseCounts(intval($tproject_id));
    $suites = 0;
    $cases = 0;
    foreach ($counts as $k => $v) {
        if ($k === intval($tproject_id)) {
            $cases = intval($v);
        } else {
            $suites++;
        }
    }
    return array('total_suites' => $suites, 'total_cases' => $cases);
}

// ================================================================ init ======
if ($action === 'init') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $tproject_id = needTprojectId();
    $proj = $tprojMgr->get_by_id($tproject_id);
    $withCases = showTestCases();
    $filterNode = filterNodeId();
    $prefix = (string)(isset($proj['prefix']) ? $proj['prefix'] : '');
    // Legacy static $showTestCaseID read the SAME cfg key for every node.
    $showCaseIds = config_get('treemenu_show_testcase_id') ? 1 : 0;

    // The legacy tree root was the project node itself (a node_type_id = 1 row
    // that can also appear re-parented under a requirement container - Refs
    // #1699 - so its existence is proven by id + type, not by parent_id).
    $rootRows = $db->get_recordset(
        'SELECT id, name, node_type_id FROM nodes_hierarchy WHERE id = ' . $tproject_id);
    if (!$rootRows || !$rootRows[0] ||
        intval($rootRows[0]['node_type_id']) !== NODE_TYPE_TESTPROJECT) {
        failOut(404, 'Test project does not exist', 'tproject_not_found');
    }

    $totals = projectTotals($tproject_id);
    $children = nodeChildren($tproject_id, $tproject_id, $withCases, $filterNode,
        $showCaseIds, $prefix);

    out(array(
        'status'  => 'ok',
        'context' => array(
            'tproject_id'          => $tproject_id,
            'tproject_name'        => (string)$proj['name'],
            'prefix'               => $prefix,
            'active'               => (isset($proj['active']) && $proj['active'] == 1) ? 1 : 0,
            'user_id'              => $userId,
            'user_login'           => (string)$user->login,
            'total_suites'         => $totals['total_suites'],
            'total_cases'          => $totals['total_cases'],
            'show_tcases'          => $withCases,
            'show_testcase_id'     => $showCaseIds,
            'filter_node'          => $filterNode,
        ),
        'root'    => array(
            'id'          => $tproject_id,
            'name'        => (string)$rootRows[0]['name'],
            'label'       => (string)$rootRows[0]['name'],
            'node_type'   => 'testproject',
            'leaf'        => 0,
            'cls'         => 'folder',
            'open_url'    => '/gui/templates/projects/projectInfoView.html?tproject_id=' . $tproject_id,
            'open_label_key' => 'tcpt.open_testproject',
        ),
        'children' => $children,
        'grant'    => array(
            'view'   => true,
            'modify' => (bool)$user->hasRight($db, 'mgt_modify_tc', $tproject_id),
        ),
    ));
}

// ============================================================ children ======
// The lazy-load answer of lib/ajax/gettprojectnodes.php?node=<id>. The node is
// proven to be the project node or a test suite of the addressed project BEFORE
// any suite / test case name is returned.
if ($action === 'children') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $tproject_id = needTprojectId();
    $nodeId = needOwnedNode(needIntParam('node_id', 'node'), $tproject_id,
        array(NODE_TYPE_TESTPROJECT, NODE_TYPE_TESTSUITE));
    $withCases = showTestCases();
    $filterNode = filterNodeId();
    $proj = $tprojMgr->get_by_id($tproject_id);
    $prefix = (string)(isset($proj['prefix']) ? $proj['prefix'] : '');
    $showCaseIds = config_get('treemenu_show_testcase_id') ? 1 : 0;

    out(array(
        'status'   => 'ok',
        'node_id'  => $nodeId,
        'children' => nodeChildren($nodeId, $tproject_id, $withCases, $filterNode,
            $showCaseIds, $prefix),
    ));
}

// ============================================================ filter ========
// The modern equivalent of the legacy &filter_node gesture: restrict the tree to
// one container. Answered separately so the screen can validate the pick before
// it reloads the tree (and so an invalid pick is a 404, never a silently empty
// tree). The node is proven to be a suite of the addressed project.
if ($action === 'filter') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $tproject_id = needTprojectId();
    $nodeId = needOwnedNode(needIntParam('node_id', 'node'), $tproject_id,
        array(NODE_TYPE_TESTSUITE));
    $counts = suiteCaseCounts($nodeId);
    out(array(
        'status'  => 'ok',
        'node_id' => $nodeId,
        'label'   => nodeLabel($nodeId, $counts),
        'tcase_qty' => isset($counts[$nodeId]) ? intval($counts[$nodeId]) : 0,
    ));
}

/** "Name (n)" - the label the legacy folder nodes carried. */
function nodeLabel($nodeId, $counts)
{
    global $db;
    $rows = $db->get_recordset('SELECT name FROM nodes_hierarchy WHERE id = ' . intval($nodeId));
    $name = ($rows && $rows[0]) ? (string)$rows[0]['name'] : '';
    $n = isset($counts[$nodeId]) ? intval($counts[$nodeId]) : 0;
    return $name . ' (' . $n . ')';
}

// ============================================================ projects ======
// The legacy frame had no project switcher (it always read
// $_SESSION['testprojectID']). The modern screen gets one, so it needs the list
// of test projects the caller may actually read.
if ($action === 'projects') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $all = $tprojMgr->get_all();
    $sessionTid = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
    $out = array();
    foreach (($all ? $all : array()) as $p) {
        $pid = intval($p['id']);
        if ($pid <= 0) { continue; }
        if (!canViewTc($user, $pid)) { continue; }
        $out[] = array(
            'id'    => $pid,
            'name'  => (string)$p['name'],
            'prefix' => (string)(isset($p['prefix']) ? $p['prefix'] : ''),
            'active' => (isset($p['active']) && $p['active'] == 1) ? 1 : 0,
            'is_current' => ($pid === $sessionTid) ? 1 : 0,
        );
    }
    usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    out(array('status' => 'ok', 'projects' => $out,
        'session_tproject_id' => $sessionTid));
}

failOut(400, 'Unknown action', 'unknown_action');
