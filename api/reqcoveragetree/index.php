<?php
/**
 * Requirement Coverage Tree navigator BFF API
 * URL: /api/reqcoveragetree/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Modern, rights-checked replacement of the 1.9.20 "requirement coverage"
 * ExtJS lazy loader lib/ajax/getreqcoveragenodes.php (Refs #1765).
 *
 * Legacy behaviour being ported
 * -----------------------------
 * lib/functions/tlTestCaseFilterByRequirementControl.class.php built the
 * coverage tree used as the drag-and-drop SOURCE by:
 *   - create test suites / test cases on a test project,
 *   - assign keywords to test cases,
 *   - assign requirements to test cases.
 * The tree was
 *   root   = test project           -> javascript:EP(<tproject_id>)
 *   level2 = requirement spec       -> javascript:ERS(<id>)   ("<doc_id>:<title> (<n>)")
 *   level3 = requirement            -> javascript:ER(<id>)    ("<req_doc_id>:<title>")
 * and every level was lazily loaded from lib/ajax/getreqcoveragenodes.php,
 * which answered a flat JSON array of ExtJS tree nodes (text / id / leaf /
 * cls / testlink_node_type / testlink_node_name / forbidden_parent /
 * position / href).
 *
 * Security holes inherited from the loader (all closed here)
 * ---------------------------------------------------------
 * 1. lib/ajax/getreqcoveragenodes.php called only testlinkInitPage($db) - a
 *    SESSION check, never a RIGHTS check. Any authenticated user (no matter
 *    which role) could read the requirement spec doc_ids/titles and the
 *    requirement doc_ids/titles of ANY test project by passing an arbitrary
 *    root_node / node id, because display_children() filtered on
 *    `NHA.parent_id = intval($parent)` only - no project scope, no node-type
 *    gate. Here the rights are mgt_view_req OR mgt_modify_req on the
 *    ADDRESSED project, and every node id is proven to live inside that same
 *    project before a single name is returned.
 * 2. No node-type gate: the legacy SQL accepted ANY child of the parent node.
 *    Here only requirement_spec (6), the #1699 container nodes (a
 *    node_type_id = 1 testproject re-parented under a specification when
 *    req_cfg->child_requirements_mgmt is on) and requirement (7) are served.
 * 3. The tree advertised drag-and-drop writes onto lib/ajax/dragdroprequirementnodes.php,
 *    which had NO rights / ownership / same-origin check and mutated on GET
 *    (bug #1681, hardened by #1699). This screen is READ-ONLY on purpose: the
 *    write gesture lives in gui/templates/requirements/reqTreeReorder.html
 *    (api/reqtreereorder) and the requirement<->test case assignment lives in
 *    gui/templates/requirements/reqTcBulkAssign.html / reqTcAssign.html.
 *
 * Ownership
 * ---------
 * nodes_hierarchy does not carry the project link (2.0.1 dropped the four
 * columns, see #1660): a specification owns its project through
 * req_specs.testproject_id, and a requirement owns its project through its
 * parent specification (or, with containers enabled, through the spec that
 * holds the container). Every id that reaches SQL here is resolved to an
 * owning project first and compared with the requested one.
 *
 * Coverage
 * --------
 * req_coverage(req_id, req_version_id, testcase_id, tcversion_id,
 * is_active) is the requirement <-> test case assignment table used by
 * requirement_mgr. is_active = 0 marks a link closed by execution, so it is
 * NOT counted as coverage.
 *
 * Endpoints (JSON in/out, GET only - this screen writes nothing):
 *   GET ?action=init&tproject_id=N
 *        -> context + rights + the project node + its specification children
 *           (doc_id, title, requirement count, covered count) + totals.
 *   GET ?action=children&tproject_id=N&node_id=M
 *        -> the lazy-load contract of the legacy loader: the specifications /
 *           containers / requirements below node M (M proven to belong to N).
 *   GET ?action=coverage&tproject_id=N&req_id=R
 *        -> the test case versions covering requirement R (proven to belong
 *           to N), newest first.
 *   GET ?action=projects
 *        -> the requirement-enabled projects the caller may read, for the
 *           screen's project switcher.
 *
 * Status codes: 200 ok / 400 bad param / 401 anonymous or expired session,
 * 403 no right, 404 unknown project or node, 405 wrong verb, 500 guarded.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../config_db.inc.php');
require_once('common.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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
    error_log('api/reqcoveragetree: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(array('status' => 'error', 'code' => 'server_error',
        'message' => 'Internal error'));
    exit;
});

$T = tlObjectWithDB::getDBTables(array(
    'nodes_hierarchy', 'req_specs', 'requirements',
    'req_versions', 'req_coverage', 'testprojects', 'tcversions'));

const NODE_TESTPROJECT = 1;
const NODE_REQ_SPEC = 6;
const NODE_REQUIREMENT = 7;
const NODE_REQ_VERSION = 8;

/** Node row (id, name, node_type_id) or null. */
function loadNode($db, $id)
{
    $T = $GLOBALS['T'];
    $sql = "SELECT id, name, node_type_id, parent_id, node_order"
        . " FROM {$T['nodes_hierarchy']} WHERE id = " . intval($id);
    $rs = $db->exec_query($sql);
    $row = $db->fetch_array($rs);
    // fetch_array() answers FALSE when the rowset is empty,
    // so an unknown id has to be detected with empty() as well.
    if (is_null($row) || empty($row)) { return null; }
    return $row;
}

/**
 * Owning test project of a hierarchy node, or 0 when it cannot be resolved.
 * Walks parent-first-free: a specification resolves through req_specs, a
 * requirement through its parent chain, and the walk is bounded so a cyclic
 * hierarchy cannot hang the request.
 */
function owningProjectId($db, $nodeId)
{
    $T = $GLOBALS['T'];
    $id = intval($nodeId);
    for ($hop = 0; $hop < 64 && $id > 0; $hop++) {
        $node = loadNode($db, $id);
        if (is_null($node)) { return 0; }
        switch (intval($node['node_type_id'])) {
            case NODE_TESTPROJECT:
                // Refs #1699: a node_type_id = 1 node with a parent is a
                // CONTAINER below a specification, not the project itself - the
                // owning project is the one the parent specification belongs
                // to. A real project root has parent_id = 0 and answers for
                // itself.
                if (intval($node['parent_id']) > 0) {
                    $id = intval($node['parent_id']);
                    break;
                }
                return intval($node['id']);
            case NODE_REQ_SPEC:
                $sql = "SELECT testproject_id FROM {$T['req_specs']} WHERE id = " . intval($node['id']);
                $rs = $db->exec_query($sql);
                $row = $db->fetch_array($rs);
                // fetch_array() answers FALSE on an exhausted rowset, so an
                // empty() check is what keeps the events table clean.
                return empty($row) ? 0 : intval($row['testproject_id']);
            case NODE_REQUIREMENT:
            case NODE_REQ_VERSION:
                $id = intval($node['parent_id']);
                break;
            default:
                return 0;
        }
    }
    return 0;
}

/**
 * Project context row or null (name / prefix / requirements flag).
 *
 * The row is read through testproject::get_by_id() because 2.0.1 dropped
 * testprojects.testproject_name (the NAME now lives in nodes_hierarchy) and
 * because `options` is a PHP-serialized blob that only the class API
 * unserializes - a raw SELECT leaves it a string and every option flag would
 * read as disabled (#1376 / #1516 tolerated-reader pattern).
 */
function loadProject($db, $tprojectId)
{
    $tprojMgr = new testproject($db);
    $proj = $tprojMgr->get_by_id(intval($tprojectId));
    if (is_null($proj) || !isset($proj['id'])) {
        return null;
    }
    $name = isset($proj['name']) ? $proj['name'] : '';
    if ($name === '') {
        $node = loadNode($db, $tprojectId);
        $name = is_null($node) ? '' : $node['name'];
    }
    return array(
        'id' => intval($proj['id']),
        'name' => $name,
        'prefix' => isset($proj['prefix']) ? $proj['prefix'] : '',
        'req_enabled' => projFlag($proj, 'requirementsEnabled', 'option_reqs', 1),
        'req_mgr_enabled' => projFlag($proj, 'reqMgrIntegration', 'reqmgr_integration_enabled', 0),
    );
}

/** Tolerant option-flag read (#1376 / #1516 pattern, see api/reqspectreelist). */
function projFlag($proj, $key, $column, $default = 0)
{
    $opt = isset($proj['opt']) ? $proj['opt'] : null;
    if (is_object($opt) && isset($opt->$key)) {
        return intval($opt->$key) > 0 ? 1 : 0;
    }
    if (is_array($opt) && isset($opt[$key])) {
        return intval($opt[$key]) > 0 ? 1 : 0;
    }
    if (isset($proj['options']) && is_object($proj['options']) && isset($proj['options']->$key)) {
        return intval($proj['options']->$key) > 0 ? 1 : 0;
    }
    return intval(isset($proj[$column]) ? $proj[$column] : $default) > 0 ? 1 : 0;
}

/**
 * The parent ids whose requirements belong to a node: the node itself plus, for
 * a specification, every #1699 container it parents (their requirements are
 * listed under the container node but still count for the specification).
 */
function statsParentIds($db, $nodeId, $nodeTypeId, $allowContainers)
{
    $ids = array(intval($nodeId));
    if (intval($nodeTypeId) !== NODE_REQ_SPEC || !$allowContainers) {
        return $ids;
    }
    $T = $GLOBALS['T'];
    $rs = $db->exec_query(
        "SELECT id FROM {$T['nodes_hierarchy']}"
        . " WHERE parent_id = " . intval($nodeId)
        . " AND node_type_id = " . NODE_TESTPROJECT);
    while ($row = $db->fetch_array($rs)) {
        $ids[] = intval($row['id']);
    }
    return $ids;
}

/** Per-specification requirement totals + how many of them are covered. */
function specStats($db, $specId, $allowContainers = null, $nodeTypeId = NODE_REQ_SPEC)
{
    $T = $GLOBALS['T'];
    if (is_null($allowContainers)) { $allowContainers = childRequirementsMgmtEnabled(); }
    $parents = statsParentIds($db, $specId, $nodeTypeId, $allowContainers);
    $in = implode(',', array_map('intval', $parents));
    $sql = "SELECT REQ.id AS req_id"
        . " FROM {$T['nodes_hierarchy']} NH"
        . " JOIN {$T['requirements']} REQ ON REQ.id = NH.id"
        . " WHERE NH.parent_id IN (" . $in . ")"
        . " AND NH.node_type_id = " . NODE_REQUIREMENT;
    $rs = $db->exec_query($sql);
    $total = 0;
    $covered = 0;
    while ($row = $db->fetch_array($rs)) {
        $total++;
        if (activeCoverageCount($db, intval($row['req_id'])) > 0) { $covered++; }
    }
    return array('total' => $total, 'covered' => $covered);
}

/** Distinct ACTIVE test case versions covering a requirement. */
function activeCoverageCount($db, $reqId)
{
    $T = $GLOBALS['T'];
    $sql = "SELECT COUNT(DISTINCT tcversion_id) AS qty FROM {$T['req_coverage']}"
        . " WHERE req_id = " . intval($reqId) . " AND is_active = 1";
    $rs = $db->exec_query($sql);
    $row = $db->fetch_array($rs);
    return is_null($row) ? 0 : intval($row['qty']);
}

/**
 * req_cfg->child_requirements_mgmt (config.inc.php:1689, ENABLED by default).
 * When it is on, a TEST PROJECT node can be re-parented under a specification
 * and requirements hang off that container (#1699) - the legacy loader listed
 * such containers. The modern tree offers the container branch only when the
 * flag is on, so the shape on screen is always a supported one.
 */
function childRequirementsMgmtEnabled()
{
    $cfg = config_get('req_cfg');
    $enabled = (is_object($cfg) && isset($cfg->child_requirements_mgmt))
        ? $cfg->child_requirements_mgmt : ENABLED;
    return intval($enabled) > 0 ? 1 : 0;
}

/**
 * Children of a node, restricted to the node types the coverage tree shows.
 * $projectId is used only to keep the result set project scoped; ownership of
 * $parentId has already been proven by the caller.
 */
function children($db, $parentId)
{
    $T = $GLOBALS['T'];
    $items = array();
    $allowContainers = childRequirementsMgmtEnabled();

    // specifications + #1699 containers (node_type_id = 1) directly below
    $sql = "SELECT NH.id, NH.name, NH.node_type_id, NH.node_order, RSPEC.doc_id"
        . " FROM {$T['nodes_hierarchy']} NH"
        . " LEFT JOIN {$T['req_specs']} RSPEC ON RSPEC.id = NH.id"
        . " WHERE NH.parent_id = " . intval($parentId)
        . " AND NH.node_type_id IN (" . NODE_REQ_SPEC . ',' . NODE_TESTPROJECT . ")"
        . " ORDER BY NH.node_order";
    $rs = $db->exec_query($sql);
    while ($row = $db->fetch_array($rs)) {
        if (intval($row['node_type_id']) !== NODE_REQ_SPEC && !$allowContainers) {
            continue;
        }
        $stats = specStats($db, intval($row['id']), $allowContainers, intval($row['node_type_id']));
        $items[] = array(
            'id' => intval($row['id']),
            'kind' => intval($row['node_type_id']) === NODE_REQ_SPEC ? 'spec' : 'container',
            'doc_id' => is_null($row['doc_id']) ? '' : $row['doc_id'],
            'text' => is_null($row['doc_id']) ? $row['name'] : $row['doc_id'] . ': ' . $row['name'],
            'requirement_count' => $stats['total'],
            'covered_count' => $stats['covered'],
            'has_children' => true,
        );
    }

    // requirements directly below
    $sql = "SELECT NH.id, NH.name, NH.node_order, REQ.req_doc_id"
        . " FROM {$T['nodes_hierarchy']} NH"
        . " JOIN {$T['requirements']} REQ ON REQ.id = NH.id"
        . " WHERE NH.parent_id = " . intval($parentId)
        . " AND NH.node_type_id = " . NODE_REQUIREMENT
        . " ORDER BY NH.node_order";
    $rs = $db->exec_query($sql);
    while ($row = $db->fetch_array($rs)) {
        $qty = activeCoverageCount($db, intval($row['id']));
        $items[] = array(
            'id' => intval($row['id']),
            'kind' => 'requirement',
            'doc_id' => is_null($row['req_doc_id']) ? '' : $row['req_doc_id'],
            'text' => $row['name'],
            'covered_count' => $qty,
            'has_children' => $qty > 0,
        );
    }

    return $items;
}

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
        'message' => 'Not authenticated'));
}

// A tab left open past the inactivity timeout must stop reading data.
bffEnforceSession($db);

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    failOut(405, 'Method not allowed', 'method_not_allowed');
}

$action = isset($_GET['action']) ? trim($_GET['action']) : 'init';
if ($action === '') { $action = 'init'; }

$action = preg_replace('/[^a-z_]/', '', $action);

/**
 * Rights gate. Checked on the REQUESTED project BEFORE the project is
 * resolved, so a foreign or non-existent id answers the same 403 and the
 * endpoint is not a test-project existence oracle (#1697).
 */
function gateProject($db, $user, $tprojectId)
{
    if ($tprojectId <= 0) {
        failOut(400, 'Missing or invalid tproject_id', 'invalid_tproject_id');
    }
    // hasRight() may answer the string "yes" - normalize to a real bool.
    $view = $user->hasRight($db, 'mgt_view_req', $tprojectId) ? true : false;
    $modify = $user->hasRight($db, 'mgt_modify_req', $tprojectId) ? true : false;
    if (!$view && !$modify) {
        failOut(403, 'You do not have permission to read requirements',
            'no_right_req_view');
    }
    return array('view' => $view, 'modify' => $modify);
}

if ($action === 'projects') {
    $tprojMgr = new testproject($db);
    // NB: testproject::get_all() only honours filters['active'] - the sort keys
    // would be silently ignored, and without 'active' a deleted/disabled test
    // project would be offered in the switcher.
    $rows = $tprojMgr->get_all(array('active' => 1));
    $list = array();
    foreach ((array)$rows as $row) {
        if (!isset($row['id'])) { continue; }
        $id = intval($row['id']);
        if (!$user->hasRight($db, 'mgt_view_req', $id)
            && !$user->hasRight($db, 'mgt_modify_req', $id)) {
            continue;
        }
        $list[] = array(
            'id' => $id,
            'name' => isset($row['name']) ? $row['name'] : '',
            'prefix' => isset($row['prefix']) ? $row['prefix'] : '',
            'req_enabled' => projFlag($row, 'requirementsEnabled', 'option_reqs', 1),
        );
    }
    out(array('status' => 'ok', 'action' => 'projects', 'projects' => $list));
}

$tprojectId = isset($_GET['tproject_id']) ? intval($_GET['tproject_id']) : 0;
$rights = gateProject($db, $user, $tprojectId);

if ($action === 'init') {
    $project = loadProject($db, $tprojectId);
    if (is_null($project)) {
        failOut(404, 'Unknown test project', 'unknown_project');
    }
    if (!$project['req_enabled']) {
        failOut(400, 'Requirements are disabled for this test project',
            'requirements_disabled');
    }

    $nodes = children($db, $tprojectId);
    $hasContainers = false;
    $specCount = 0;
    $reqCount = 0;
    $covered = 0;
    foreach ($nodes as $n) {
        if ($n['kind'] === 'requirement') {
            $reqCount++;
            if ($n['covered_count'] > 0) { $covered++; }
        } else {
            $specCount++;
            $reqCount += $n['requirement_count'];
            $covered += $n['covered_count'];
        }
    }
    if (childRequirementsMgmtEnabled()) {
        $rs = $db->exec_query(
            "SELECT COUNT(1) AS c FROM {$GLOBALS['T']['nodes_hierarchy']} CONT"
            . " JOIN {$GLOBALS['T']['req_specs']} RSPEC ON RSPEC.id = CONT.parent_id"
            . " WHERE CONT.node_type_id = " . NODE_TESTPROJECT
            . " AND RSPEC.testproject_id = " . intval($tprojectId));
        $crow = $db->fetch_array($rs);
        $hasContainers = ($crow && intval($crow['c']) > 0);
    }

    out(array(
        'status' => 'ok',
        'action' => 'init',
        'project' => $project,
        'rights' => array('view' => $rights['view'], 'modify' => $rights['modify']),
        'user' => isset($user->login) ? $user->login : '',
        'totals' => array(
            'specifications' => $specCount,
            'requirements' => $reqCount,
            'covered' => $covered,
            'uncovered' => max(0, $reqCount - $covered),
        ),
        'has_containers' => $hasContainers,
        'nodes' => $nodes,
    ));
}

if ($action === 'children') {
    $nodeId = isset($_GET['node_id']) ? intval($_GET['node_id']) : 0;
    if ($nodeId <= 0) {
        failOut(400, 'Missing or invalid node_id', 'invalid_node_id');
    }
    $node = loadNode($db, $nodeId);
    if (is_null($node)) {
        failOut(404, 'Unknown node', 'unknown_node');
    }
    // Ownership FIRST, then the type gate, and both failures answer the same
    // 404: a 400 "unsupported node type" would tell a caller with rights on
    // one project whether an arbitrary node id exists elsewhere.
    if (owningProjectId($db, $nodeId) !== $tprojectId) {
        failOut(404, 'Unknown node', 'unknown_node');
    }
    $typeId = intval($node['node_type_id']);
    if ($typeId !== NODE_TESTPROJECT && $typeId !== NODE_REQ_SPEC) {
        failOut(404, 'Unknown node', 'unknown_node');
    }
    out(array(
        'status' => 'ok',
        'action' => 'children',
        'node_id' => $nodeId,
        'nodes' => children($db, $nodeId),
    ));
}

if ($action === 'coverage') {
    $reqId = isset($_GET['req_id']) ? intval($_GET['req_id']) : 0;
    if ($reqId <= 0) {
        failOut(400, 'Missing or invalid req_id', 'invalid_req_id');
    }
    $node = loadNode($db, $reqId);
    if (is_null($node) || intval($node['node_type_id']) !== NODE_REQUIREMENT) {
        failOut(404, 'Unknown requirement', 'unknown_requirement');
    }
    if (owningProjectId($db, $reqId) !== $tprojectId) {
        failOut(404, 'Unknown requirement', 'unknown_requirement');
    }

    // The coverage answer carries TEST CASE names, human ids and versions, so
    // a requirement-only right is not enough: mgt_view_req does not imply
    // mgt_view_tc (the right groups are independent, roles.inc.php).
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)
        && !$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
        failOut(403, 'You do not have permission to read test cases',
            'no_right_tc_view');
    }

    // NB: 2.0.1 has NO `testcase` table - the test case NAME lives in
    // nodes_hierarchy and the human id (PREFIX-N) in tcversions.tc_external_id.
    $sql = "SELECT RC.tcversion_id, RC.testcase_id, RC.is_active, RC.link_status,"
        . " NH_TC.name, TCV.tc_external_id, TCV.version,"
        . " NV.version AS req_version"
        . " FROM {$T['req_coverage']} RC"
        . " JOIN {$T['nodes_hierarchy']} NH_TC ON NH_TC.id = RC.testcase_id"
        . " JOIN {$T['tcversions']} TCV ON TCV.id = RC.tcversion_id"
        . " LEFT JOIN {$T['req_versions']} NV ON NV.id = RC.req_version_id"
        . " WHERE RC.req_id = " . intval($reqId)
        . " ORDER BY RC.is_active DESC, RC.id DESC";
    $rs = $db->exec_query($sql);
    $rows = array();
    while ($row = $db->fetch_array($rs)) {
        $rows[] = array(
            'tcversion_id' => intval($row['tcversion_id']),
            'testcase_id' => intval($row['testcase_id']),
            'active' => intval($row['is_active']) === 1,
            'link_status' => intval($row['link_status']),
            'external_id' => is_null($row['tc_external_id']) ? null : intval($row['tc_external_id']),
            'name' => $row['name'],
            'version' => intval($row['version']),
            'req_version' => is_null($row['req_version']) ? 0 : intval($row['req_version']),
        );
    }

    $docRs = $db->exec_query("SELECT req_doc_id FROM {$T['requirements']} WHERE id = " . intval($reqId));
    $docRow = $db->fetch_array($docRs);

    out(array(
        'status' => 'ok',
        'action' => 'coverage',
        'req_id' => $reqId,
        'req_name' => $node['name'],
        'req_doc_id' => (is_null($docRow) || empty($docRow)) ? '' : $docRow['req_doc_id'],
        'rows' => $rows,
    ));
}

failOut(400, 'Unknown action', 'unknown_action');
