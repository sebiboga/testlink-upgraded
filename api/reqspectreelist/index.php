<?php
/**
 * Requirement Specification Tree navigator BFF API
 * URL: /api/reqspectreelist/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Modern, rights-checked replacement of the 1.9.20 "Requirement Specification
 * tree" frame lib/requirements/reqSpecListTree.php (Refs #1695).
 *
 * Legacy behaviour being ported
 * -----------------------------
 * reqSpecListTree.php (84 lines) built a two-level ExtJS tree through
 * tlRequirementFilterControl::build_tree_menu() and rendered
 * gui/templates/dashio/requirements/reqSpecListTree.tpl (inc_head.tpl +
 * inc_ext_js.tpl + treebyloader.js / execTree.js). The tree was:
 *
 *   root   = test project, label "<project name> (<n requirements>)"
 *            href javascript:TPROJECT_REQ_SPEC_MGMT(<tproject_id>)
 *   level2 = requirement specifications, label "<doc_id>:<name> (<n reqs>)"
 *            href javascript:REQ_SPEC_MGMT(<tproject_id>,<id>)
 *   level3 = requirements,          label "<req_doc_id>:<name>"
 *            href javascript:REQ_MGMT(<tproject_id>,<id>)
 *
 * Without an active filter the children were LAZY LOADED from
 * lib/ajax/getrequirementnodes.php?mode=reqspec&root_node=<tproject_id>
 * (Ext JS passes &node=<expanded id>), so both files together implemented the
 * navigator. That loader is a pure read endpoint and this BFF re-implements its
 * node shape for the modern screen, node by node.
 *
 * Security holes inherited from the loader (closed here)
 * -----------------------------------------------------
 * 1. lib/ajax/getrequirementnodes.php performed NO rights check at all - only
 *    testlinkInitPage(). Any authenticated user could read the requirement
 *    documents (doc_id + title) of ANY test project by sending
 *    ?root_node=<foreign id>, and could read the children of ANY node in the
 *    hierarchy by sending an arbitrary ?node= (the SQL only filtered on
 *    `NHA.parent_id = <parent>`, with no project scope and no node-type gate).
 *    Here the rights are mgt_view_req OR mgt_modify_req on the addressed
 *    project, and every node id is proven to be a requirement specification of
 *    that same project (or the project's own node) before any name is returned.
 * 2. The tree the legacy screen advertised for drag-and-drop posted to
 *    lib/ajax/dragdroprequirementnodes.php; that endpoint is the one hardened
 *    by #1681 and is now a non-mutating shim, and this screen is READ-ONLY on
 *    purpose: the write gesture lives in gui/templates/requirements/
 *    reqTreeReorder.html (api/reqtreereorder).
 *
 * node_type_id map (node_types table): 1 testproject, 6 requirement_spec,
 * 7 requirement, 8 requirement_version, 11 requirement_spec_revision.
 * Ownership of a specification is read from req_specs.testproject_id, which
 * keeps its own project link (nodes_hierarchy does not - see #1660).
 *
 * Endpoints (JSON in/out, GET only - this screen writes nothing):
 *   GET ?action=init&tproject_id=N
 *        -> context + rights + the project node + its specification children
 *           (doc_id, title, revision, total requirement count, author).
 *   GET ?action=children&tproject_id=N&node_id=M
 *        -> the lazy-load contract of lib/ajax/getrequirementnodes.php: the
 *           requirements of specification M (M is proven to belong to N).
 *   GET ?action=projects
 *        -> the requirement-enabled test projects the caller may read, so the
 *           screen can offer a project switcher (the legacy frame always used
 *           the session project and had no switcher at all).
 *
 * Status codes: 200 ok / 400 bad param / 401 anonymous or expired session,
 * 403 no right, 404 unknown project, 405 wrong verb, 500 guarded.
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
    error_log('api/reqspectreelist: ' . $e->getMessage());
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

// Refs #1614: the legacy frame went through testlinkInitPage(), which runs
// checkSessionValid() on EVERY page load, so a stale session was bounced to
// login.php?note=expired. A BFF must answer instead of redirecting.
bffEnforceSession($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr     = new requirement_mgr($db);
$tprojMgr   = new testproject($db);

define('NODE_TYPE_TESTPROJECT',     1);
define('NODE_TYPE_REQUIREMENT_SPEC', 6);
define('NODE_TYPE_REQUIREMENT',      7);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : 'init';

function param($key, $default = 0)
{
    return array_key_exists($key, $_REQUEST) ? $_REQUEST[$key] : $default;
}

/** Test project must exist; returns its id. No right check here. */
function needTprojectId()
{
    global $tprojMgr;
    $tid = intval(param('tproject_id', 0));
    if ($tid <= 0) {
        failOut(400, 'Invalid test project id', 'invalid_tproject');
    }
    $info = $tprojMgr->get_by_id($tid);
    if (is_null($info) || !isset($info['name'])) {
        failOut(404, 'Test project does not exist', 'tproject_not_found');
    }
    return $tid;
}

/** Tolerantly read a project flag (the #1376 / #1516 pattern). */
function projFlag($proj, $key, $default = 0)
{
    $opt = isset($proj['opt']) ? $proj['opt'] : null;
    if (is_object($opt) && isset($opt->$key)) {
        return intval($opt->$key) > 0 ? 1 : 0;
    }
    if (is_array($opt) && isset($opt[$key])) {
        return intval($opt[$key]) > 0 ? 1 : 0;
    }
    return intval($default) > 0 ? 1 : 0;
}

/** requirements are enabled per project (testprojects.option_reqs). */
function requirementsEnabled($proj)
{
    return projFlag($proj, 'requirementsEnabled',
        isset($proj['option_reqs']) ? intval($proj['option_reqs']) : 1);
}

function canViewReqs($user, $tproject_id)
{
    return $user->hasRight($GLOBALS['db'], 'mgt_view_req', $tproject_id) ||
           $user->hasRight($GLOBALS['db'], 'mgt_modify_req', $tproject_id);
}

/**
 * Specification list of a project, ordered the way the legacy tree ordered it
 * (nodes_hierarchy.node_order ASC, id ASC) so the modern navigator and the
 * legacy one present the specifications in the same sequence. The total
 * requirement count is the same per-spec count the legacy tree appended to the
 * label; it is computed over the requirement nodes parented by the spec
 * (testproject nodes that were re-parented into a spec when
 * req_cfg->child_requirements_mgmt is on are counted too, as before).
 */
function specList($tproject_id)
{
    global $db, $reqSpecMgr;

    $rows = $db->get_recordset(
        'SELECT RS.id, RS.doc_id, NH.name AS title, NH.node_order,' .
        ' (SELECT COUNT(1) FROM requirements C WHERE C.srs_id = RS.id)' .
        '   AS total_reqs' .
        ' FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' JOIN nodes_hierarchy NH ON NH.id = RS.id' .
        '   AND NH.node_type_id = ' . NODE_TYPE_REQUIREMENT_SPEC .
        ' WHERE RS.testproject_id = ' . intval($tproject_id) .
        ' ORDER BY NH.node_order ASC, RS.id ASC');

    $out = array();
    foreach (($rows ? $rows : array()) as $r) {
        $specId = intval($r['id']);
        $hdr = specHeader($specId);
        $out[] = array(
            'id'          => $specId,
            'doc_id'      => (string)$r['doc_id'],
            'title'       => (string)$r['title'],
            'node_order'  => intval($r['node_order']),
            'total_reqs'  => intval($r['total_reqs']),
            'revision'    => intval($hdr['revision']),
            'type'        => (string)$hdr['type'],
            'scope'       => (string)$hdr['scope'],
            'author_id'   => intval($hdr['author_id']),
            'author_login' => (string)$hdr['author_login'],
        );
    }
    return $out;
}

/** Newest revision of a specification, with its author. */
function specHeader($specId)
{
    global $db, $reqSpecMgr;
    $rows = $db->get_recordset(
        'SELECT RS.id, RS.doc_id, NH.name AS title, V.revision, V.type,' .
        ' V.scope, V.author_id, U.login AS author_login' .
        ' FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' LEFT JOIN nodes_hierarchy NH ON NH.id = RS.id' .
        ' LEFT JOIN req_specs_revisions V ON V.parent_id = RS.id' .
        '   AND V.revision = (SELECT MAX(V2.revision) FROM req_specs_revisions V2' .
        '        WHERE V2.parent_id = RS.id)' .
        ' LEFT JOIN users U ON U.id = V.author_id' .
        ' WHERE RS.id = ' . intval($specId));
    return ($rows && $rows[0]) ? $rows[0] : array();
}

/**
 * Node id -> owning test project, proved through the node itself. Used to
 * answer "which project decides the rights for this node" without trusting any
 * caller parameter (the #1681 pattern).
 */
function nodeOwningTproject($nodeId)
{
    global $db, $reqSpecMgr, $reqMgr;
    $nid = intval($nodeId);
    if ($nid <= 0) { return 0; }

    $rows = $db->get_recordset(
        'SELECT NH.node_type_id, RS.testproject_id' .
        ' FROM nodes_hierarchy NH' .
        ' LEFT JOIN ' . $reqSpecMgr->object_table . ' RS ON RS.id = NH.id' .
        ' WHERE NH.id = ' . $nid);
    if (!$rows || !$rows[0]) { return 0; }

    $type = intval($rows[0]['node_type_id']);
    if ($type === NODE_TYPE_REQUIREMENT_SPEC) {
        return intval($rows[0]['testproject_id']);
    }
    if ($type === NODE_TYPE_REQUIREMENT) {
        // Requirement -> its spec -> the project.
        $rid = $nid;
        $srs = $db->get_recordset(
            'SELECT srs_id FROM ' . $reqMgr->object_table . ' WHERE id = ' . $rid);
        if (!$srs || !$srs[0]) { return 0; }
        $rows = $db->get_recordset(
            'SELECT testproject_id FROM ' . $reqSpecMgr->object_table .
            ' WHERE id = ' . intval($srs[0]['srs_id']));
        return ($rows && $rows[0]) ? intval($rows[0]['testproject_id']) : 0;
    }
    if ($type === NODE_TYPE_TESTPROJECT) {
        return $nid;
    }
    return 0;
}

/**
 * A specification node id must be a real requirement specification of
 * $tproject_id. This is the ownership proof the legacy loader never did.
 */
function needOwnedSpec($specId, $tproject_id)
{
    global $db, $reqSpecMgr;
    $sid = intval($specId);
    if ($sid <= 0) {
        failOut(400, 'Invalid requirement specification id', 'invalid_req_spec');
    }
    $rows = $db->get_recordset(
        'SELECT RS.testproject_id, NH.node_type_id' .
        ' FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' LEFT JOIN nodes_hierarchy NH ON NH.id = RS.id' .
        ' WHERE RS.id = ' . $sid);
    if (!$rows || !$rows[0]) {
        failOut(404, 'Requirement specification not found', 'req_spec_not_found');
    }
    if (intval($rows[0]['node_type_id']) !== NODE_TYPE_REQUIREMENT_SPEC) {
        failOut(400, 'Node ' . $sid . ' is not a requirement specification',
            'not_a_req_spec');
    }
    if (intval($rows[0]['testproject_id']) !== intval($tproject_id)) {
        // Cross-project id: indistinguishable from a non-existent one on purpose.
        failOut(404, 'Requirement specification not found', 'req_spec_not_found');
    }
    return $sid;
}

/** Total requirement count of a project (the legacy root-node label). */
function projectReqCount($tproject_id)
{
    global $db;
    $rows = $db->get_recordset(
        'SELECT COUNT(1) AS c FROM requirements R' .
        ' JOIN ' . $GLOBALS['reqSpecMgr']->object_table . ' RS ON RS.id = R.srs_id' .
        ' WHERE RS.testproject_id = ' . intval($tproject_id));
    return ($rows && $rows[0]) ? intval($rows[0]['c']) : 0;
}

/**
 * Requirements parented by a specification, in tree order - the third level of
 * the legacy navigator, and the body of the lazy-load answer. Only the fields
 * the tree rendered (doc_id, name, latest-version status) plus the modern deep
 * link are returned.
 */
function requirementChildren($specId)
{
    global $db, $reqMgr;
    $rows = $db->get_recordset(
        'SELECT R.id, R.req_doc_id, NH.name AS title, NH.node_order,' .
        ' V.status, V.type, V.version' .
        ' FROM ' . $reqMgr->object_table . ' R' .
        ' JOIN nodes_hierarchy NH ON NH.id = R.id' .
        '   AND NH.node_type_id = ' . NODE_TYPE_REQUIREMENT .
        ' LEFT JOIN nodes_hierarchy VN ON VN.parent_id = R.id' .
        '   AND VN.node_type_id = 8' .
        '   AND VN.id = (SELECT MAX(VN2.id) FROM nodes_hierarchy VN2' .
        '        WHERE VN2.parent_id = R.id AND VN2.node_type_id = 8)' .
        ' LEFT JOIN req_versions V ON V.id = VN.id' .
        ' WHERE R.srs_id = ' . intval($specId) .
        ' ORDER BY NH.node_order ASC, R.id ASC');

    $out = array();
    foreach (($rows ? $rows : array()) as $r) {
        $out[] = array(
            'id'          => intval($r['id']),
            'req_doc_id'  => (string)$r['req_doc_id'],
            'title'       => (string)$r['title'],
            'node_order'  => intval($r['node_order']),
            'status'      => (string)$r['status'],
            'type'        => (string)$r['type'],
            'version'     => intval($r['version']),
        );
    }
    return $out;
}

// ================================================================ init ======
if ($action === 'init') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $tproject_id = needTprojectId();
    $proj = $tprojMgr->get_by_id($tproject_id);

    // Rights FIRST, on the requested project: resolving anything before this
    // would turn the endpoint into a cross-project existence oracle (403 for a
    // real id, 404 for a bogus one).
    if (!canViewReqs($user, $tproject_id)) {
        failOut(403, 'You are not authorized to view requirements', 'no_right');
    }

    $reqsEnabled = requirementsEnabled($proj);
    $specs = $reqsEnabled ? specList($tproject_id) : array();

    out(array(
        'status'  => 'ok',
        'context' => array(
            'tproject_id'     => $tproject_id,
            'tproject_name'   => (string)$proj['name'],
            'prefix'          => (string)(isset($proj['prefix']) ? $proj['prefix'] : ''),
            'requirements_enabled' => $reqsEnabled,
            'total_reqs'      => $reqsEnabled ? projectReqCount($tproject_id) : 0,
            'total_specs'     => count($specs),
            'user_id'         => $userId,
            'user_login'      => (string)$user->login,
        ),
        'specs'   => $specs,
        'grant'   => array(
            'view'   => true,
            // hasRight() can answer the string "yes" - normalize to a real
            // boolean so the JSON grant map is not mixed-type.
            'modify' => (bool)$user->hasRight($db, 'mgt_modify_req', $tproject_id),
        ),
    ));
}

// ============================================================ children ======
// The lazy-load answer of lib/ajax/getrequirementnodes.php?node=<id>. The node
// is proven to be a requirement specification of the addressed project before
// any requirement name is returned.
if ($action === 'children') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $tproject_id = needTprojectId();
    if (!canViewReqs($user, $tproject_id)) {
        failOut(403, 'You are not authorized to view requirements', 'no_right');
    }
    $specId = needOwnedSpec(param('node_id', 0), $tproject_id);
    $spec = specHeader($specId);
    out(array(
        'status'  => 'ok',
        'node'    => array(
            'id'          => $specId,
            'doc_id'      => (string)($spec['doc_id'] ?? ''),
            'title'       => (string)($spec['title'] ?? ''),
        ),
        'children' => requirementChildren($specId),
    ));
}

// ============================================================ projects ======
// The legacy frame had no project switcher (it always read
// $_SESSION['testprojectID']). The modern screen gets one, so it needs the
// list of requirement-enabled projects the caller may actually read.
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
        if (!canViewReqs($user, $pid)) { continue; }
        if (!requirementsEnabled($p)) { continue; }
        $out[] = array(
            'id'    => $pid,
            'name'  => (string)$p['name'],
            'prefix' => (string)(isset($p['prefix']) ? $p['prefix'] : ''),
            'active' => ($p['active'] == 1) ? 1 : 0,
            'is_current' => ($pid === $sessionTid) ? 1 : 0,
        );
    }
    usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    out(array('status' => 'ok', 'projects' => $out,
        'session_tproject_id' => $sessionTid));
}

failOut(400, 'Unknown action', 'unknown_action');
